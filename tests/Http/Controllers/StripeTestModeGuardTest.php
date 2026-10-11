<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\BillingController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Tests\Support\StripeHttpStub;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasGuestSupport;
use FlutterSdk\MagicStarter\Traits\MustVerifyEmail;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Billable;

/**
 * The Stripe test-mode guard on checkout and swap, driven through the routes
 * and Cashier's real builders with only the Stripe transport stubbed
 * ({@see StripeHttpStub}).
 *
 * The claim: on a box running a Stripe TEST key outside local development, a
 * purchase answers only for an allowlisted, verified, non-guest person, and
 * the refusal reaches Stripe for nothing. A refused checkout that still
 * created a Stripe customer would leave a `stripe_id` behind, so every refusal
 * here also asserts the transport saw no request at all.
 *
 * Each refusal has an accepting twin (a live key, a local environment, an
 * allowlisted user), because a guard that refuses everybody passes every
 * refusal test on its own.
 */
class StripeTestModeGuardTest extends TestCase
{
    private const SUCCESS_URL = 'https://app.example.test/billing/success';

    private const CANCEL_URL = 'https://app.example.test/billing/cancel';

    /**
     * The env key the config file reads the allowlist from.
     */
    private const ALLOWLIST_ENV = 'MAGIC_STARTER_BILLING_STRIPE_TEST_EMAILS';

    private StripeHttpStub $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        config([
            'cashier.secret' => 'sk_test_x',
            'magic-starter.use_uuids' => true,
            'magic-starter.models.user' => StripeTestModeUser::class,
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
                    'prices' => ['web' => ['USD' => 2900]],
                    'refs' => ['stripe_price' => 'price_pro'],
                ],
            ],
            'magic-starter.billing.stripe_test_mode.emails' => [],
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing.billable' => 'user',
            'auth.providers.users' => [
                'driver' => 'eloquent',
                'model' => StripeTestModeUser::class,
            ],
            // The harness shim the other billing tests carry: Sanctum's token
            // driver is not registered in a Testbench skeleton.
            'auth.guards.sanctum' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
        ]);

        $schema = app('db.schema');

        // `email` and `email_verified_at` are nullable because a guest has
        // neither, which is one of the cases under test.
        $schema->create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('is_guest')->default(false);
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

        // The audit trail every refusal reaches, from the shipped migration.
        (require __DIR__ . '/../../../database/migrations/create_billing_events_table.php')->up();

        $this->app['router']->setRoutes(new RouteCollection);
        $this->app->forgetInstance(GateContract::class);
        Gate::clearResolvedInstance(GateContract::class);

        (new MagicStarterServiceProvider($this->app))->boot();

        $this->stripe = StripeHttpStub::install();

        $this->runningIn('production');
    }

    protected function tearDown(): void
    {
        StripeHttpStub::uninstall();
        MagicStarter::reset();
        putenv(self::ALLOWLIST_ENV);

        parent::tearDown();
    }

    /**
     * (a) A verified user nobody allowlisted is refused at checkout with the
     * reason and the rail, the refusal is recorded, and Stripe is never asked
     * for a customer or a session.
     */
    public function test_a_checkout_on_a_test_key_refuses_a_user_who_is_not_allowlisted(): void
    {
        $user = $this->createUser('stranger@example.test');

        $this->checkout($user)
            ->assertStatus(409)
            ->assertJsonPath('message', __('magic-starter::billing.refusals.stripe_test_mode'))
            ->assertJsonPath('billing.reason', BillingController::REASON_STRIPE_TEST_MODE)
            ->assertJsonPath('billing.provider', 'stripe');

        $refusals = BillingEvent::query()->where('type', BillingEventType::REQUEST_REFUSED)->get();

        $this->assertCount(1, $refusals);
        $this->assertSame(BillingController::REASON_STRIPE_TEST_MODE, $refusals->sole()->reason);

        $this->assertSame([], $this->stripe->requestsTo('post', '/v1/customers'));
        $this->assertSame([], $this->stripe->requestsTo('post', '/v1/checkout/sessions'));
        $this->assertSame([], $this->stripe->requests);
        $this->assertNull($user->fresh()?->stripe_id);
    }

    /**
     * (b) The same refusal for a swap, before the subscription is read.
     */
    public function test_a_swap_on_a_test_key_refuses_a_user_who_is_not_allowlisted(): void
    {
        $user = $this->createUser('stranger-swap@example.test');

        $this->actingAs($user->fresh(), 'sanctum')
            ->postJson('/billing/swap', [
                'product' => 'pro_monthly',
            ])
            ->assertStatus(409)
            ->assertJsonPath('billing.reason', BillingController::REASON_STRIPE_TEST_MODE)
            ->assertJsonPath('billing.provider', 'stripe');

        $this->assertSame(1, $this->recordedTestModeRefusals());
        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * (c) An allowlisted user reaches Stripe, whichever side of the comparison
     * carries the capitals: the list is lower-cased when the config file reads
     * it, and the stored email is lower-cased when the guard compares it.
     */
    public function test_an_allowlisted_user_reaches_stripe_whatever_the_case_of_either_side(): void
    {
        // The list in mixed case, the stored email lower case.
        $this->allowlistFromEnv(' Owner@Example.TEST , other@example.test');

        $lower = $this->createUser('owner@example.test');

        $this->buy($lower)->assertOk()->assertJsonPath('session_id', 'cs_test_guard');
        $this->assertCount(1, $this->stripe->requestsTo('post', '/v1/checkout/sessions'));

        // The list in lower case, the stored email in mixed case.
        $this->allowlistFromEnv('mixed@example.test');

        $mixed = $this->createUser('Mixed@Example.Test');

        $this->buy($mixed)->assertOk();
        $this->assertCount(2, $this->stripe->requestsTo('post', '/v1/checkout/sessions'));
    }

    /**
     * (d) A restricted test key is a test key too.
     */
    public function test_a_restricted_test_key_is_guarded_like_a_secret_test_key(): void
    {
        config([
            'cashier.secret' => 'rk_test_x',
        ]);

        $user = $this->createUser('restricted@example.test');

        $this->checkout($user)
            ->assertStatus(409)
            ->assertJsonPath('billing.reason', BillingController::REASON_STRIPE_TEST_MODE);

        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * (e) A live key turns the guard off: anybody may buy.
     */
    public function test_a_live_key_lets_a_user_who_is_not_allowlisted_buy(): void
    {
        config([
            'cashier.secret' => 'sk_live_x',
        ]);

        $user = $this->createUser('customer@example.test');

        $this->buy($user)->assertOk();
        $this->assertCount(1, $this->stripe->requestsTo('post', '/v1/checkout/sessions'));
    }

    /**
     * (f) Local development on a test key is the normal way to work, so it is
     * not guarded.
     */
    public function test_the_local_environment_on_a_test_key_is_not_guarded(): void
    {
        $this->runningIn('local');

        $this->buy($this->createUser('local@example.test'))->assertOk();
        $this->assertCount(1, $this->stripe->requestsTo('post', '/v1/checkout/sessions'));
    }

    /**
     * (f) The test suite's own environment is not guarded either.
     */
    public function test_the_testing_environment_on_a_test_key_is_not_guarded(): void
    {
        $this->runningIn('testing');

        $this->buy($this->createUser('testing@example.test'))->assertOk();
        $this->assertCount(1, $this->stripe->requestsTo('post', '/v1/checkout/sessions'));
    }

    /**
     * (g) Any environment that is not explicitly local development is guarded,
     * so a box named `staging` (or a typo of `production`) fails closed.
     */
    public function test_a_staging_environment_on_a_test_key_fails_closed(): void
    {
        $this->runningIn('staging');

        $this->checkout($this->createUser('staging@example.test'))
            ->assertStatus(409)
            ->assertJsonPath('billing.reason', BillingController::REASON_STRIPE_TEST_MODE);

        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * (h) An allowlisted address nobody has verified proves nothing about who
     * holds it, so it is refused.
     */
    public function test_an_allowlisted_but_unverified_email_is_refused(): void
    {
        $this->allowlist('unverified@example.test');

        $user = $this->createUser('unverified@example.test', [
            'email_verified_at' => null,
        ]);

        $this->checkout($user)
            ->assertStatus(409)
            ->assertJsonPath('billing.reason', BillingController::REASON_STRIPE_TEST_MODE);

        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * (i) A sandbox subscriber must always be able to leave, so cancel and the
     * portal are never refused for test mode.
     */
    public function test_cancel_and_portal_are_not_guarded(): void
    {
        $user = $this->createUser('leaving@example.test', [
            'stripe_id' => 'cus_leaving',
        ]);

        $this->stripe->answer([
            'id' => 'bps_test_guard',
            'object' => 'billing_portal.session',
            'url' => 'https://billing.stripe.test/bps_test_guard',
        ]);

        $this->actingAs($user->fresh(), 'sanctum')
            ->getJson('/billing/portal?return_url=' . urlencode('https://app.example.test/billing'))
            ->assertOk()
            ->assertJsonPath('portal_url', 'https://billing.stripe.test/bps_test_guard');

        // No subscription to cancel is an honest 404, which is the point: the
        // request got past where a test-mode refusal would have stopped it.
        $cancel = $this->actingAs($user->fresh(), 'sanctum')->postJson('/billing/cancel');

        $cancel->assertNotFound();
        $this->assertNotSame(BillingController::REASON_STRIPE_TEST_MODE, $cancel->json('billing.reason'));

        $this->assertSame(0, $this->recordedTestModeRefusals());
    }

    /**
     * (j) The refusal names nobody on the allowlist, in the body or in the
     * recorded row.
     */
    public function test_the_refusal_never_carries_an_allowlisted_email(): void
    {
        $this->allowlist('secret-tester@example.test');

        $response = $this->checkout($this->createUser('outsider@example.test'))->assertStatus(409);

        $this->assertStringNotContainsString('secret-tester', (string) $response->getContent());
        $this->assertStringNotContainsString(
            'secret-tester',
            (string) json_encode(BillingEvent::query()->sole()->toArray()),
        );
    }

    /**
     * (k) A guest is refused, with no email and even with an allowlisted one:
     * the package's `hasVerifiedEmail()` answers yes for every guest, so the
     * verification check alone would let an allowlisted guest through.
     */
    public function test_a_guest_is_refused(): void
    {
        $guest = $this->createUser(null, [
            'is_guest' => true,
            'email_verified_at' => null,
        ]);

        $this->checkout($guest)
            ->assertStatus(409)
            ->assertJsonPath('billing.reason', BillingController::REASON_STRIPE_TEST_MODE);

        $this->allowlist('guest@example.test');

        $allowlistedGuest = $this->createUser('guest@example.test', [
            'is_guest' => true,
            'email_verified_at' => null,
        ]);

        $this->assertTrue($allowlistedGuest->hasVerifiedEmail());

        $this->checkout($allowlistedGuest)
            ->assertStatus(409)
            ->assertJsonPath('billing.reason', BillingController::REASON_STRIPE_TEST_MODE);

        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * How many refusals were recorded with the test-mode reason.
     */
    private function recordedTestModeRefusals(): int
    {
        return BillingEvent::query()
            ->where('reason', BillingController::REASON_STRIPE_TEST_MODE)
            ->count();
    }

    /**
     * Pretend the application booted in [$environment].
     */
    private function runningIn(string $environment): void
    {
        $this->app->detectEnvironment(fn (): string => $environment);
    }

    /**
     * Put [$emails] on the allowlist as the config file would already hold them.
     */
    private function allowlist(string ...$emails): void
    {
        config([
            'magic-starter.billing.stripe_test_mode.emails' => $emails,
        ]);
    }

    /**
     * Put a raw env value through the shipped config file's own parse, so the
     * normalisation under test is the shipped one.
     */
    private function allowlistFromEnv(string $value): void
    {
        putenv(self::ALLOWLIST_ENV . '=' . $value);

        $shipped = require __DIR__ . '/../../../config/magic-starter.php';

        config([
            'magic-starter.billing.stripe_test_mode.emails' => $shipped['billing']['stripe_test_mode']['emails'],
        ]);
    }

    /**
     * Queue Stripe's two answers and open a checkout as [$user].
     */
    private function buy(StripeTestModeUser $user): TestResponse
    {
        $this->stripe
            ->answer([
                'id' => 'cus_test_guard',
                'object' => 'customer',
            ])
            ->answer([
                'id' => 'cs_test_guard',
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.test/cs_test_guard',
            ]);

        return $this->checkout($user);
    }

    /**
     * Open a checkout as [$user] with nothing queued on the Stripe side.
     */
    private function checkout(StripeTestModeUser $user): TestResponse
    {
        return $this->actingAs($user->fresh(), 'sanctum')->postJson('/billing/checkout', [
            'product' => 'pro_monthly',
            'success_url' => self::SUCCESS_URL,
            'cancel_url' => self::CANCEL_URL,
        ]);
    }

    /**
     * A verified user unless [$attributes] says otherwise.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createUser(?string $email, array $attributes = []): StripeTestModeUser
    {
        return StripeTestModeUser::query()->create([
            'name' => 'Stripe Test Mode',
            'email' => $email,
            'email_verified_at' => now(),
            ...$attributes,
        ]);
    }
}

/**
 * A user carrying Cashier's REAL billable trait, the package's guest support
 * and the package's email verification trait, so the guard reads the same
 * `isGuest()` and `hasVerifiedEmail()` a published User model answers.
 */
class StripeTestModeUser extends Model implements AuthenticatableContract, MustVerifyEmailContract
{
    use AuthenticatableTrait;
    use Authorizable;
    use Billable;
    use ConditionallyUsesUuids;
    use HasGuestSupport;
    use MustVerifyEmail;
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_guest' => 'boolean',
    ];

    public function getForeignKey(): string
    {
        return 'user_id';
    }
}
