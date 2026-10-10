<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\BillingTrial;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Tests\Support\StripeHttpStub;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Billable;

/**
 * What Stripe actually RECEIVES when a trial checkout opens, driven through the
 * controller and Cashier's real `SubscriptionBuilder::checkout()` with only the
 * HTTP transport stubbed ({@see StripeHttpStub}).
 *
 * The builder fakes in {@see BillingWriteEndpointsTest} certify what the
 * controller asked the builder for. They cannot certify that Cashier turns
 * `trialDays()` into `subscription_data[trial_end]`, that the trial user tag
 * survives Cashier merging in its own `name` and `type`, or that the card
 * collection option survives `array_merge_recursive` with the builder's
 * payload. The webhook that records the trial reads those exact fields, so
 * this is where they are pinned.
 */
class TrialCheckoutPayloadTest extends TestCase
{
    private const SUCCESS_URL = 'https://app.example.test/billing/success';

    private const CANCEL_URL = 'https://app.example.test/billing/cancel';

    private StripeHttpStub $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        config([
            'cashier.secret' => 'sk_test_trial_payload',
            'magic-starter.use_uuids' => true,
            'magic-starter.models.user' => TrialPayloadUser::class,
            'magic-starter.route_prefix' => '',
            'magic-starter.billing.tier_order' => ['free', 'pro'],
            'magic-starter.billing.tiers' => [
                'free' => ['name' => 'Free'],
                'pro' => ['name' => 'Pro'],
            ],
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'trial_days' => 14,
                    'refs' => ['stripe_price' => 'price_pro'],
                ],
            ],
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing.billable' => 'user',
            'auth.providers.users' => [
                'driver' => 'eloquent',
                'model' => TrialPayloadUser::class,
            ],
            // The harness shim the other billing tests carry: Sanctum's token
            // driver is not registered in a Testbench skeleton.
            'auth.guards.sanctum' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
        ]);

        $schema = app('db.schema');

        $schema->create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('stripe_id')->nullable();
            $table->string('plan_provider')->nullable();
            $table->timestamps();
        });

        $schema->create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('user_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../../database/migrations/create_billing_trials_table.php',
            '--realpath' => true,
        ]);

        $this->app['router']->setRoutes(new RouteCollection);
        $this->app->forgetInstance(GateContract::class);
        Gate::clearResolvedInstance(GateContract::class);

        (new MagicStarterServiceProvider($this->app))->boot();

        $this->stripe = StripeHttpStub::install();
    }

    protected function tearDown(): void
    {
        StripeHttpStub::uninstall();
        MagicStarter::reset();

        parent::tearDown();
    }

    /**
     * An eligible caller's session carries the trial end, the trial user tag
     * beside Cashier's own `type`, and the instruction to collect a card even
     * though the first invoice is zero.
     */
    public function test_an_eligible_checkout_sends_the_trial_and_the_trial_user_to_stripe(): void
    {
        $this->freezeTime();

        $user = $this->createUser('payload-eligible@example.test');

        $this->buy($user)
            ->assertOk()
            ->assertJsonPath('session_id', 'cs_test_trial')
            ->assertJsonPath('checkout_url', 'https://checkout.stripe.test/cs_test_trial');

        // A billable with no Stripe customer gets one first.
        $this->assertCount(1, $this->stripe->requestsTo('post', '/v1/customers'));

        $params = $this->sessionParams();

        $this->assertSame('subscription', $params['mode']);
        $this->assertSame('price_pro', $params['line_items'][0]['price']);
        $this->assertSame('cus_trial_payload', $params['customer']);
        $this->assertSame(now()->addDays(14)->getTimestamp(), $params['subscription_data']['trial_end']);
        $this->assertSame(
            (string) $user->getKey(),
            $params['subscription_data']['metadata']['magic_starter_trial_user'],
        );
        $this->assertSame('default', $params['subscription_data']['metadata']['type']);
        $this->assertSame('always', $params['payment_method_collection']);
        $this->assertSame(self::SUCCESS_URL, $params['success_url']);
    }

    /**
     * A caller who already trialed buys at the full price: no trial end and no
     * trial user tag, so the webhook records no trial, while the card is still
     * collected and the subscription still carries Cashier's `type`.
     */
    public function test_an_ineligible_checkout_sends_no_trial_to_stripe(): void
    {
        $user = $this->createUser('payload-trialed@example.test');

        BillingTrial::query()->create([
            'user_id' => $user->getKey(),
            'billable_type' => $user->getMorphClass(),
            'billable_id' => $user->getKey(),
            'stripe_subscription_id' => 'sub_earlier_trial',
            'subscription_created_at' => now()->subYear(),
        ]);

        $this->buy($user)->assertOk();

        $params = $this->sessionParams();

        $this->assertArrayNotHasKey('trial_end', $params['subscription_data']);
        $this->assertArrayNotHasKey('magic_starter_trial_user', $params['subscription_data']['metadata']);
        $this->assertSame('default', $params['subscription_data']['metadata']['type']);
        $this->assertSame('always', $params['payment_method_collection']);
    }

    /**
     * Queue Stripe's two answers and open a checkout as [$user].
     */
    private function buy(TrialPayloadUser $user): TestResponse
    {
        $this->stripe
            ->answer([
                'id' => 'cus_trial_payload',
                'object' => 'customer',
            ])
            ->answer([
                'id' => 'cs_test_trial',
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.test/cs_test_trial',
            ]);

        return $this->actingAs($user->fresh(), 'sanctum')->postJson('/billing/checkout', [
            'product' => 'pro_monthly',
            'success_url' => self::SUCCESS_URL,
            'cancel_url' => self::CANCEL_URL,
        ]);
    }

    /**
     * The parameters of the one Checkout Session the request opened.
     *
     * @return array<string, mixed>
     */
    private function sessionParams(): array
    {
        $sessions = $this->stripe->requestsTo('post', '/v1/checkout/sessions');

        $this->assertCount(1, $sessions);

        return $sessions[0]['params'];
    }

    private function createUser(string $email): TrialPayloadUser
    {
        return TrialPayloadUser::query()->create([
            'name' => 'Trial Payload',
            'email' => $email,
        ]);
    }
}

/**
 * A user carrying Cashier's REAL billable trait, so the checkout runs through
 * Cashier's own builder. The foreign key is pinned to `user_id` because Cashier
 * derives it from the class basename.
 */
class TrialPayloadUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use Authorizable;
    use Billable;
    use ConditionallyUsesUuids;

    protected $table = 'users';

    protected $guarded = [];

    public function getForeignKey(): string
    {
        return 'user_id';
    }
}
