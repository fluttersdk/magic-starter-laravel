<?php

namespace FlutterSdk\MagicStarter\Tests\Campaigns;

use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\Events\Billing\BillingOutcome;
use FlutterSdk\MagicStarter\Events\Billing\CampaignRedeemed;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Models\Campaign;
use FlutterSdk\MagicStarter\Models\ProcessedWebhookEvent;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\Support\StripeHttpStub;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;

/**
 * Pins how a completed campaign checkout becomes a `campaign_redeemed` row:
 * a signed `checkout.session.completed` whose `client_reference_id` names a
 * campaign is recorded once, in the delivery's own transaction, with the
 * promotion code Stripe says was redeemed.
 *
 * The Stripe transport is the only thing faked ({@see StripeHttpStub}), so the
 * read-back of a session whose payload lacks its discounts goes through the
 * real reader and the SDK's real parameter encoding. A completed session was
 * never measured against Stripe (the probe needs a browser), which is why both
 * payload shapes are covered: discounts in the payload, and discounts only on
 * the retrieved session.
 */
class CampaignRedemptionWebhookTest extends TestCase
{
    private const WEBHOOK_SECRET = 'whsec_campaign_redemption';

    private const EVENT_AT = 1792400000;

    private const STRIPE_ID = 'cus_campaign_redemption';

    private const PROMOTION_CODE = 'promo_campaign';

    private StripeHttpStub $stripe;

    /**
     * Every outcome event a listener on {@see BillingOutcome} received.
     *
     * @var list<BillingOutcome>
     */
    private array $dispatched = [];

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        MagicStarter::reset();

        $app['config']->set([
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'magic-starter.features' => [
                Features::billing(),
                Features::campaigns(),
            ],
            'magic-starter.billing.billable' => 'user',
            'magic-starter.models.user' => CampaignRedemptionUser::class,
            'auth.providers.users.model' => CampaignRedemptionUser::class,
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'refs' => ['stripe_price' => 'price_pro'],
                ],
            ],
            'magic-starter.billing.tier_order' => ['free', 'pro'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        // Set after the providers booted for the reason StripeWebhookTest gives:
        // Cashier merges its own `webhook` block during register(), shallowly.
        config([
            'cashier.secret' => 'sk_test_campaign_redemption',
            'cashier.webhook.secret' => self::WEBHOOK_SECRET,
        ]);

        // The Cashier customer model is a register()-phase wiring, and Testbench
        // registers providers before defineEnvironment() runs.
        (new MagicStarterServiceProvider($this->app))->register();

        $this->dispatched = [];

        Event::listen(BillingOutcome::class, function (BillingOutcome $event): void {
            $this->dispatched[] = $event;
        });

        foreach ([
            'create_users_table.php',
            'add_cashier_customer_columns_to_billable_table.php',
            'create_processed_webhook_events_table.php',
            'create_billing_events_table.php',
            'create_campaigns_table.php',
        ] as $migration) {
            (require __DIR__ . '/../../database/migrations/' . $migration)->up();
        }

        $this->stripe = StripeHttpStub::install();
    }

    protected function tearDown(): void
    {
        StripeHttpStub::uninstall();
        Carbon::setTestNow();

        Cashier::useCustomerModel('App\\Models\\User');
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);
        Cashier::$registersRoutes = true;
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_a_completed_campaign_checkout_records_one_redemption(): void
    {
        $billable = $this->createBillable();
        $campaign = $this->createCampaign();

        $this->postSignedWebhook($this->completedSession('evt_redeemed', $this->referenceTo($campaign)))->assertOk();

        $row = $this->onlyRedemption();
        $this->assertSame(BillingSource::WEBHOOK, $row->source);
        $this->assertSame(BillingProvider::STRIPE, $row->provider);
        $this->assertSame($billable->getMorphClass(), $row->billable_type);
        $this->assertSame((string) $billable->getKey(), $row->billable_id);
        $this->assertSame('evt_redeemed', $row->external_id);
        $this->assertNull($row->reason);
        $this->assertSame(
            [
                'campaign_id' => (string) $campaign->getKey(),
                'promotion_code' => self::PROMOTION_CODE,
                'amount_discount' => 925,
            ],
            $row->properties,
        );

        // The payload carried everything, so Stripe was never asked.
        $this->assertSame([], $this->stripe->requests);

        $redeemed = array_values(array_filter(
            $this->dispatched,
            static fn (BillingOutcome $event): bool => $event instanceof CampaignRedeemed,
        ));
        $this->assertCount(1, $redeemed);
    }

    public function test_a_trial_checkout_discounting_nothing_still_counts(): void
    {
        $this->createBillable();
        $campaign = $this->createCampaign();

        $this->postSignedWebhook(
            $this->completedSession('evt_trial', $this->referenceTo($campaign), amountDiscount: 0),
        )->assertOk();

        $this->assertSame(0, $this->onlyRedemption()->properties['amount_discount']);
    }

    public function test_the_same_event_delivered_twice_records_one_redemption(): void
    {
        $this->createBillable();
        $campaign = $this->createCampaign();
        $event = $this->completedSession('evt_twice', $this->referenceTo($campaign));

        $this->postSignedWebhook($event)->assertOk();
        $this->postSignedWebhook($event)->assertOk();

        $this->onlyRedemption();
        $this->assertSame(1, ProcessedWebhookEvent::query()->where('event_id', 'evt_twice')->count());
    }

    public function test_a_payload_without_discounts_is_read_back_from_the_expanded_session(): void
    {
        $this->createBillable();
        $campaign = $this->createCampaign();

        $this->stripe->answer([
            'id' => 'cs_test_completed',
            'object' => 'checkout.session',
            'discounts' => [
                [
                    'coupon' => null,
                    'promotion_code' => self::PROMOTION_CODE,
                ],
            ],
            'total_details' => [
                'amount_discount' => 1450,
            ],
        ]);

        $this->postSignedWebhook(
            $this->completedSession('evt_readback', $this->referenceTo($campaign), withDiscounts: false),
        )->assertOk();

        $reads = $this->stripe->requestsTo('get', '/v1/checkout/sessions/cs_test_completed');
        $this->assertCount(1, $reads);
        $this->assertSame(['discounts'], $reads[0]['params']['expand']);
        $this->assertSame(
            [
                'campaign_id' => (string) $campaign->getKey(),
                'promotion_code' => self::PROMOTION_CODE,
                'amount_discount' => 1450,
            ],
            $this->onlyRedemption()->properties,
        );
    }

    public function test_a_session_that_redeemed_no_code_even_when_read_back_records_nothing(): void
    {
        $this->createBillable();
        $campaign = $this->createCampaign();

        $this->stripe->answer([
            'id' => 'cs_test_completed',
            'object' => 'checkout.session',
            'discounts' => [],
            'total_details' => [
                'amount_discount' => 0,
            ],
        ]);

        $this->postSignedWebhook(
            $this->completedSession('evt_no_code', $this->referenceTo($campaign), withDiscounts: false),
        )->assertOk();

        $this->assertSame(0, $this->redemptions()->count());
        $this->assertSame(1, ProcessedWebhookEvent::query()->where('event_id', 'evt_no_code')->count());
    }

    public function test_a_stripe_failure_on_the_read_back_rolls_the_claim_back_so_a_retry_records_it(): void
    {
        $this->createBillable();
        $campaign = $this->createCampaign();
        $event = $this->completedSession('evt_retry', $this->referenceTo($campaign), withDiscounts: false);

        $this->stripe->answer([
            'error' => [
                'type' => 'api_error',
                'message' => 'Stripe is down.',
            ],
        ], 500);

        $this->assertSame(500, $this->postSignedWebhook($event)->getStatusCode());
        $this->assertSame(0, ProcessedWebhookEvent::query()->where('event_id', 'evt_retry')->count());
        $this->assertSame(0, $this->redemptions()->count());

        $this->stripe->answer([
            'id' => 'cs_test_completed',
            'object' => 'checkout.session',
            'discounts' => [
                [
                    'promotion_code' => self::PROMOTION_CODE,
                ],
            ],
            'total_details' => [
                'amount_discount' => 925,
            ],
        ]);

        $this->postSignedWebhook($event)->assertOk();

        $this->onlyRedemption();
    }

    public function test_without_the_campaigns_feature_the_session_is_not_looked_at(): void
    {
        $this->createBillable();
        $campaign = $this->createCampaign();
        config(['magic-starter.features' => [Features::billing()]]);
        Schema::drop('campaigns');

        $this->postSignedWebhook($this->completedSession('evt_off', $this->referenceTo($campaign)))->assertOk();

        $this->assertSame(0, $this->redemptions()->count());
        $this->assertSame(1, ProcessedWebhookEvent::query()->where('event_id', 'evt_off')->count());
    }

    public function test_an_unknown_campaign_id_records_nothing(): void
    {
        $this->createBillable();
        $this->createCampaign();

        $this->postSignedWebhook($this->completedSession('evt_unknown', 'campaign:987654'))->assertOk();

        $this->assertSame(0, $this->redemptions()->count());
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_a_reference_that_is_not_a_campaign_key_records_nothing(): void
    {
        $this->createBillable();
        $this->createCampaign();

        $this->postSignedWebhook($this->completedSession('evt_foreign', 'order-7'))->assertOk();

        $this->assertSame(0, $this->redemptions()->count());
        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * Another integration's session may carry a bare key that happens to be a
     * campaign's; only the `campaign:` reference the checkout writes counts.
     */
    public function test_a_bare_campaign_key_without_the_prefix_records_nothing(): void
    {
        $this->createBillable();
        $campaign = $this->createCampaign();

        $this->postSignedWebhook(
            $this->completedSession('evt_bare', (string) $campaign->getKey()),
        )->assertOk();

        $this->assertSame(0, $this->redemptions()->count());
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_a_prefixed_reference_that_is_not_a_campaign_key_records_nothing(): void
    {
        $this->createBillable();
        $this->createCampaign();

        $this->postSignedWebhook($this->completedSession('evt_prefixed_foreign', 'campaign:order-7'))->assertOk();

        $this->assertSame(0, $this->redemptions()->count());
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_a_session_without_a_client_reference_records_nothing(): void
    {
        $this->createBillable();
        $this->createCampaign();

        $this->postSignedWebhook($this->completedSession('evt_plain', null))->assertOk();

        $this->assertSame(0, $this->redemptions()->count());
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_a_customer_nothing_bills_records_nothing(): void
    {
        $campaign = $this->createCampaign();

        $this->postSignedWebhook(
            $this->completedSession('evt_stranger', $this->referenceTo($campaign)),
        )->assertOk();

        $this->assertSame(0, $this->redemptions()->count());
    }

    public function test_an_unsigned_delivery_is_refused(): void
    {
        $this->createBillable();
        $campaign = $this->createCampaign();

        $this->postSignedWebhook(
            $this->completedSession('evt_unsigned', $this->referenceTo($campaign)),
            'whsec_somebody_else',
        )->assertForbidden();

        $this->assertSame(0, $this->redemptions()->count());
    }

    /**
     * @return Builder<BillingEvent>
     */
    private function redemptions(): Builder
    {
        return BillingEvent::query()->where('type', BillingEventType::CAMPAIGN_REDEEMED->value);
    }

    private function onlyRedemption(): BillingEvent
    {
        $this->assertSame(1, $this->redemptions()->count());

        return $this->redemptions()->sole();
    }

    private function createBillable(): CampaignRedemptionUser
    {
        return CampaignRedemptionUser::query()->create([
            'name' => 'Redeemer',
            'email' => 'redeemer@example.test',
            'password' => 'secret',
            'stripe_id' => self::STRIPE_ID,
        ]);
    }

    private function createCampaign(): Campaign
    {
        return Campaign::query()->create([
            'name' => 'Launch week',
            'starts_at' => Carbon::parse('2026-11-01 00:00:00'),
            'ends_at' => Carbon::parse('2026-11-08 00:00:00'),
            'enabled' => true,
            'products' => ['pro_monthly'],
            'headline' => ['en' => 'Launch week'],
            'body' => ['en' => 'Half off.'],
            'stripe_promotion_code_id' => self::PROMOTION_CODE,
        ]);
    }

    /**
     * The `client_reference_id` the checkout writes for [$campaign].
     */
    private function referenceTo(Campaign $campaign): string
    {
        return 'campaign:' . $campaign->getKey();
    }

    /**
     * A `checkout.session.completed` event for a subscription session.
     *
     * `$withDiscounts` false builds the payload shape the read-back exists for:
     * the session without its `discounts` and `total_details`.
     *
     * @return array<string, mixed>
     */
    private function completedSession(
        string $eventId,
        ?string $clientReferenceId,
        bool $withDiscounts = true,
        int $amountDiscount = 925,
    ): array {
        $session = [
            'id' => 'cs_test_completed',
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'status' => 'complete',
            'customer' => self::STRIPE_ID,
            'client_reference_id' => $clientReferenceId,
            'subscription' => 'sub_campaign_redemption',
        ];

        if ($withDiscounts) {
            $session['discounts'] = [
                [
                    'coupon' => null,
                    'promotion_code' => self::PROMOTION_CODE,
                ],
            ];
            $session['total_details'] = [
                'amount_discount' => $amountDiscount,
            ];
        }

        return [
            'id' => $eventId,
            'type' => 'checkout.session.completed',
            'created' => self::EVENT_AT,
            'data' => ['object' => $session],
        ];
    }

    /**
     * POST a Stripe-signed payload to the package's endpoint.
     *
     * @param  array<string, mixed>  $event
     */
    private function postSignedWebhook(array $event, ?string $secret = null): TestResponse
    {
        $payload = (string) json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? self::WEBHOOK_SECRET);

        return $this->call(
            'POST',
            'stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            $payload,
        );
    }
}

/**
 * The billable the webhook resolves, carrying Cashier's real trait so the
 * customer lookup is Cashier's own.
 */
class CampaignRedemptionUser extends ConcreteUser
{
    use Billable;
}
