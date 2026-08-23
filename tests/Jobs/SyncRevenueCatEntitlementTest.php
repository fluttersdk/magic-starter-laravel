<?php

namespace FlutterSdk\MagicStarter\Tests\Jobs;

use Carbon\CarbonImmutable;
use FlutterSdk\MagicStarter\Actions\WriteEntitlement;
use FlutterSdk\MagicStarter\Contracts\WritesEntitlement;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Jobs\SyncRevenueCatEntitlement;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\ProcessedWebhookEvent;
use FlutterSdk\MagicStarter\Support\EntitlementWrite;
use FlutterSdk\MagicStarter\Support\RevenueCatClient;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The store rail's feeder, where entitlement is actually decided.
 *
 * Six of RevenueCat's event types reach this job and FOUR of them mean the
 * opposite of what their name suggests to a reader who has not read the docs:
 * `CANCELLATION` means auto-renew was switched off and the customer is still
 * paid up, `BILLING_ISSUE` means a retry is in progress, `SUBSCRIPTION_PAUSED`
 * carries a resume date, and `PRODUCT_CHANGE` announces a change that has NOT
 * happened yet. Each one has a plausible implementation that revokes a tier
 * somebody is paying for, so each gets a test of its own against a billable of
 * its own: two guards on one outcome absorb each other's mutation, and a
 * scenario that trips two of these rules would keep passing with either rule
 * gone.
 *
 * The structural answer to all four is that the job NEVER reads the tier from
 * the event. It re-reads the authoritative subscriber from RevenueCat and maps
 * the tier from that; the event type only decided that the job runs at all. The
 * tests are written to bite an implementation that breaks that rule: the
 * `PRODUCT_CHANGE` payload carries a `new_product_id` mapped to a LOWER tier
 * than the authoritative read shows, so a job that trusted the payload would
 * downgrade a paying billable and fail here.
 *
 * ## The job is driven by DIRECT DISPATCH, not through the webhook
 *
 * The application this was extracted from reached the job by POSTing a signed
 * body to the store endpoint. Here the endpoint's own test owns
 * delivery-to-dispatch, and this file's subject is what the job does once it
 * runs, so every scenario below constructs the event and dispatches. The one
 * exception is the release path at the bottom, which needs a real worker
 * because what broke there was that NOTHING invoked `failed()`.
 *
 * ## The billable casts none of the provenance columns, deliberately
 *
 * The package ships those columns and not the model that owns them, so an
 * uncast model is a shape an adopter is entitled to ship and the one this file
 * has to survive: a feeder reading `plan_provider` straight off a model that
 * casts it to an enum of its own hands an enum instance to a `?string`
 * parameter, which is a TypeError on a revocation path.
 */
class SyncRevenueCatEntitlementTest extends TestCase
{
    /**
     * The API key every test configures. A fake value: the client refuses to
     * call at all without one, and no real key belongs in a public repository.
     */
    protected const API_KEY = 'sk_test_revenuecat_secret';

    /**
     * The store's own management surface, returned by `GET /subscribers` as
     * `subscriber.management_url`. It is the ONLY source `plan_manage_url` has
     * on a store rail, so it is asserted rather than assumed.
     */
    protected const MANAGE_URL = 'https://apps.apple.com/account/subscriptions';

    /**
     * The two products the mapping tests map from, standing in for the ids an
     * adopter creates in App Store Connect and Play Console. The Play one
     * carries the `:base_plan_id` suffix that Play actually sends, because a
     * map keyed on the bare subscription id is an unmapped-product warning on
     * every Android renewal.
     */
    protected const APP_STORE_BUSINESS = 'starter_business_monthly';

    protected const PLAY_PRO = 'starter_pro:monthly';

    /**
     * Configure the application before its providers BOOT.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            // The failed-job store has its OWN connection key, and Testbench
            // points it at `sqlite` while the suite runs on `testing`. Left
            // alone, a failure is recorded into a different database and the
            // assertion that it stayed recorded reads an empty table.
            'queue.failed.database' => 'testing',
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing.billable' => 'user',
            // The adopter's own ranking, cheapest first. The write action reads
            // it, so a scenario that moves a tier has to be decidable.
            'magic-starter.billing.tier_order' => ['free', 'pro', 'business'],
            // The store rail's half of the price map: which rail-native product
            // id sells which of the adopter's tiers.
            'magic-starter.billing.store_products' => [
                self::APP_STORE_BUSINESS => 'business',
                self::PLAY_PRO => 'pro',
            ],
            'magic-starter.billing.revenuecat.secret_api_key' => self::API_KEY,
            'magic-starter.billing.revenuecat.base_url' => RevenueCatClient::DEFAULT_BASE_URL,
            'magic-starter.billing.revenuecat.accept_sandbox' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Every fixture below decides "is this subscription still live" against
        // `now()`, so the clock is pinned to the moment the entitlement on
        // record was granted. Without this, a grace window sixteen days wide is
        // in the future today and in the past next month, and the test would
        // start failing on a date rather than on a change.
        $this->travelTo($this->grantedAt());

        $this->createSchema();
    }

    // -------------------------------------------------------------------------
    // The four event types that do NOT revoke
    // -------------------------------------------------------------------------

    /**
     * `CANCELLATION` leaves the tier and records that the subscription will not
     * renew.
     *
     * The event means auto-renew was switched off, not that access ended: the
     * customer has paid through the end of the period and RevenueCat sends
     * `EXPIRATION` when that period actually runs out. The authoritative read
     * says the same thing in its own vocabulary, `unsubscribe_detected_at` set
     * with `expires_date` still in the future, and that pairing is exactly what
     * a reasonable implementation reads as "cancelled, so revoke".
     */
    public function test_a_cancellation_leaves_the_tier_and_records_that_it_will_not_renew(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription([
                    'unsubscribe_detected_at' => $this->grantedAt()->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->sync($this->event('CANCELLATION', $billable));

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'), 'A cancellation revoked a paid period.');
        $this->assertSame(PlanStatus::ACTIVE->value, $billable->getAttribute('plan_status'));
        $this->assertFalse((bool) $billable->getAttribute('plan_renews'));
        $this->assertSame(self::MANAGE_URL, $billable->getAttribute('plan_manage_url'));
    }

    /**
     * `BILLING_ISSUE` leaves the tier and records the grace window.
     *
     * The store is still retrying the charge. Cutting access off at the first
     * failed charge punishes a customer for an expired card, and the read says
     * the window is open by carrying a future `grace_period_expires_date`.
     */
    public function test_a_billing_issue_leaves_the_tier_and_records_the_grace_expiry(): void
    {
        $grace = $this->grantedAt()->addDays(16);

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription([
                    'billing_issues_detected_at' => $this->grantedAt()->toIso8601ZuluString(),
                    'grace_period_expires_date' => $grace->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->sync($this->event('BILLING_ISSUE', $billable));

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'), 'A dunning window revoked a tier.');
        $this->assertSame(PlanStatus::GRACE->value, $billable->getAttribute('plan_status'));
        $this->assertSame(
            $grace->getTimestamp(),
            CarbonImmutable::parse((string) $billable->getAttribute('plan_grace_period_ends_at'))->getTimestamp(),
        );
    }

    /**
     * `SUBSCRIPTION_PAUSED` leaves the tier while the pause is still pending.
     *
     * Google Play only, and the pause takes effect at the END of the paid
     * period: the read still carries a future `expires_date`, so the customer
     * keeps what they paid for until the `EXPIRATION` that follows.
     */
    public function test_a_subscription_pause_leaves_the_tier(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::PLAY_STORE->value,
        ]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                self::PLAY_PRO => $this->subscription([
                    'store' => 'play_store',
                    'auto_resume_date' => $this->grantedAt()->addMonths(2)->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->sync($this->event('SUBSCRIPTION_PAUSED', $billable));

        $billable->refresh();
        $this->assertSame('pro', $billable->getAttribute('plan'), 'A pending pause revoked a period already paid for.');
        $this->assertSame(PlanStatus::ACTIVE->value, $billable->getAttribute('plan_status'));
    }

    /**
     * `PRODUCT_CHANGE` leaves the tier the authoritative read still shows.
     *
     * The event announces a change that starts at the NEXT renewal, and its
     * payload names the new product. This is the scenario that bites a job which
     * trusts the event: `new_product_id` here is mapped to the LOWER tier, so an
     * implementation reading it downgrades a billable nobody downgraded.
     */
    public function test_a_product_change_leaves_the_tier_the_authoritative_read_still_shows(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription(),
            ]),
        ]);

        $this->sync($this->event('PRODUCT_CHANGE', $billable, [
            'new_product_id' => self::PLAY_PRO,
            'product_id' => self::APP_STORE_BUSINESS,
        ]));

        $billable->refresh();
        $this->assertSame(
            'business',
            $billable->getAttribute('plan'),
            'The job read the tier out of the event instead of out of the authoritative response.',
        );
        $this->assertSame(self::APP_STORE_BUSINESS, $billable->getAttribute('plan_product_id'));
    }

    // -------------------------------------------------------------------------
    // What the authoritative read is allowed to decide
    // -------------------------------------------------------------------------

    /**
     * A subscriber holding NOTHING AT ALL still revokes a store-granted tier.
     *
     * The distinction this makes against the expiration test below is the whole
     * point: there, the read still returns the subscription, merely expired, so
     * the feeder's own set is non-empty. Here the read comes back with no
     * subscriptions whatever, which is what an account whose purchase was
     * removed outright looks like.
     *
     * `claimFor()` guards that case with `$owned === [] && $dated !== []`, and
     * the second half is the load-bearing one: dropping it returns early on an
     * empty set and the walk never reaches the revocation path, so a customer
     * whose store purchase is gone keeps their paid tier forever. Measured, with
     * that half removed, the only tests that go red are the two asserting the
     * feeder must not revoke ANOTHER rail's plan, both of which pass for the
     * opposite reason (they want the early return). This case is what tells the
     * two apart.
     */
    public function test_a_subscriber_holding_nothing_at_all_revokes_a_store_granted_tier(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([]),
        ]);

        $this->sync($this->event('EXPIRATION', $billable));

        $billable->refresh();
        $this->assertNull(
            $billable->getAttribute('plan'),
            'A store rail that shows no subscriptions at all has nothing left to grant, and it '
            . 'granted this tier, so it is the rail entitled to take it back.',
        );
        // EXPIRED and not CANCELED, which is the second thing this case reports.
        // A cancellation is a customer who asked to stop, and the read says so
        // through `unsubscribe_detected_at` on the subscription. There is no
        // subscription here to carry that, so the honest word is that the tier
        // ran out, not that anybody cancelled it. The expiry test below sets
        // that field and gets CANCELED, which is what makes the pair a
        // distinction rather than a duplicate.
        $this->assertSame(PlanStatus::EXPIRED->value, $billable->getAttribute('plan_status'));
    }

    /**
     * `EXPIRATION` revokes, because the read shows nothing live.
     */
    public function test_an_expiration_revokes_the_tier(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription([
                    'expires_date' => $this->grantedAt()->subDay()->toIso8601ZuluString(),
                    'unsubscribe_detected_at' => $this->grantedAt()->subMonth()->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->sync($this->event('EXPIRATION', $billable));

        $billable->refresh();
        $this->assertNull($billable->getAttribute('plan'));
        $this->assertSame(PlanStatus::CANCELED->value, $billable->getAttribute('plan_status'));
        $this->assertSame(BillingProvider::APP_STORE->value, $billable->getAttribute('plan_provider'));
        $this->assertFalse((bool) $billable->getAttribute('plan_renews'));
    }

    /**
     * A live purchase writes the tier its product maps to, THROUGH the contract.
     *
     * The recorded claim is the half a column assertion cannot make: a feeder
     * that force-filled the columns itself leaves an identical row behind while
     * bypassing every ordering rule that decides whether the row should have
     * changed at all. The Play product also pins the `:base_plan_id` key shape,
     * because a map keyed on the bare subscription id warns on every Android
     * renewal instead of granting.
     */
    public function test_an_initial_purchase_grants_through_the_contract(): void
    {
        $this->recordEntitlementWrites();

        $billable = $this->makeBillable([]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                self::PLAY_PRO => $this->subscription(['store' => 'play_store']),
            ]),
        ]);

        $this->sync($this->event('INITIAL_PURCHASE', $billable));

        $this->assertCount(1, RecordingEntitlementWriter::$claims);

        $claim = RecordingEntitlementWriter::$claims[0];
        $this->assertTrue($claim->billable->is($billable));
        $this->assertSame('pro', $claim->plan);
        $this->assertSame(PlanStatus::ACTIVE, $claim->status);
        $this->assertSame(BillingProvider::PLAY_STORE, $claim->provider);
        $this->assertTrue($claim->authoritative, 'A re-read of the rail is the rail speaking, not a projection.');
        $this->assertSame('INITIAL_PURCHASE', $claim->providerStatus);
        $this->assertSame(self::PLAY_PRO, $claim->productId);
        $this->assertSame($this->eventAt()->getTimestampMs(), $claim->eventAt->getTimestampMs());

        $billable->refresh();
        $this->assertSame('pro', $billable->getAttribute('plan'));
        $this->assertSame(self::PLAY_PRO, $billable->getAttribute('plan_product_id'));
    }

    /**
     * A trial reaches the neutral vocabulary as a trial rather than as active.
     */
    public function test_a_trial_period_is_recorded_as_trialing(): void
    {
        $billable = $this->makeBillable([]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription(['period_type' => 'trial']),
            ]),
        ]);

        $this->sync($this->event('INITIAL_PURCHASE', $billable));

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
        $this->assertSame(PlanStatus::TRIALING->value, $billable->getAttribute('plan_status'));
    }

    /**
     * An unmapped product warns and writes nothing.
     *
     * A config gap is not a reason to revoke, and this is the branch every event
     * lands on before an adopter has filled `billing.store_products` in: if it
     * downgraded anybody, going live would be a mass revocation.
     */
    public function test_a_live_subscription_whose_product_is_unmapped_warns_and_writes_nothing(): void
    {
        Log::spy();

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                'starter_unmapped_monthly' => $this->subscription(),
            ]),
        ]);

        $this->sync($this->event('RENEWAL', $billable));

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));

        $this->assertWarned([
            'reason' => 'unmapped_product',
            'billable_id' => $billable->getKey(),
            'product_id' => 'starter_unmapped_monthly',
        ]);
    }

    /**
     * A `TRANSFER` re-reads BOTH sides: the source loses the subscription and
     * the destination gains it.
     *
     * Syncing only the destination leaves a billable paying for a subscription
     * that now funds somebody else.
     */
    public function test_a_transfer_revokes_the_source_and_grants_the_destination(): void
    {
        $source = $this->makeBillable([
            'email' => 'source@example.test',
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);

        $destination = $this->makeBillable(['email' => 'destination@example.test']);

        $this->fakeAuthoritativeReads([
            (string) $source->getKey() => $this->subscriber([]),
            (string) $destination->getKey() => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription(),
            ]),
        ]);

        $this->sync($this->event('TRANSFER', $source, [
            'app_user_id' => null,
            'transferred_from' => [(string) $source->getKey()],
            'transferred_to' => [(string) $destination->getKey()],
        ]));

        $source->refresh();
        $destination->refresh();

        $this->assertNull($source->getAttribute('plan'), 'The source kept a subscription it no longer funds.');
        $this->assertSame(PlanStatus::EXPIRED->value, $source->getAttribute('plan_status'));
        $this->assertSame('business', $destination->getAttribute('plan'));
    }

    /**
     * A family-shared entitlement is granted, and warned about.
     *
     * The access is real and the store granted it, so refusing it would deny a
     * tier the customer genuinely has; the subject holding it is not the one
     * whose owner paid, which is worth an operator's attention.
     */
    public function test_a_family_shared_entitlement_grants_and_warns(): void
    {
        Log::spy();

        $billable = $this->makeBillable([]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription(['ownership_type' => 'FAMILY_SHARED']),
            ]),
        ]);

        $this->sync($this->event('INITIAL_PURCHASE', $billable));

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));

        $this->assertWarned([
            'reason' => 'family_shared_entitlement',
            'billable_id' => $billable->getKey(),
        ]);
    }

    // -------------------------------------------------------------------------
    // The sandbox gate, which is the one between a developer account and money
    // -------------------------------------------------------------------------

    /**
     * A sandbox purchase grants nothing while the deployment refuses sandboxes.
     *
     * The product is MAPPED and the subscription is LIVE, so nothing but the
     * per-subscription `is_sandbox` flag stands between a developer account and
     * a real paid tier. It must not revoke either: a sandbox purchase is no
     * evidence that a production tier ended.
     */
    public function test_a_sandbox_only_subscriber_writes_nothing_and_warns(): void
    {
        Log::spy();

        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription(['is_sandbox' => true]),
            ]),
        ]);

        $this->sync($this->event('INITIAL_PURCHASE', $billable));

        $billable->refresh();
        $this->assertSame('pro', $billable->getAttribute('plan'), 'A sandbox purchase moved a real tier.');
        $this->assertSame(PlanStatus::ACTIVE->value, $billable->getAttribute('plan_status'));

        $this->assertWarned([
            'reason' => 'sandbox_only_subscriber',
            'billable_id' => $billable->getKey(),
        ]);
    }

    /**
     * The gate reads the PACKAGE's config key, and only widens what is accepted.
     *
     * The same fixture as the test above, with `accept_sandbox` on. Without this
     * limb a gate reading a key that no longer exists passes every other test in
     * this file: it fails closed, so no money leaves, and
     * `REVENUECAT_ACCEPT_SANDBOX=true` is silently dead for the adopter who set
     * it. The two tests together say the flag is read, and read from here.
     */
    public function test_accept_sandbox_widens_the_gate_and_is_read_from_the_package_key(): void
    {
        config(['magic-starter.billing.revenuecat.accept_sandbox' => true]);

        $billable = $this->makeBillable([]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription(['is_sandbox' => true]),
            ]),
        ]);

        $this->sync($this->event('INITIAL_PURCHASE', $billable));

        $billable->refresh();
        $this->assertSame(
            'business',
            $billable->getAttribute('plan'),
            'accept_sandbox is not read from magic-starter.billing.revenuecat, so it can never widen anything.',
        );
    }

    /**
     * A sandbox subscription does not hide the production one behind it.
     *
     * The sandbox entry reaches further into the future, so a filter applied to
     * the ranked WINNER rather than to the whole set would refuse the winner and
     * never look at the live production purchase underneath.
     */
    public function test_a_sandbox_subscription_does_not_mask_a_production_purchase(): void
    {
        $billable = $this->makeBillable([]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                'starter_sandbox_monthly' => $this->subscription([
                    'is_sandbox' => true,
                    'expires_date' => $this->grantedAt()->addYear()->toIso8601ZuluString(),
                ]),
                self::APP_STORE_BUSINESS => $this->subscription(),
            ]),
        ]);

        $this->sync($this->event('RENEWAL', $billable));

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
    }

    // -------------------------------------------------------------------------
    // Refunds, which the vendor documents as not ending a subscription
    // -------------------------------------------------------------------------

    /**
     * A refund alone does NOT take the tier away.
     *
     * RevenueCat's own documentation: a refund can be given without cancelling a
     * subscription, and the current status is what says whether it is still
     * active. This test exists because reading `refunded_at` as "not live" was
     * tried, and it is the one place in the job where an ambiguity would resolve
     * toward taking a tier from somebody who may still be paying.
     */
    public function test_a_refund_alone_does_not_take_the_tier_away(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription([
                    'refunded_at' => $this->grantedAt()->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->sync($this->event('CANCELLATION', $billable));

        $billable->refresh();
        $this->assertSame(
            'business',
            $billable->getAttribute('plan'),
            'A refund with a future expiry is a state the vendor documents as live.',
        );
    }

    /**
     * A refund that really did end the subscription revokes, on the dates.
     *
     * The control on the test above: the field is still read where it only NAMES
     * a revocation the dates already decided.
     */
    public function test_a_refund_that_expired_the_subscription_revokes_as_canceled(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription([
                    'expires_date' => $this->grantedAt()->subDay()->toIso8601ZuluString(),
                    'refunded_at' => $this->grantedAt()->subDay()->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->sync($this->event('EXPIRATION', $billable));

        $billable->refresh();
        $this->assertNull($billable->getAttribute('plan'));
        $this->assertSame(PlanStatus::CANCELED->value, $billable->getAttribute('plan_status'));
    }

    // -------------------------------------------------------------------------
    // Which subscriber, and which billable
    // -------------------------------------------------------------------------

    /**
     * An App User ID that cannot be a billable key never reaches the read.
     *
     * The shape check is what keeps a malformed value out of a `where id = ?` on
     * a PostgreSQL `uuid` column, where it is a 500 and not the clean null
     * SQLite answers with.
     */
    public function test_an_app_user_id_that_is_not_a_billable_key_never_reaches_the_authoritative_read(): void
    {
        Log::spy();

        Http::fake();

        $this->sync($this->event('RENEWAL', $this->makeBillable([]), [
            'app_user_id' => 'not-a-key',
            'aliases' => [],
            'original_app_user_id' => null,
        ]));

        Http::assertNothingSent();

        $this->assertWarned([
            'reason' => 'malformed_app_user_id',
            'app_user_id' => 'not-a-key',
        ]);
    }

    /**
     * One alias can stand in for an anonymous last-seen id.
     *
     * `app_user_id` is the LAST SEEN id, so a subscriber who bought before the
     * SDK's `logIn` ran arrives with an anonymous id while the real key sits in
     * `aliases`. Refusing that leaves a paying customer on free with no
     * self-serve recovery, because the store will not resell what they own.
     */
    public function test_one_alias_can_stand_in_for_an_anonymous_last_seen_id(): void
    {
        $billable = $this->makeBillable([]);

        $this->fakeAuthoritativeReads([
            '$RCAnonymousID:8f3a' => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription(),
            ]),
        ]);

        $this->sync($this->event('INITIAL_PURCHASE', $billable, [
            'app_user_id' => '$RCAnonymousID:8f3a',
            'aliases' => ['$RCAnonymousID:8f3a', (string) $billable->getKey()],
        ]));

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
    }

    /**
     * Two candidate aliases are refused rather than guessed.
     *
     * An owner who moves between two of their own subjects on one device makes
     * both keys aliases of one subscriber, and "the first alias that parses"
     * would hand one subject's subscription to the other, silently.
     */
    public function test_two_candidate_aliases_are_refused_rather_than_guessed(): void
    {
        Log::spy();

        Http::fake();

        $first = $this->makeBillable(['email' => 'first@example.test']);
        $second = $this->makeBillable(['email' => 'second@example.test']);

        $this->sync($this->event('INITIAL_PURCHASE', $first, [
            'app_user_id' => '$RCAnonymousID:8f3a',
            'aliases' => [(string) $first->getKey(), (string) $second->getKey()],
        ]));

        Http::assertNothingSent();

        $first->refresh();
        $second->refresh();
        $this->assertNull($first->getAttribute('plan'));
        $this->assertNull($second->getAttribute('plan'));

        // `atLeast()` rather than the exact-once helper below, because refusing
        // the aliases leaves the id itself unresolvable and the job says so a
        // second time. Both warnings are the same refusal.
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return ($context['reason'] ?? null) === 'ambiguous_aliases';
            })
            ->atLeast()
            ->once();
    }

    /**
     * An alias never stands in for a TRANSFER side.
     *
     * The two sides are different subscribers, so substituting the event's own
     * alias for one of them reads one side's subscriber and claims it against
     * the other side's billable: a revocation against the subject that just
     * gained the subscription.
     */
    public function test_an_alias_never_stands_in_for_a_transfer_side(): void
    {
        Log::spy();

        $destination = $this->makeBillable(['email' => 'destination@example.test']);

        $this->fakeAuthoritativeReads([
            (string) $destination->getKey() => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription(),
            ]),
        ]);

        $this->sync($this->event('TRANSFER', $destination, [
            'app_user_id' => null,
            'transferred_from' => ['$RCAnonymousID:8f3a'],
            'transferred_to' => [(string) $destination->getKey()],
            'aliases' => [(string) $destination->getKey()],
        ]));

        $destination->refresh();
        $this->assertSame('business', $destination->getAttribute('plan'), 'The destination lost what it just gained.');

        $this->assertWarned([
            'reason' => 'malformed_app_user_id',
            'app_user_id' => '$RCAnonymousID:8f3a',
        ]);
    }

    /**
     * A padded App User ID still finds its billable.
     *
     * The id is trimmed for the lookup and for the subscriber URL both, and the
     * event's own subscriber is still recognised as its own: untrimmed it
     * compares unequal to its loop entry, the alias fallback is refused, and the
     * refusal says "transferred" about an id nothing transferred.
     */
    public function test_a_padded_app_user_id_still_finds_its_billable(): void
    {
        $billable = $this->makeBillable([]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription(),
            ]),
        ]);

        $this->sync($this->event('INITIAL_PURCHASE', $billable, [
            'app_user_id' => '  ' . $billable->getKey() . ' ',
        ]));

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
    }

    /**
     * An App User ID with no billable behind it writes nothing.
     */
    public function test_an_unknown_billable_writes_nothing(): void
    {
        Log::spy();

        Http::fake();

        $orphan = (string) Str::uuid();

        $this->sync($this->event('RENEWAL', $this->makeBillable([]), ['app_user_id' => $orphan]));

        Http::assertNothingSent();

        $this->assertWarned([
            'reason' => 'unknown_billable',
            'app_user_id' => $orphan,
        ]);
    }

    // -------------------------------------------------------------------------
    // Which rail may revoke, and what an unreadable response may decide
    // -------------------------------------------------------------------------

    /**
     * This feeder does not revoke a plan another rail granted.
     *
     * The write action would drop the attempt anyway, and a claim nobody is
     * entitled to make is better not made. The mutant is a `revokingProvider()`
     * that falls back to the stored rail whatever it is, which would let a store
     * expiry cancel a card subscription that is still being billed.
     */
    public function test_a_card_granted_plan_is_not_revoked_by_the_store_feeder(): void
    {
        Log::spy();

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
        ]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([]),
        ]);

        $this->sync($this->event('EXPIRATION', $billable));

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'), 'A store expiry revoked a card plan.');
        $this->assertSame(BillingProvider::STRIPE->value, $billable->getAttribute('plan_provider'));

        $this->assertWarned([
            'reason' => 'nothing_to_revoke',
            'billable_id' => $billable->getKey(),
            'stored_provider' => BillingProvider::STRIPE->value,
        ]);
    }

    /**
     * The same refusal on a billable that CASTS the provenance column.
     *
     * The package ships the column and not the model that owns it, so an adopter
     * casting `plan_provider` to an enum of their own is a shape this rail has to
     * survive. Read straight off such a model the value is an enum instance where
     * `BillingProvider::fromWire()` wants a `?string`, which is a TypeError on the
     * revocation path and reachable from a store expiry alone. The uncast test
     * above cannot see it: a raw string satisfies both readers.
     */
    public function test_a_cast_provenance_column_is_still_decoded_on_the_revocation_path(): void
    {
        MagicStarter::useUserModel(CastingUser::class);

        $billable = CastingUser::query()->create([
            'name' => 'Payer',
            'email' => 'casting@example.test',
            'password' => 'secret',
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_source_event_at' => $this->grantedAt(),
        ]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([]),
        ]);

        $this->sync($this->event('EXPIRATION', $billable));

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
        $this->assertSame(BillingProvider::STRIPE, $billable->getAttribute('plan_provider'));
    }

    /**
     * A subscription from a store this feeder does not own is ignored, and does
     * not mask the store purchase behind it.
     *
     * The promotional grant reaches furthest into the future, so a check applied
     * to the ranked winner would refuse the winner and leave a live App Store
     * purchase unclaimed.
     */
    public function test_an_unowned_store_does_not_mask_the_purchase_behind_it(): void
    {
        Log::spy();

        $billable = $this->makeBillable([]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                'starter_promotional' => $this->subscription([
                    'store' => 'promotional',
                    'expires_date' => $this->grantedAt()->addYear()->toIso8601ZuluString(),
                ]),
                self::APP_STORE_BUSINESS => $this->subscription(),
            ]),
        ]);

        $this->sync($this->event('RENEWAL', $billable));

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));

        $this->assertWarned([
            'reason' => 'unfed_store',
            'ignored_product_ids' => ['starter_promotional'],
        ]);
    }

    /**
     * A subscription with no readable date writes nothing rather than expiring.
     */
    public function test_an_undated_subscription_writes_nothing_and_warns(): void
    {
        Log::spy();

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription([
                    'expires_date' => null,
                    'grace_period_expires_date' => null,
                ]),
            ]),
        ]);

        $this->sync($this->event('RENEWAL', $billable));

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));

        $this->assertWarned([
            'reason' => 'undated_subscriptions',
            'billable_id' => $billable->getKey(),
        ]);
    }

    /**
     * A failed authoritative read RAISES and writes nothing.
     *
     * The queue then retries. The alternative, reading a 503 as "nothing is
     * owed", revokes every paying subscriber the moment RevenueCat has an
     * outage.
     */
    public function test_a_failing_authoritative_read_throws_and_writes_nothing(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);

        Http::fake(fn (): PromiseInterface => Http::response(['message' => 'upstream is down'], 503));

        $this->expectException(RequestException::class);

        try {
            $this->sync($this->event('RENEWAL', $billable));
        } finally {
            $billable->refresh();
            $this->assertSame('business', $billable->getAttribute('plan'));
        }
    }

    // -------------------------------------------------------------------------
    // The queue contract, and the claim a permanent failure has to release
    // -------------------------------------------------------------------------

    /**
     * The job rides the queue an adopter's worker drains without being told to.
     *
     * A package-owned queue name would need a supervisor entry on infrastructure
     * this package does not own, and a queue with no worker behind it is a job
     * that dispatches successfully and never runs.
     */
    public function test_the_job_is_queued_on_default(): void
    {
        $job = new SyncRevenueCatEntitlement([]);

        $this->assertSame('default', SyncRevenueCatEntitlement::QUEUE);
        $this->assertSame('default', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff());
    }

    /**
     * A permanently failed sync RELEASES the event id the webhook claimed.
     *
     * Driven through a real worker on the database driver, because what broke
     * here was not the method's body: it was that NOTHING invoked it. The retry
     * budget is spent first, because the job's own `$tries` beats any flag on
     * the command line and one `--once` run merely RELEASES the job with
     * `available_at` a minute out, which looks exactly like a broken release.
     *
     * Without this the endpoint answers 200 to every remaining delivery of the
     * same id having done nothing, and a dropped `INITIAL_PURCHASE` is the one
     * failure this rail cannot recover from on its own.
     */
    public function test_a_permanently_failed_sync_releases_the_event_id_the_webhook_claimed(): void
    {
        Log::spy();

        $billable = $this->makeBillable([]);
        $event = $this->event('INITIAL_PURCHASE', $billable);

        // Claimed the way the endpoint claims it, under the job's own prefix:
        // one unique column serves two senders whose id spaces are unrelated.
        ProcessedWebhookEvent::recordIfNew(
            SyncRevenueCatEntitlement::CLAIM_PREFIX . $event['id'],
            (string) $event['type'],
        );

        Http::fake(fn (): PromiseInterface => Http::response(['message' => 'upstream is down'], 503));

        config(['queue.default' => 'database']);
        dispatch(new SyncRevenueCatEntitlement($event));

        $this->assertSame(1, $this->queuedJobs());

        $this->exhaustTheRetryBudget();

        $this->assertSame(
            0,
            ProcessedWebhookEvent::query()->count(),
            'The claim survived a permanently failed sync, so every redelivery is now a no-op.',
        );
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'The failure must stay recorded.');

        $this->assertWarned(['reason' => 'released_burnt_event_id', 'released' => 1]);
    }

    /**
     * A TRANSIENT failure keeps the claim, because the redelivery would race the
     * retry.
     *
     * The control on the test above, and the reason its fixture spends the
     * budget first: one worker pass releases the job for another attempt, and a
     * release of the dedup row there would let RevenueCat's next delivery queue
     * a second re-read of the same event alongside the one still pending.
     */
    public function test_a_retryable_failure_keeps_the_claim(): void
    {
        $billable = $this->makeBillable([]);
        $event = $this->event('INITIAL_PURCHASE', $billable);

        ProcessedWebhookEvent::recordIfNew(
            SyncRevenueCatEntitlement::CLAIM_PREFIX . $event['id'],
            (string) $event['type'],
        );

        Http::fake(fn (): PromiseInterface => Http::response(['message' => 'upstream is down'], 503));

        config(['queue.default' => 'database']);
        dispatch(new SyncRevenueCatEntitlement($event));

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--queue' => SyncRevenueCatEntitlement::QUEUE,
        ])->assertExitCode(0);

        $this->assertSame(1, ProcessedWebhookEvent::query()->count(), 'A retryable failure burnt the claim.');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, $this->queuedJobs(), 'The job was not released for another attempt.');
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * Run one webhook event through the job the way the queue does, with the
     * client and the write path resolved from the container.
     *
     * @param  array<string, mixed>  $event
     */
    protected function sync(array $event): void
    {
        dispatch_sync(new SyncRevenueCatEntitlement($event));
    }

    /**
     * Record every claim on its way to the real write path.
     */
    protected function recordEntitlementWrites(): void
    {
        RecordingEntitlementWriter::reset();

        $this->app->bind(
            WritesEntitlement::class,
            fn (): WritesEntitlement => new RecordingEntitlementWriter(new WriteEntitlement),
        );
    }

    /**
     * Spend the queued re-read's whole retry budget, then let a real worker
     * decide what that means.
     *
     * The budget is read out of the queued PAYLOAD rather than written as a
     * literal `3`: it is the same `maxTries` the worker will compare against, so
     * widening `$tries` cannot leave this fixture quietly one attempt short and
     * start exercising `handle()` instead of the fail path. The reserve
     * `queue:work` is about to make adds one attempt, so a row already at
     * `maxTries` is a job whose last attempt has been made.
     */
    protected function exhaustTheRetryBudget(): void
    {
        $queued = DB::table('jobs')->first();

        $this->assertNotNull($queued, 'Nothing is queued, so there is no retry budget to spend.');

        $payload = (array) json_decode((string) $queued->payload, true, 512, JSON_THROW_ON_ERROR);
        $maxTries = $payload['maxTries'] ?? null;

        $this->assertIsInt(
            $maxTries,
            'The queued job publishes no retry budget, so the worker would run it rather than refuse it.',
        );

        DB::table('jobs')->where('id', $queued->id)->update(['attempts' => $maxTries]);

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--queue' => SyncRevenueCatEntitlement::QUEUE,
        ])->assertExitCode(0);
    }

    /**
     * How many jobs are waiting on the database queue.
     */
    protected function queuedJobs(): int
    {
        return DB::table('jobs')->count();
    }

    /**
     * A RevenueCat webhook event, carrying only the fields this job is allowed
     * to read plus the ones it must ignore.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function event(string $type, Model $billable, array $overrides = []): array
    {
        return [
            'id' => 'rc-' . Str::uuid()->toString(),
            'type' => $type,
            'app_user_id' => (string) $billable->getKey(),
            'event_timestamp_ms' => $this->eventAt()->getTimestampMs(),
            'store' => 'APP_STORE',
            'environment' => 'PRODUCTION',
            ...$overrides,
        ];
    }

    /**
     * A `GET /subscribers/{app_user_id}` body, in the shape the API documents.
     *
     * @param  array<string, array<string, mixed>>  $subscriptions
     * @return array<string, mixed>
     */
    protected function subscriber(array $subscriptions): array
    {
        return [
            'subscriber' => [
                'original_app_user_id' => 'irrelevant-to-this-job',
                'first_seen' => $this->grantedAt()->subYear()->toIso8601ZuluString(),
                'management_url' => self::MANAGE_URL,
                'subscriptions' => $subscriptions,
                'entitlements' => [],
                'non_subscriptions' => [],
            ],
        ];
    }

    /**
     * One `subscriber.subscriptions.<product_id>` entry with every field this
     * job reads present, so a test states the value it varies and nothing else.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function subscription(array $overrides = []): array
    {
        return [
            'expires_date' => $this->periodEnd()->toIso8601ZuluString(),
            'grace_period_expires_date' => null,
            'unsubscribe_detected_at' => null,
            'billing_issues_detected_at' => null,
            'refunded_at' => null,
            'auto_resume_date' => null,
            'is_sandbox' => false,
            'store' => 'app_store',
            'period_type' => 'normal',
            'ownership_type' => 'PURCHASED',
            'purchase_date' => $this->grantedAt()->subMonth()->toIso8601ZuluString(),
            ...$overrides,
        ];
    }

    /**
     * Answer `GET /subscribers/{id}` from a per-app-user-id table.
     *
     * An id the test did not name answers 404 rather than an empty subscriber:
     * an empty body is the one answer that could quietly revoke, so a job asking
     * for the wrong subscriber has to fail loudly instead of passing.
     *
     * @param  array<string, array<string, mixed>>  $byAppUserId
     */
    protected function fakeAuthoritativeReads(array $byAppUserId): void
    {
        Http::fake(function (Request $request) use ($byAppUserId): PromiseInterface {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $appUserId = urldecode(basename($path));

            if (! array_key_exists($appUserId, $byAppUserId)) {
                return Http::response(['message' => "unexpected app_user_id [{$appUserId}]"], 404);
            }

            return Http::response($byAppUserId[$appUserId], 200);
        });
    }

    /**
     * Assert exactly one warning carried these context fields.
     *
     * `once()` is load bearing: it is what shows the run did not ALSO warn for
     * another reason, which a spy asserting only the fields it cares about would
     * never notice.
     *
     * @param  array<string, mixed>  $context
     */
    protected function assertWarned(array $context): void
    {
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $actual) use ($context): bool {
                foreach ($context as $field => $value) {
                    if (($actual[$field] ?? null) !== $value) {
                        return false;
                    }
                }

                return true;
            });
    }

    /**
     * When the entitlement on record was granted.
     */
    protected function grantedAt(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-22 12:00:00');
    }

    /**
     * The incoming event's own timestamp, strictly newer than the one on record
     * so the write action's monotonic rule cannot be what decides these tests.
     */
    protected function eventAt(): CarbonImmutable
    {
        return $this->grantedAt()->addMinutes(5);
    }

    /**
     * A period end a month past the pinned clock, i.e. comfortably live.
     */
    protected function periodEnd(): CarbonImmutable
    {
        return $this->grantedAt()->addMonth();
    }

    /**
     * A billable carrying whatever entitlement provenance the scenario needs.
     *
     * `plan_source_event_at` is stamped at the pinned clock rather than left
     * null, so every scenario's event is strictly newer than what is on record
     * and the monotonic rule is never the thing that decided the outcome.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeBillable(array $attributes): ConcreteUser
    {
        $model = MagicStarter::billableModel();

        $this->assertSame(ConcreteUser::class, $model, 'The billable subject is not the fixture this file writes.');

        $billable = ConcreteUser::query()->create([
            'name' => 'Payer',
            'email' => 'payer-' . Str::random(8) . '@example.test',
            'password' => 'secret',
            'plan_source_event_at' => $this->grantedAt(),
            ...$attributes,
        ]);

        $this->assertInstanceOf(ConcreteUser::class, $billable);

        return $billable;
    }

    /**
     * Build the billing schema the way a consumer's `migrate` does, plus the
     * framework's own queue tables.
     *
     * The package's own migrations rather than a hand-written Blueprint, because
     * the COLUMN TYPES are what the provenance assertions depend on:
     * `plan_renews` is a real boolean and the three dates are `timestampTz`, and
     * a hand-rolled string column would quietly certify a decode the shipped
     * schema never asks for. The queue tables are the framework's, so they are
     * taken from Testbench's own migration rather than restated here.
     */
    private function createSchema(): void
    {
        $this->runPackageMigration('create_users_table.php');
        $this->runPackageMigration('add_entitlement_provenance_to_billable_table.php');
        $this->runPackageMigration('create_processed_webhook_events_table.php');

        $queueTables = require __DIR__ . '/../../vendor/orchestra/testbench-core/laravel/migrations/'
            . '0001_01_01_000002_testbench_create_jobs_table.php';

        $queueTables->up();
    }

    private function runPackageMigration(string $filename): void
    {
        $migration = require __DIR__ . '/../../database/migrations/' . $filename;

        $migration->up();
    }
}

/**
 * A billable that CASTS the rail column, which an adopter is free to do.
 *
 * The package owns the column and not the model, so both shapes are legitimate
 * and every reader in the package has to answer the same for either.
 */
class CastingUser extends ConcreteUser
{
    protected $table = 'users';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'plan_provider' => BillingProvider::class,
    ];
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
