<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use Carbon\CarbonImmutable;
use FlutterSdk\MagicStarter\Actions\WriteEntitlement;
use FlutterSdk\MagicStarter\Contracts\WritesEntitlement;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\StripeWebhookController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\ProcessedWebhookEvent;
use FlutterSdk\MagicStarter\Support\EntitlementWrite;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Locks the Stripe webhook feeder: the authoritative, idempotent rail that
 * writes a billable's entitlement columns through {@see WritesEntitlement}.
 *
 * Five properties are pinned here, and every one of them has a mutant that
 * every other test in this package would survive:
 *
 *  1. A signed delivery writes an entitlement THROUGH THE CONTRACT. Asserted by
 *     decorating the binding rather than by reading columns alone, because a
 *     feeder that force-filled the ten columns itself would leave exactly the
 *     same row behind while bypassing every ordering rule that protects it.
 *  2. An unsigned delivery is refused. The middleware is inherited from Cashier's
 *     own constructor, so the mutant is a constructor that forgets
 *     `parent::__construct()`: the route is then unsigned and nothing else
 *     changes.
 *  3. A replayed event id is a total no-op, and a mid-handler failure leaves NO
 *     dedup row behind. The second half is what makes the outer transaction
 *     load-bearing: without it a crash mid-flight turns Stripe's retry into a
 *     permanent no-op and a cancelled customer keeps a paid tier for free.
 *  4. The route does not move when `magic-starter.route_prefix` does, and the
 *     served path is literally `stripe/webhook`. A test asserting only that
 *     "some webhook route answers" passes while the URL every adopter has in
 *     their Stripe dashboard quietly moves.
 *  5. Nothing is registered while the billing feature is off.
 *
 * The provenance assertions carry a sixth, quieter one. The ten columns here are
 * created by the package's own migrations, so `plan_renews` is a real boolean
 * column and the three dates are `timestamptz`, and the fixture billable casts
 * NONE of them: that is the shape an adopter is entitled to ship, since the
 * package owns the columns and not the model. A feeder reading them straight off
 * the model hands a raw SQLite `1` to a `?bool` and a raw string to a
 * `?CarbonInterface`, which is a TypeError on the payment path. The invoice
 * re-affirmation is the only handler that reads them back, and
 * {@see test_a_paid_invoice_keeps_the_period_it_cannot_read()} is where that
 * mutant dies.
 */
class StripeWebhookTest extends TestCase
{
    /**
     * The webhook signing secret every payload in this file is signed with.
     */
    protected const WEBHOOK_SECRET = 'whsec_test_secret';

    /**
     * The instant every payload's `created` field counts from: 2026-08-22
     * 12:00:00 UTC.
     *
     * Fixed rather than `time()` so an assertion compares against a value the
     * payload states. Any scenario delivering two events to ONE billable should
     * space them apart from here, because the write path drops a same-rail write
     * whose event is OLDER than the one on record, and decides a TIE by
     * direction rather than by arrival order.
     */
    protected const EVENT_AT = 1787400000;

    /**
     * The Stripe customer id the billable fixture carries.
     */
    protected const STRIPE_ID = 'cus_webhook_test';

    /**
     * Configure the application before its providers BOOT.
     *
     * Testbench runs this hook between `RegisterProviders` and `BootProviders`,
     * so the configuration below reaches the package's `boot()` (which is what
     * registers the routes) and NOT its `register()`. The register-phase wiring
     * is re-driven from `setUp()` for that reason; see the comment there.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The runtime overrides are STATICS on MagicStarter, so a model another
        // test in this process registered outlives that test's application and
        // would win over the config below.
        MagicStarter::reset();

        $app['config']->set([
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing.billable' => 'user',
            'magic-starter.models.user' => User::class,
            'auth.providers.users.model' => User::class,
            // Which Stripe price sells which of the adopter's tiers, and the
            // ranking the cross-rail rules compare against.
            'magic-starter.billing.prices' => [
                'price_pro' => 'pro',
                'price_business' => 'business',
            ],
            'magic-starter.billing.tier_order' => ['free', 'pro', 'business'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The parent registers its own fixture models AFTER the application has
        // booted, so clear them again: everything below resolves the billable
        // from config, exactly as the provider did during register().
        MagicStarter::reset();

        // Set here rather than in defineEnvironment() because CashierServiceProvider
        // merges its own config during register(), and mergeConfigFrom is a shallow
        // array_merge: a `webhook` key written earlier would REPLACE Cashier's whole
        // block and take `tolerance` with it.
        config(['cashier.webhook.secret' => static::WEBHOOK_SECRET]);

        // Testbench bootstraps RegisterProviders BEFORE it applies
        // defineEnvironment(), so the package's register() ran against the
        // skeleton's configuration rather than against the billing one above.
        // Re-driven here rather than worked around, because the call this file
        // depends on is a register()-phase one by Cashier's own design: Cashier
        // resolves $customerModel from its boot() onwards, so register() is the
        // last phase that still lands in a real application. The provider's own
        // method is called; nothing about it is stubbed.
        (new MagicStarterServiceProvider($this->app))->register();

        RecordingEntitlementWriter::reset();
        SwitchableEntitlementWriter::reset();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        // All four are STATICS, so they outlive the application this test booted.
        Cashier::useCustomerModel('App\\Models\\User');
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);
        Cashier::$registersRoutes = true;

        RecordingEntitlementWriter::reset();
        SwitchableEntitlementWriter::reset();
        MagicStarter::reset();

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // The wiring this rail cannot work without
    // -------------------------------------------------------------------------

    /**
     * The provider points Cashier at the declared billable, in `register()`.
     *
     * Left at Cashier's `App\Models\User` default, `findBillable()` searches the
     * users table on a team-billing application and matches nobody: every
     * delivery resolves no customer, answers 200, and writes no entitlement.
     * Nothing about that looks like a failure from the outside.
     */
    public function test_the_provider_points_cashier_at_the_billable_model(): void
    {
        $this->assertSame(User::class, MagicStarter::billableModel());
        $this->assertSame(User::class, Cashier::$customerModel);
    }

    /**
     * The same wiring under the TEAM subject, which is the case it exists for.
     *
     * The user subject above cannot fail for the right reason on its own: a
     * consumer billing a user is close enough to Cashier's default that a
     * missing `useCustomerModel()` call still resolves somebody. Only a subject
     * that is NOT a user can tell the wiring from the default.
     */
    public function test_the_billable_model_follows_a_team_subject_too(): void
    {
        config([
            'magic-starter.features' => [Features::teams(), Features::billing()],
            'magic-starter.billing.billable' => 'team',
            'magic-starter.models.team' => \FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam::class,
        ]);

        (new MagicStarterServiceProvider($this->app))->register();

        $this->assertSame(
            \FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam::class,
            Cashier::$customerModel,
        );
    }

    // -------------------------------------------------------------------------
    // The route: where it is, and where it is not
    // -------------------------------------------------------------------------

    /**
     * The served path is literally `stripe/webhook`, and it is THIS controller.
     *
     * Both halves are needed. The path is the string in every adopter's Stripe
     * dashboard, so a package-owned path here would 404 their deliveries until
     * somebody edited it by hand; and the action name is what tells this
     * registration apart from Cashier's own, which serves the same URI with none
     * of the idempotency or the entitlement projection.
     */
    public function test_the_served_path_is_stripe_webhook_and_answers_from_this_controller(): void
    {
        $route = $this->matchRoute('POST', 'stripe/webhook');

        $this->assertNotNull($route, 'The Stripe webhook must be registered under cashier.path.');
        $this->assertSame('stripe/webhook', $route->uri());
        $this->assertSame(StripeWebhookController::class . '@handleWebhook', $route->getActionName());

        // The path resolves to `stripe/webhook` because the route file reads
        // config('cashier.path') and falls back to Cashier's own default. That
        // fallback is asserted against CASHIER'S SHIPPED CONFIG rather than
        // hardcoded twice: this package's test environment does not load
        // CashierServiceProvider, so the key is absent here and only the fallback
        // answers, and without this line a change to Cashier's default would move
        // every adopter's path with nothing in this file to notice.
        $cashierDefaults = require __DIR__ . '/../../../vendor/laravel/cashier/config/cashier.php';

        $this->assertSame('stripe', $cashierDefaults['path']);
    }

    /**
     * An adopter who moves `cashier.path` moves this route with it.
     *
     * The mutant is a route file that hardcodes `stripe/webhook`. It passes the
     * test above and every delivery test, and it breaks exactly one adopter: the
     * one who set CASHIER_PATH, whose dashboard points somewhere this package
     * would then refuse to answer.
     */
    public function test_the_route_follows_cashier_path_when_an_adopter_moves_it(): void
    {
        config(['cashier.path' => 'stripe-billing']);

        $this->bootRoutes([Features::billing()]);

        $this->assertNotNull($this->matchRoute('POST', 'stripe-billing/webhook'));
        $this->assertNull($this->matchRoute('POST', 'stripe/webhook'));
    }

    /**
     * The webhook does NOT move when the API prefix does.
     *
     * The two controls are the whole test. Asserting only that a webhook route
     * still exists passes even when it inherited the prefix, because a prefixed
     * route exists too; what has to be shown is that the API routes MOVED in the
     * same boot while this one did not.
     */
    public function test_the_webhook_path_survives_a_changed_route_prefix(): void
    {
        $this->bootRoutes([Features::billing()], 'api/v9');

        $route = $this->matchRoute('POST', 'stripe/webhook');

        $this->assertNotNull($route, 'The webhook must not inherit magic-starter.route_prefix.');
        $this->assertSame(StripeWebhookController::class . '@handleWebhook', $route->getActionName());
        $this->assertNull($this->matchRoute('POST', 'api/v9/stripe/webhook'));

        // The controls: the API surface really did move in this same boot, so
        // the assertion above is the webhook standing still rather than a prefix
        // that was never applied to anything.
        $this->assertNotNull($this->matchRoute('GET', 'api/v9/billing/plans'));
        $this->assertNull($this->matchRoute('GET', 'billing/plans'));
        $this->assertNotNull($this->matchRoute('POST', 'api/v9/auth/login'));
        $this->assertNull($this->matchRoute('POST', 'auth/login'));
    }

    /**
     * With billing off, no webhook route is registered at all.
     */
    public function test_no_webhook_route_exists_while_billing_is_disabled(): void
    {
        $this->bootRoutes([]);

        $this->assertNull($this->matchRoute('POST', 'stripe/webhook'));

        // The disarming limb: the provider booted and registered its other
        // routes, so the absence above is the billing gate and not an empty
        // router.
        $this->assertNotNull($this->matchRoute('POST', 'auth/login'));
    }

    /**
     * A consumer taking route registration over takes the webhook with it.
     */
    public function test_ignore_routes_withholds_the_webhook_too(): void
    {
        MagicStarter::ignoreRoutes();

        $this->bootRoutes([Features::billing()]);

        $this->assertNull($this->matchRoute('POST', 'stripe/webhook'));
    }

    // -------------------------------------------------------------------------
    // Signature verification, inherited rather than routed
    // -------------------------------------------------------------------------

    /**
     * An unsigned delivery is refused and writes nothing.
     *
     * The mutant is a constructor that declares itself and forgets
     * `parent::__construct()`. Cashier attaches VerifyWebhookSignature from
     * there, so the route silently unsigns and every other test in this file
     * still passes: they all sign.
     */
    public function test_an_unsigned_delivery_is_refused(): void
    {
        $billable = $this->createBillable();

        $payload = json_encode(
            $this->subscriptionEvent('evt_unsigned', 'customer.subscription.created', 'price_pro', 'active'),
        );

        $response = $this->call(
            'POST',
            'stripe/webhook',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) $payload,
        );

        $this->assertSame(403, $response->getStatusCode());

        $billable->refresh();
        $this->assertNull($billable->getAttribute('plan'));
        $this->assertSame(0, ProcessedWebhookEvent::query()->count());
    }

    /**
     * A delivery signed with the WRONG secret is refused as well.
     *
     * The control on the test above: a 403 there could also come from a route
     * that answers nobody, and this one shows the endpoint is reachable and
     * checking the signature rather than merely closed.
     */
    public function test_a_delivery_signed_with_the_wrong_secret_is_refused(): void
    {
        $this->createBillable();

        $response = $this->postSignedWebhook(
            $this->subscriptionEvent('evt_wrong_secret', 'customer.subscription.created', 'price_pro', 'active'),
            'whsec_not_the_configured_one',
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // The entitlement projection
    // -------------------------------------------------------------------------

    /**
     * A signed subscription event writes the entitlement THROUGH the contract.
     *
     * The recorded claim is the half a column assertion cannot make: a feeder
     * that force-filled the ten columns itself leaves an identical row behind
     * while bypassing every ordering rule that decides whether the row should
     * have changed at all.
     */
    public function test_a_signed_delivery_writes_the_entitlement_through_the_contract(): void
    {
        $this->recordEntitlementWrites();

        $billable = $this->createBillable();

        $this->postSignedWebhook(
            $this->subscriptionEvent('evt_created', 'customer.subscription.created', 'price_pro', 'active'),
        )->assertOk();

        $this->assertCount(1, RecordingEntitlementWriter::$claims);

        $claim = RecordingEntitlementWriter::$claims[0];
        $this->assertTrue($claim->billable->is($billable));
        $this->assertSame('pro', $claim->plan);
        $this->assertSame(PlanStatus::ACTIVE, $claim->status);
        $this->assertSame(BillingProvider::STRIPE, $claim->provider);
        $this->assertTrue($claim->authoritative);
        $this->assertSame(static::EVENT_AT, $claim->eventAt->getTimestamp());

        $billable->refresh();
        $this->assertSame('pro', $billable->getAttribute('plan'));
        $this->assertSame('active', $billable->getAttribute('plan_status'));

        // The payload carries neither period key, so both columns stay NULL:
        // null is "the rail has not said", and a feeder that defaulted the renew
        // flag to true would be inventing a claim Stripe never made.
        $this->assertNull($billable->getAttribute('plan_current_period_end'));
        $this->assertNull($billable->getAttribute('plan_renews'));
    }

    /**
     * A subscription event lands every provenance column it has a source for.
     *
     * A field the feeder forgets has no symptom other than a column that serves
     * null forever, on a rail whose data sat in the payload the whole time. The
     * period end is asserted from the PAYLOAD for a second reason: reading it off
     * Cashier's `currentPeriodEnd()` instead would be a live Stripe API call per
     * subscription item, inside a handler running in a transaction, and this test
     * configures no Stripe key for it to reach.
     */
    public function test_a_subscription_event_records_the_full_stripe_provenance(): void
    {
        $billable = $this->createBillable();
        $periodEnd = static::EVENT_AT + 2592000;

        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_provenance',
                'customer.subscription.created',
                'price_pro',
                'active',
                currentPeriodEnd: $periodEnd,
                cancelAtPeriodEnd: false,
            ),
        )->assertOk();

        $billable->refresh();
        $this->assertSame('pro', $billable->getAttribute('plan'));
        $this->assertSame(PlanStatus::ACTIVE->value, $billable->getAttribute('plan_status'));
        $this->assertSame(BillingProvider::STRIPE->value, $billable->getAttribute('plan_provider'));
        $this->assertSame(static::EVENT_AT, $this->storedTimestamp($billable, 'plan_source_event_at'));
        $this->assertSame('active', $billable->getAttribute('plan_provider_status'));
        $this->assertSame('price_pro', $billable->getAttribute('plan_product_id'));
        $this->assertSame($periodEnd, $this->storedTimestamp($billable, 'plan_current_period_end'));
        $this->assertTrue($this->storedFlag($billable, 'plan_renews'));

        // Neither column has a source on the Stripe rail: Cashier's grace period
        // is "cancelled but still entitled" rather than a dunning window, and a
        // billing-portal URL is a short-lived single-use session, not a durable
        // value a column can hold.
        $this->assertNull($billable->getAttribute('plan_grace_period_ends_at'));
        $this->assertNull($billable->getAttribute('plan_manage_url'));
    }

    /**
     * `cancel_at_period_end` is the renew flag, inverted.
     *
     * Delivered as `customer.subscription.created` deliberately: Cashier's
     * `handleCustomerSubscriptionUpdated()` answers a truthy `cancel_at_period_end`
     * by calling `$subscription->currentPeriodEnd()`, which is a live Stripe API
     * call, so the updated event cannot carry this flag in a test at all.
     */
    public function test_a_subscription_cancelling_at_period_end_is_recorded_as_not_renewing(): void
    {
        $billable = $this->createBillable();

        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_no_renew',
                'customer.subscription.created',
                'price_pro',
                'active',
                currentPeriodEnd: static::EVENT_AT + 2592000,
                cancelAtPeriodEnd: true,
            ),
        )->assertOk();

        $billable->refresh();
        // Still entitled until the period ends, and the period end is what says
        // until when; the flag only says it will not roll over.
        $this->assertSame('pro', $billable->getAttribute('plan'));
        $this->assertFalse($this->storedFlag($billable, 'plan_renews'));
        $this->assertSame(static::EVENT_AT + 2592000, $this->storedTimestamp($billable, 'plan_current_period_end'));
    }

    public function test_a_subscription_update_swaps_the_entitlement_tier(): void
    {
        $billable = $this->createBillable();

        $this->seedActiveSubscription();

        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_updated',
                'customer.subscription.updated',
                'price_business',
                'active',
                created: static::EVENT_AT + 60,
            ),
        )->assertOk();

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
        $this->assertSame('active', $billable->getAttribute('plan_status'));
    }

    /**
     * The one Stripe status word with no neutral twin.
     *
     * `plan_status` speaks the rail-neutral vocabulary, which has no
     * `incomplete_expired`: an initial payment window that ran out is
     * {@see PlanStatus::EXPIRED}. The rail's own word is not lost, it moves to
     * `plan_provider_status`, which gates nothing and exists for exactly this.
     *
     * The tier lands as NULL rather than as the floor tier. Stripe is saying it
     * is billing nothing, and the column says that instead of naming the
     * cheapest tier on the rail's behalf.
     */
    public function test_an_incomplete_expired_update_revokes_the_tier(): void
    {
        $billable = $this->createBillable();

        $this->seedActiveSubscription();

        // Stripe deletes the subscription on this branch, so Cashier's handler
        // returns null; the entitlement must still revoke without a 500.
        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_incomplete',
                'customer.subscription.updated',
                'price_pro',
                'incomplete_expired',
                created: static::EVENT_AT + 60,
            ),
        )->assertOk();

        $billable->refresh();
        $this->assertNull($billable->getAttribute('plan'));
        $this->assertSame(PlanStatus::EXPIRED->value, $billable->getAttribute('plan_status'));
        $this->assertSame('incomplete_expired', $billable->getAttribute('plan_provider_status'));
    }

    /**
     * A status word this build has never seen revokes, and must not be laundered
     * into `active` on the way in.
     *
     * Stripe can add a subscription status at any time. An unlisted one is absent
     * from the granting list, so the entitlement falls away; the neutral column
     * takes the enum's safe default rather than guessing, and the unknown word
     * survives verbatim for whoever has to work out what it meant.
     */
    public function test_an_unknown_stripe_status_neither_grants_nor_becomes_active(): void
    {
        $billable = $this->createBillable();

        $this->seedActiveSubscription();

        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_unknown',
                'customer.subscription.updated',
                'price_pro',
                'a_status_stripe_added_later',
                created: static::EVENT_AT + 60,
            ),
        )->assertOk();

        $billable->refresh();
        $this->assertNull($billable->getAttribute('plan'));
        $this->assertSame(PlanStatus::NONE->value, $billable->getAttribute('plan_status'));
        $this->assertSame('a_status_stripe_added_later', $billable->getAttribute('plan_provider_status'));
    }

    /**
     * A late-delivered OLDER event does not overwrite a newer one.
     *
     * This is the assertion that proves the feeder passes the EVENT's own
     * `created` rather than the moment of receipt. Receipt time only ever
     * increases, so a feeder stamping `now()` would read this second delivery as
     * the freshest truth on record and move a business subject back down to pro,
     * while satisfying every type check and every other test in this file.
     */
    public function test_a_late_delivered_older_event_does_not_overwrite_a_newer_one(): void
    {
        Log::spy();

        $billable = $this->createBillable();

        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_order_new',
                'customer.subscription.created',
                'price_business',
                'active',
                created: static::EVENT_AT + 3600,
            ),
        )->assertOk();

        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_order_old',
                'customer.subscription.updated',
                'price_pro',
                'active',
                created: static::EVENT_AT,
            ),
        )->assertOk();

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
        $this->assertSame(static::EVENT_AT + 3600, $this->storedTimestamp($billable, 'plan_source_event_at'));

        // Asserted on the drop's own reason, not merely on "a warning": an
        // unmapped price warns too, and it would pass a weaker assertion while
        // the ordering rule was never reached.
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => ($context['reason'] ?? null) === 'stale');
    }

    public function test_a_deleted_subscription_revokes_the_tier(): void
    {
        $billable = $this->createBillable();

        $this->seedActiveSubscription();

        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_deleted',
                'customer.subscription.deleted',
                'price_pro',
                'canceled',
                created: static::EVENT_AT + 60,
            ),
        )->assertOk();

        $billable->refresh();
        // NULL, not the floor tier: a deleted subscription owes nothing, and the
        // column stopped naming a tier to say so.
        $this->assertNull($billable->getAttribute('plan'));
        $this->assertSame('canceled', $billable->getAttribute('plan_status'));
        $this->assertSame('canceled', $billable->getAttribute('plan_provider_status'));
        $this->assertSame(BillingProvider::STRIPE->value, $billable->getAttribute('plan_provider'));
        $this->assertSame(static::EVENT_AT + 60, $this->storedTimestamp($billable, 'plan_source_event_at'));
    }

    public function test_a_paid_invoice_reaffirms_the_active_entitlement(): void
    {
        $billable = $this->createBillable();

        $this->seedActiveSubscription();

        // Drift the entitlement, then let a paid invoice re-assert it from the
        // local Cashier row's price.
        $billable->forceFill(['plan' => null, 'plan_status' => 'canceled'])->save();

        $this->postSignedWebhook(
            $this->invoiceEvent('evt_invoice', created: static::EVENT_AT + 60),
        )->assertOk();

        $billable->refresh();
        $this->assertSame('pro', $billable->getAttribute('plan'));
        $this->assertSame('active', $billable->getAttribute('plan_status'));
        $this->assertSame('price_pro', $billable->getAttribute('plan_product_id'));
    }

    /**
     * A paid invoice carries the period forward WITHOUT re-reading it as a cast
     * value.
     *
     * This is the test that kills the raw-attribute mutant. `plan_renews` is a
     * real boolean column that SQLite hands back as `1`, and
     * `plan_current_period_end` is a `timestamptz` the fixture billable does not
     * cast, so a handler passing either one straight into
     * {@see EntitlementWrite} is a TypeError on a payment path: `?bool` refuses
     * an int and `?CarbonInterface` refuses a string. Every other handler in this
     * file writes those two columns from the PAYLOAD and would never notice.
     *
     * The forward-carry itself is the second claim: an invoice object has no
     * subscription items, so this path has no period of its own, and the write
     * path sets all ten columns on every apply. Passing nothing would blank a
     * period the subscription event had already established.
     */
    public function test_a_paid_invoice_keeps_the_period_it_cannot_read(): void
    {
        $billable = $this->createBillable();
        $periodEnd = static::EVENT_AT + 2592000;

        $this->seedActiveSubscription(currentPeriodEnd: $periodEnd, cancelAtPeriodEnd: false);

        // The stored shape is the point: uncast columns, straight off the
        // package's own migration.
        $this->assertIsNotBool($billable->fresh()?->getAttribute('plan_renews'));

        $this->postSignedWebhook(
            $this->invoiceEvent('evt_inv_period', created: static::EVENT_AT + 60),
        )->assertOk();

        $billable->refresh();
        $this->assertSame('pro', $billable->getAttribute('plan'));
        $this->assertSame(BillingProvider::STRIPE->value, $billable->getAttribute('plan_provider'));
        $this->assertSame(static::EVENT_AT + 60, $this->storedTimestamp($billable, 'plan_source_event_at'));
        $this->assertSame($periodEnd, $this->storedTimestamp($billable, 'plan_current_period_end'));
        $this->assertTrue($this->storedFlag($billable, 'plan_renews'));
    }

    /**
     * A paid invoice does not re-attribute ANOTHER rail's period to Stripe.
     *
     * Carrying the stored period forward is only meaningful while Stripe is the
     * rail on record. A store record that has LAPSED is a slot another rail may
     * fill, so the write lands, and the store's period must not follow it across:
     * this feeder cannot read Stripe's period out of an invoice payload, and
     * reading Cashier's accessor for it would be a live Stripe call, so null is
     * the honest answer.
     */
    public function test_a_cross_rail_invoice_carries_no_foreign_period(): void
    {
        $billable = $this->createBillable();

        $this->seedActiveSubscription();

        $billable->forceFill([
            'plan' => 'pro',
            'plan_status' => PlanStatus::EXPIRED->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => CarbonImmutable::createFromTimestamp(static::EVENT_AT + 30),
            'plan_current_period_end' => CarbonImmutable::createFromTimestamp(static::EVENT_AT + 2592000),
            'plan_renews' => true,
        ])->save();

        $this->postSignedWebhook(
            $this->invoiceEvent('evt_cross_rail_invoice', created: static::EVENT_AT + 60),
        )->assertOk();

        $billable->refresh();
        $this->assertSame('pro', $billable->getAttribute('plan'));
        $this->assertSame(BillingProvider::STRIPE->value, $billable->getAttribute('plan_provider'));

        // The store's period did not follow it across.
        $this->assertNull($billable->getAttribute('plan_current_period_end'));
        $this->assertNull($billable->getAttribute('plan_renews'));
    }

    /**
     * A granting status whose price is unmapped is a config gap, never a
     * downgrade.
     */
    public function test_a_granting_status_with_an_unmapped_price_leaves_a_paid_subject_untouched(): void
    {
        Log::spy();

        $billable = $this->createBillable();

        $this->seedActiveSubscription();

        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_gap',
                'customer.subscription.updated',
                'price_nobody_mapped',
                'active',
                created: static::EVENT_AT + 60,
            ),
        )->assertOk();

        $billable->refresh();
        $this->assertSame('pro', $billable->getAttribute('plan'));
        $this->assertSame('active', $billable->getAttribute('plan_status'));

        // The provenance still points at the seed event: the skip means NO write
        // happened, which a plan assertion alone cannot distinguish from a write
        // that happened to land on the same tier.
        $this->assertSame(static::EVENT_AT, $this->storedTimestamp($billable, 'plan_source_event_at'));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => array_key_exists('price_id', $context));
    }

    // -------------------------------------------------------------------------
    // Idempotency, and the transaction that makes a retry safe
    // -------------------------------------------------------------------------

    public function test_a_replayed_event_id_is_a_total_no_op(): void
    {
        $billable = $this->createBillable();

        $event = $this->subscriptionEvent('evt_replay', 'customer.subscription.created', 'price_pro', 'active');

        $this->postSignedWebhook($event)->assertOk();

        $billable->refresh();
        $this->assertSame('pro', $billable->getAttribute('plan'));

        // Drift the entitlement, then re-deliver the SAME event id: the dedup
        // guard must skip processing so the drift survives untouched.
        $billable->forceFill(['plan' => null, 'plan_status' => 'canceled'])->save();

        $this->postSignedWebhook($event)->assertOk();

        $billable->refresh();
        $this->assertNull($billable->getAttribute('plan'));
        $this->assertSame('canceled', $billable->getAttribute('plan_status'));
        $this->assertSame(1, ProcessedWebhookEvent::query()->where('event_id', 'evt_replay')->count());
    }

    /**
     * A mid-handler failure leaves NO dedup row, so Stripe's retry reprocesses.
     *
     * The mutant is the outer transaction in `processOnce()`: remove it and the
     * dedup insert commits on its own, so a crash mid-flight turns every retry of
     * that event into a permanent no-op. The symptom is a cancelled customer who
     * keeps a paid tier for free, forever, with every happy-path test above still
     * green.
     *
     * The retry is DRIVEN rather than inferred from a row count. A count of zero
     * is the mechanism; a redelivery that actually writes the entitlement is the
     * property.
     */
    public function test_a_mid_handler_failure_leaves_no_dedup_row_so_a_retry_reprocesses(): void
    {
        $billable = $this->createBillable();

        $this->app->bind(
            WritesEntitlement::class,
            fn (): WritesEntitlement => new SwitchableEntitlementWriter(new WriteEntitlement),
        );

        $event = $this->subscriptionEvent('evt_poison', 'customer.subscription.created', 'price_pro', 'active');

        $this->assertSame(500, $this->postSignedWebhook($event)->getStatusCode());

        $this->assertSame(0, ProcessedWebhookEvent::query()->where('event_id', 'evt_poison')->count());

        $billable->refresh();
        $this->assertNull($billable->getAttribute('plan'));

        // Stripe retries the same event id. With the dedup row rolled back it is
        // processed rather than skipped.
        //
        // The recovery is a STATIC FLAG and not a container rebind, because a
        // rebind would not be seen: Illuminate\Routing\Route caches the
        // controller instance it resolved on the first request, so the second
        // delivery in the same test reuses the writer that was injected into it.
        SwitchableEntitlementWriter::$failing = false;

        $this->postSignedWebhook($event)->assertOk();

        $billable->refresh();
        $this->assertSame('pro', $billable->getAttribute('plan'));
        $this->assertSame(1, ProcessedWebhookEvent::query()->where('event_id', 'evt_poison')->count());
    }

    // -------------------------------------------------------------------------
    // Harness
    // -------------------------------------------------------------------------

    /**
     * Swap the bound writer for one that records every claim and then delegates
     * to the real one.
     */
    private function recordEntitlementWrites(): void
    {
        $this->app->bind(
            WritesEntitlement::class,
            fn (): WritesEntitlement => new RecordingEntitlementWriter(new WriteEntitlement),
        );
    }

    /**
     * Re-register the package's routes against a feature set and prefix.
     */
    private function bootRoutes(array $features, string $prefix = ''): void
    {
        config([
            'magic-starter.features' => $features,
            'magic-starter.route_prefix' => $prefix,
        ]);

        $this->app['router']->setRoutes(new RouteCollection);

        (new MagicStarterServiceProvider($this->app))->boot();
    }

    private function matchRoute(string $method, string $uri): ?\Illuminate\Routing\Route
    {
        try {
            return $this->app['router']->getRoutes()->match(Request::create($uri, $method));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return null;
        }
    }

    /**
     * Build the billing schema the way a consumer's `migrate` does.
     *
     * The package's own migrations rather than a hand-written Blueprint, because
     * the COLUMN TYPES are what two of the assertions in this file depend on:
     * `plan_renews` is a real boolean and the three dates are `timestamptz`, and
     * a hand-rolled string column would quietly certify a decode the shipped
     * schema never asks for.
     */
    private function createSchema(): void
    {
        $this->runMigration('create_users_table.php');
        $this->runMigration('add_cashier_customer_columns_to_billable_table.php');
        $this->runMigration('add_entitlement_provenance_to_billable_table.php');
        $this->runMigration('create_subscriptions_table.php');
        $this->runMigration('create_subscription_items_table.php');
        $this->runMigration('create_processed_webhook_events_table.php');

        $this->assertTrue(Schema::hasColumn('users', 'plan_renews'));
    }

    private function runMigration(string $filename): void
    {
        $migration = require __DIR__ . '/../../../database/migrations/' . $filename;

        $migration->up();
    }

    /**
     * The billable acting as the Stripe customer, holding nothing yet.
     */
    private function createBillable(): User
    {
        return User::query()->create([
            'name' => 'Payer',
            'email' => 'payer@example.test',
            'password' => 'secret',
            'stripe_id' => static::STRIPE_ID,
        ]);
    }

    /**
     * Deliver the seed event that both syncs a local Cashier subscription row and
     * puts the billable on the `pro` tier.
     *
     * The invoice handler reads that row's `stripe_price`, so the seed is a real
     * delivery rather than a hand-inserted row: a subscription Cashier never
     * synced is a state this rail cannot reach.
     */
    private function seedActiveSubscription(?int $currentPeriodEnd = null, ?bool $cancelAtPeriodEnd = null): void
    {
        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_seed_' . Str::random(6),
                'customer.subscription.created',
                'price_pro',
                'active',
                currentPeriodEnd: $currentPeriodEnd,
                cancelAtPeriodEnd: $cancelAtPeriodEnd,
            ),
        )->assertOk();
    }

    /**
     * Build a Stripe subscription event payload.
     *
     * `$created` is the event's own timestamp and the only thing that orders one
     * delivery against another, so it is a parameter rather than a constant: a
     * scenario delivering two events to one billable has to make the second
     * strictly newer or the monotonic rule drops it.
     *
     * `$currentPeriodEnd` and `$cancelAtPeriodEnd` are OMITTED from the payload
     * when null rather than written as null. Stripe's absent key is what makes
     * `plan_renews` NULL mean "the rail has not said", which is a different claim
     * from false, so the absent-key path has to be reachable from here.
     *
     * @return array<string, mixed>
     */
    private function subscriptionEvent(
        string $eventId,
        string $type,
        string $priceId,
        string $status,
        int $created = self::EVENT_AT,
        ?int $currentPeriodEnd = null,
        ?bool $cancelAtPeriodEnd = null,
    ): array {
        $object = [
            'id' => 'sub_webhook_test',
            'customer' => static::STRIPE_ID,
            'status' => $status,
            'metadata' => ['type' => 'default'],
            'items' => [
                'data' => [
                    [
                        'id' => 'si_webhook_test',
                        'quantity' => 1,
                        'price' => [
                            'id' => $priceId,
                            'product' => 'prod_webhook_test',
                        ],
                    ],
                ],
            ],
        ];

        // Stripe carries the period end on the subscription ITEM in the current
        // API version, which is where Cashier reads it from too.
        if ($currentPeriodEnd !== null) {
            $object['items']['data'][0]['current_period_end'] = $currentPeriodEnd;
        }

        if ($cancelAtPeriodEnd !== null) {
            $object['cancel_at_period_end'] = $cancelAtPeriodEnd;
        }

        return [
            'id' => $eventId,
            'type' => $type,
            'created' => $created,
            'data' => ['object' => $object],
        ];
    }

    /**
     * Build a Stripe invoice.payment_succeeded event payload.
     *
     * An invoice object carries no subscription items, which is why this builder
     * has no period parameters: the handler has no period to read here.
     *
     * @return array<string, mixed>
     */
    private function invoiceEvent(string $eventId, int $created = self::EVENT_AT): array
    {
        return [
            'id' => $eventId,
            'type' => 'invoice.payment_succeeded',
            'created' => $created,
            'data' => [
                'object' => [
                    'id' => 'in_webhook_test',
                    'customer' => static::STRIPE_ID,
                ],
            ],
        ];
    }

    /**
     * POST a Stripe-signed webhook payload to the package's endpoint.
     *
     * @param  array<string, mixed>  $event
     */
    private function postSignedWebhook(array $event, ?string $secret = null): TestResponse
    {
        $payload = (string) json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? static::WEBHOOK_SECRET);

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

    /**
     * Read a stored date column as a Unix timestamp.
     *
     * Parsed here rather than cast on the fixture, because the fixture casting it
     * would hide the very decode this file exists to pin: the package ships the
     * column and not the model that owns it.
     */
    private function storedTimestamp(Model $billable, string $attribute): ?int
    {
        $stored = $billable->getAttribute($attribute);

        return $stored === null ? null : Carbon::parse($stored)->getTimestamp();
    }

    /**
     * Read a stored boolean column, whatever shape the driver hands back.
     */
    private function storedFlag(Model $billable, string $attribute): ?bool
    {
        $stored = $billable->getAttribute($attribute);

        return $stored === null ? null : (bool) $stored;
    }
}

/**
 * The billable the webhook resolves, with a basename a consumer actually ships.
 *
 * `Str::snake(class_basename($this)) . '_id'` is how Cashier names the relation
 * column and how `create_subscriptions_table.php` derives it, so a fixture named
 * for the test would pin a column no application ever sees.
 *
 * NOTHING here casts the ten provenance columns, deliberately: the package ships
 * those columns and not the model that owns them, so an uncast model is a shape
 * an adopter is entitled to ship and the one this file has to survive.
 */
class User extends ConcreteUser
{
    use Billable;

    protected $table = 'users';
}

/**
 * Records every claim and then hands it to the real write path.
 *
 * A decorator rather than a stub, because both halves are being asserted: that
 * the feeder goes THROUGH the contract, and that the columns the real
 * implementation writes are the ones this rail expects.
 */
class RecordingEntitlementWriter implements WritesEntitlement
{
    /**
     * @var list<EntitlementWrite>
     */
    public static array $claims = [];

    public function __construct(private WritesEntitlement $inner) {}

    public function write(EntitlementWrite $write): bool
    {
        self::$claims[] = $write;

        return $this->inner->write($write);
    }

    public static function reset(): void
    {
        self::$claims = [];
    }
}

/**
 * Fails the way a handler fails halfway through: after the dedup row is claimed
 * and before the entitlement lands, then recovers so the retry can be driven.
 *
 * A bound implementation rather than a partial mock of the controller, because
 * the failure has to happen INSIDE the transaction under test rather than around
 * a method a mock intercepted. The recovery is a static flag rather than a second
 * binding, because `Illuminate\Routing\Route` caches the controller instance it
 * resolved, so a rebind between two deliveries in one test is never seen.
 */
class SwitchableEntitlementWriter implements WritesEntitlement
{
    public static bool $failing = true;

    public function __construct(private WritesEntitlement $inner) {}

    public function write(EntitlementWrite $write): bool
    {
        if (self::$failing) {
            throw new RuntimeException('The handler exploded mid-flight.');
        }

        return $this->inner->write($write);
    }

    public static function reset(): void
    {
        self::$failing = true;
    }
}
