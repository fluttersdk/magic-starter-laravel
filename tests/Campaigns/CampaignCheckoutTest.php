<?php

namespace FlutterSdk\MagicStarter\Tests\Campaigns;

use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\Events\Billing\BillingOutcome;
use FlutterSdk\MagicStarter\Events\Billing\CampaignCodeRefused;
use FlutterSdk\MagicStarter\Events\Billing\CheckoutStarted;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Models\Campaign;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Billable;

/**
 * What Stripe RECEIVES when a checkout opens during a campaign, driven through
 * the controller and Cashier's real subscription builder with only the HTTP
 * transport stubbed ({@see StripeHttpStub}), and the billing rows it leaves.
 *
 * Probe facts these pin: Stripe refuses `discounts` beside any
 * `allow_promotion_codes` (false included), so a campaign session carries no
 * such key; a session may expire no sooner than 30 minutes and no later than
 * 24 hours ahead; and a code Stripe refuses at session creation must never
 * block the purchase, so the session is opened again without it.
 */
class CampaignCheckoutTest extends TestCase
{
    private const SUCCESS_URL = 'https://app.example.test/billing/success';

    private const CANCEL_URL = 'https://app.example.test/billing/cancel';

    private const NOW = '2026-11-03 12:00:00';

    private StripeHttpStub $stripe;

    /**
     * Every outcome event a listener on {@see BillingOutcome} received.
     *
     * @var list<BillingOutcome>
     */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        $this->dispatched = [];

        Event::listen(BillingOutcome::class, function (BillingOutcome $event): void {
            $this->dispatched[] = $event;
        });

        config([
            'cashier.secret' => 'sk_test_campaign_checkout',
            'magic-starter.use_uuids' => true,
            'magic-starter.models.user' => CampaignCheckoutUser::class,
            'magic-starter.route_prefix' => '',
            'magic-starter.billing.tier_order' => [
                'free',
                'pro',
            ],
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
                    'refs' => ['stripe_price' => 'price_pro_monthly'],
                ],
                'pro_annual' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'annual',
                    'prices' => ['web' => ['USD' => 29000]],
                    'refs' => ['stripe_price' => 'price_pro_annual'],
                ],
            ],
            'auth.providers.users' => [
                'driver' => 'eloquent',
                'model' => CampaignCheckoutUser::class,
            ],
            // The harness shim the other billing tests carry: Sanctum's token
            // driver is not registered in a Testbench skeleton.
            'auth.guards.sanctum' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
        ]);

        $this->createSchema();
        $this->bootRoutes(campaigns: true);

        Carbon::setTestNow(Carbon::parse(self::NOW));

        $this->stripe = StripeHttpStub::install();
    }

    protected function tearDown(): void
    {
        StripeHttpStub::uninstall();
        Carbon::setTestNow();
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_a_campaign_product_bought_by_a_free_owner_carries_the_campaign_code(): void
    {
        // On purpose: the flag must not reach a campaign session, since Stripe
        // refuses `discounts` beside any `allow_promotion_codes`.
        config(['magic-starter.billing.promotion_codes' => true]);

        $campaign = $this->createCampaign(['ends_at' => Carbon::parse('2026-11-04 00:00:00')]);
        $user = $this->createUser('campaign-buyer@example.test');

        $this->buy($user, 'pro_monthly')->assertOk()->assertJsonPath('session_id', 'cs_test_campaign');

        $params = $this->onlySessionParams();

        $this->assertSame([['promotion_code' => 'promo_campaign']], $params['discounts']);
        $this->assertArrayNotHasKey('allow_promotion_codes', $params);
        $this->assertSame('campaign:' . $campaign->getKey(), $params['client_reference_id']);
        $this->assertSame(Carbon::parse('2026-11-04 00:00:00')->getTimestamp(), $params['expires_at']);

        $row = $this->onlyRow(BillingEventType::CHECKOUT_STARTED, $user);
        $this->assertSame('cs_test_campaign', $row->external_id);
        $this->assertSame(
            [
                'product' => 'pro_monthly',
                'price_id' => 'price_pro_monthly',
                'trial_days' => 0,
                'promotion_codes' => false,
                'campaign_id' => (string) $campaign->getKey(),
            ],
            $row->properties,
        );
    }

    /**
     * One minute inside Stripe's 30-minute floor and five inside its 24-hour
     * ceiling, so a server clock slightly ahead of Stripe's never crosses either.
     */
    public function test_a_campaign_ending_within_half_an_hour_expires_the_session_just_past_half_an_hour(): void
    {
        $this->createCampaign(['ends_at' => Carbon::parse(self::NOW)->addMinutes(10)]);
        $user = $this->createUser('campaign-floor@example.test');

        $this->buy($user, 'pro_monthly')->assertOk();

        $this->assertSame(
            Carbon::parse(self::NOW)->addMinutes(31)->getTimestamp(),
            $this->onlySessionParams()['expires_at'],
        );
    }

    public function test_a_campaign_ending_after_a_day_expires_the_session_just_short_of_a_day(): void
    {
        $this->createCampaign(['ends_at' => Carbon::parse(self::NOW)->addDays(5)]);
        $user = $this->createUser('campaign-ceiling@example.test');

        $this->buy($user, 'pro_monthly')->assertOk();

        $this->assertSame(
            Carbon::parse(self::NOW)->addHours(24)->subMinutes(5)->getTimestamp(),
            $this->onlySessionParams()['expires_at'],
        );
    }

    public function test_a_product_outside_the_campaign_checks_out_as_before(): void
    {
        config(['magic-starter.billing.promotion_codes' => true]);

        $this->createCampaign(['products' => ['pro_annual']]);
        $user = $this->createUser('campaign-other-product@example.test');

        $this->buy($user, 'pro_monthly')->assertOk();

        $this->assertSessionWithoutCampaign($this->onlySessionParams(), promotionCodes: true);
        $this->assertArrayNotHasKey(
            'campaign_id',
            $this->onlyRow(BillingEventType::CHECKOUT_STARTED, $user)->properties,
        );
    }

    public function test_a_subject_holding_a_paid_tier_gets_no_campaign(): void
    {
        $this->createCampaign();
        $user = $this->createUser('campaign-comped@example.test', [
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'manual',
        ]);

        $this->buy($user, 'pro_monthly')->assertOk();

        $this->assertSessionWithoutCampaign($this->onlySessionParams(), promotionCodes: false);
    }

    public function test_no_campaign_applies_outside_its_window_while_disabled_or_with_the_feature_off(): void
    {
        // A fresh buyer per limb: a returning customer's reads would take the
        // answers queued for the session.
        $campaign = $this->createCampaign(['starts_at' => Carbon::parse(self::NOW)->addMinute()]);

        $this->buy($this->createUser('campaign-not-yet@example.test'), 'pro_monthly')->assertOk();
        $this->assertSessionWithoutCampaign($this->lastSessionParams(), promotionCodes: false);

        $campaign->forceFill([
            'starts_at' => Carbon::parse('2026-11-01 00:00:00'),
            'enabled' => false,
        ])->save();

        $this->buy($this->createUser('campaign-disabled@example.test'), 'pro_monthly')->assertOk();
        $this->assertSessionWithoutCampaign($this->lastSessionParams(), promotionCodes: false);

        $campaign->forceFill(['enabled' => true])->save();
        $this->bootRoutes(campaigns: false);

        $this->buy($this->createUser('campaign-feature-off@example.test'), 'pro_monthly')->assertOk();
        $this->assertSessionWithoutCampaign($this->lastSessionParams(), promotionCodes: false);

        // The control limb: the same campaign, enabled and in its window, applies.
        $this->bootRoutes(campaigns: true);

        $this->buy($this->createUser('campaign-control@example.test'), 'pro_monthly')->assertOk();
        $this->assertSame([['promotion_code' => 'promo_campaign']], $this->lastSessionParams()['discounts']);
    }

    public function test_a_refused_campaign_code_is_recorded_and_the_session_opens_without_it(): void
    {
        config(['magic-starter.billing.promotion_codes' => true]);

        $campaign = $this->createCampaign();
        $user = $this->createUser('campaign-refused@example.test');

        $this->stripe
            ->answer($this->customer())
            ->answer($this->stripeError(
                code: 'promotion_code_customer_not_first_time',
                param: null,
            ), 400)
            ->answer($this->customer())
            ->answer($this->checkoutSession('cs_test_retry'));

        $this->checkout($user, 'pro_monthly')->assertOk()->assertJsonPath('session_id', 'cs_test_retry');

        $sessions = $this->stripe->requestsTo('post', '/v1/checkout/sessions');
        $this->assertCount(2, $sessions);
        $this->assertSame([['promotion_code' => 'promo_campaign']], $sessions[0]['params']['discounts']);
        $this->assertSessionWithoutCampaign($sessions[1]['params'], promotionCodes: true);

        $rows = BillingEvent::query()->orderBy('created_at')->get();
        $this->assertCount(2, $rows);

        $refused = $rows->firstWhere('type', BillingEventType::CAMPAIGN_CODE_REFUSED);
        $this->assertNotNull($refused);
        $this->assertSame(BillingSource::REQUEST, $refused->source);
        $this->assertSame(BillingProvider::STRIPE, $refused->provider);
        $this->assertSame('promotion_code_customer_not_first_time', $refused->reason);
        $this->assertSame((string) $user->getKey(), $refused->billable_id);
        $this->assertSame((string) $user->getKey(), (string) $refused->actor_user_id);
        $this->assertSame(
            [
                'campaign_id' => (string) $campaign->getKey(),
                'promotion_code_id' => 'promo_campaign',
                'stripe_code' => 'promotion_code_customer_not_first_time',
            ],
            $refused->properties,
        );

        $started = $rows->firstWhere('type', BillingEventType::CHECKOUT_STARTED);
        $this->assertNotNull($started);
        $this->assertSame('cs_test_retry', $started->external_id);
        $this->assertTrue($started->properties['promotion_codes']);
        $this->assertArrayNotHasKey('campaign_id', $started->properties);

        $this->assertSame(
            [CampaignCodeRefused::class, CheckoutStarted::class],
            array_map(static fn (BillingOutcome $event): string => $event::class, $this->dispatched),
        );
    }

    public function test_a_coupon_refused_on_the_discounts_param_is_retried_without_the_campaign(): void
    {
        $this->createCampaign();
        $user = $this->createUser('campaign-currency@example.test');

        $this->stripe
            ->answer($this->customer())
            ->answer($this->stripeError(code: null, param: 'discounts[0][promotion_code][coupon]'), 400)
            ->answer($this->customer())
            ->answer($this->checkoutSession('cs_test_retry'));

        $this->checkout($user, 'pro_monthly')->assertOk()->assertJsonPath('session_id', 'cs_test_retry');

        $sessions = $this->stripe->requestsTo('post', '/v1/checkout/sessions');
        $this->assertCount(2, $sessions);
        $this->assertSessionWithoutCampaign($sessions[1]['params'], promotionCodes: false);

        $refused = BillingEvent::query()->where('type', BillingEventType::CAMPAIGN_CODE_REFUSED->value)->sole();
        $this->assertNull($refused->properties['stripe_code']);
        $this->assertSame('campaign_code', $refused->reason);
    }

    /**
     * A coupon-level refusal (an exhausted or deleted coupon behind an active
     * code) names neither a `promotion_code_*` code nor the `discounts` param,
     * and must not block the purchase either.
     */
    public function test_a_refusal_naming_neither_the_code_nor_the_discounts_is_retried_without_the_campaign(): void
    {
        $campaign = $this->createCampaign();
        $user = $this->createUser('campaign-coupon-expired@example.test');

        $this->stripe
            ->answer($this->customer())
            ->answer($this->stripeError(code: 'coupon_expired', param: null), 400)
            ->answer($this->customer())
            ->answer($this->checkoutSession('cs_test_retry'));

        $this->checkout($user, 'pro_monthly')->assertOk()->assertJsonPath('session_id', 'cs_test_retry');

        $sessions = $this->stripe->requestsTo('post', '/v1/checkout/sessions');
        $this->assertCount(2, $sessions);
        $this->assertSame([['promotion_code' => 'promo_campaign']], $sessions[0]['params']['discounts']);
        $this->assertSessionWithoutCampaign($sessions[1]['params'], promotionCodes: false);

        $refused = BillingEvent::query()->where('type', BillingEventType::CAMPAIGN_CODE_REFUSED->value)->sole();
        $this->assertSame('coupon_expired', $refused->reason);
        $this->assertSame((string) $campaign->getKey(), $refused->properties['campaign_id']);
    }

    /**
     * A refusal that is not the campaign's comes back on the session opened
     * without it, and that one propagates: no refusal row blames the code.
     */
    public function test_a_refusal_that_repeats_without_the_campaign_propagates_and_records_nothing(): void
    {
        $this->createCampaign();
        $user = $this->createUser('campaign-other-error@example.test');

        $this->stripe
            ->answer($this->customer())
            ->answer($this->stripeError(code: null, param: 'line_items[0][price]'), 400)
            ->answer($this->customer())
            ->answer($this->stripeError(code: null, param: 'line_items[0][price]'), 400);

        $this->checkout($user, 'pro_monthly')->assertStatus(500);

        $sessions = $this->stripe->requestsTo('post', '/v1/checkout/sessions');
        $this->assertCount(2, $sessions);
        $this->assertSessionWithoutCampaign($sessions[1]['params'], promotionCodes: false);
        $this->assertSame(0, BillingEvent::query()->count());
    }

    public function test_overlapping_active_campaigns_resolve_to_the_earliest_start(): void
    {
        $this->createCampaign([
            'starts_at' => Carbon::parse('2026-11-02 00:00:00'),
            'stripe_promotion_code_id' => 'promo_later',
        ]);
        $this->createCampaign(['stripe_promotion_code_id' => 'promo_earlier']);

        $this->buy($this->createUser('campaign-overlap@example.test'), 'pro_monthly')->assertOk();

        $this->assertSame([['promotion_code' => 'promo_earlier']], $this->onlySessionParams()['discounts']);
    }

    /**
     * Queue a customer and a session, then open a checkout as [$user].
     */
    private function buy(CampaignCheckoutUser $user, string $product): TestResponse
    {
        $this->stripe
            ->answer($this->customer())
            ->answer($this->checkoutSession('cs_test_campaign'));

        return $this->checkout($user, $product);
    }

    private function checkout(CampaignCheckoutUser $user, string $product): TestResponse
    {
        return $this->actingAs($user->fresh(), 'sanctum')->postJson('/billing/checkout', [
            'product' => $product,
            'success_url' => self::SUCCESS_URL,
            'cancel_url' => self::CANCEL_URL,
            // Never read: the campaign comes from the database alone.
            'campaign' => 'camp-forged',
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function assertSessionWithoutCampaign(array $params, bool $promotionCodes): void
    {
        $this->assertArrayNotHasKey('discounts', $params);
        $this->assertArrayNotHasKey('client_reference_id', $params);
        $this->assertArrayNotHasKey('expires_at', $params);

        if ($promotionCodes) {
            $this->assertSame('true', $params['allow_promotion_codes']);
        } else {
            $this->assertArrayNotHasKey('allow_promotion_codes', $params);
        }
    }

    /**
     * The parameters of the one Checkout Session the request opened.
     *
     * @return array<string, mixed>
     */
    private function onlySessionParams(): array
    {
        $sessions = $this->stripe->requestsTo('post', '/v1/checkout/sessions');

        $this->assertCount(1, $sessions);

        return $sessions[0]['params'];
    }

    /**
     * @return array<string, mixed>
     */
    private function lastSessionParams(): array
    {
        $sessions = $this->stripe->requestsTo('post', '/v1/checkout/sessions');

        return $sessions[array_key_last($sessions)]['params'];
    }

    private function onlyRow(BillingEventType $type, Model $user): BillingEvent
    {
        $row = BillingEvent::query()->sole();

        $this->assertSame($type, $row->type);
        $this->assertSame(BillingSource::REQUEST, $row->source);
        $this->assertSame((string) $user->getKey(), $row->billable_id);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function customer(): array
    {
        return [
            'id' => 'cus_campaign_checkout',
            'object' => 'customer',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutSession(string $id): array
    {
        return [
            'id' => $id,
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.test/' . $id,
        ];
    }

    /**
     * A Stripe `invalid_request_error` body, as the probe recorded them.
     *
     * @return array<string, mixed>
     */
    private function stripeError(?string $code, ?string $param): array
    {
        return [
            'error' => array_filter([
                'type' => 'invalid_request_error',
                'code' => $code,
                'param' => $param,
                'message' => 'Refused by the stub.',
            ]),
        ];
    }

    /**
     * An active campaign on both pro products, with [$overrides] on top.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createCampaign(array $overrides = []): Campaign
    {
        $campaign = new Campaign;
        $campaign->forceFill([
            'name' => 'Pro week',
            'starts_at' => Carbon::parse('2026-11-01 00:00:00'),
            'ends_at' => Carbon::parse('2026-11-08 00:00:00'),
            'enabled' => true,
            'products' => ['pro_monthly', 'pro_annual'],
            'headline' => ['en' => 'Pro is 20% off this week'],
            'body' => ['en' => 'Upgrade before Sunday to keep the price.'],
            'stripe_promotion_code_id' => 'promo_campaign',
            'discount_type' => 'percent',
            'discount_value' => 20,
            ...$overrides,
        ])->save();

        return $campaign;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createUser(string $email, array $attributes = []): CampaignCheckoutUser
    {
        return CampaignCheckoutUser::query()->create([
            'name' => 'Campaign Buyer',
            'email' => $email,
            ...$attributes,
        ]);
    }

    private function bootRoutes(bool $campaigns): void
    {
        config([
            'magic-starter.features' => array_values(array_filter([
                Features::billing(),
                $campaigns ? Features::campaigns() : null,
            ])),
            'magic-starter.billing.billable' => 'user',
        ]);

        $this->app['router']->setRoutes(new RouteCollection);
        $this->app->forgetInstance(GateContract::class);
        Gate::clearResolvedInstance(GateContract::class);

        (new MagicStarterServiceProvider($this->app))->boot();
    }

    private function createSchema(): void
    {
        $schema = app('db.schema');

        $schema->create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('stripe_id')->nullable();
            $table->string('plan')->nullable();
            $table->string('plan_status')->nullable();
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

        foreach (['create_campaigns_table.php', 'create_billing_events_table.php'] as $migration) {
            $this->artisan('migrate', [
                '--path' => __DIR__ . '/../../database/migrations/' . $migration,
                '--realpath' => true,
            ]);
        }
    }
}

/**
 * A user carrying Cashier's REAL billable trait, so the checkout runs through
 * Cashier's own builder. The foreign key is pinned to `user_id` because Cashier
 * derives it from the class basename.
 */
class CampaignCheckoutUser extends Model implements AuthenticatableContract
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
