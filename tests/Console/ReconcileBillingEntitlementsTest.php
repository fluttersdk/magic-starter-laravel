<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use Carbon\CarbonImmutable;
use FlutterSdk\MagicStarter\Console\ReconcileBillingEntitlements;
use FlutterSdk\MagicStarter\Contracts\WritesEntitlement;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Support\RevenueCatClient;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\Support\FeederInvariantWriter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * Locks the sweep that heals a dropped webhook, and the schedule that runs it.
 *
 * ## Why one run is not a test
 *
 * The command's whole job is to write only when a rail DISAGREES with the
 * record, and a single run cannot tell "converged" apart from "re-stamps
 * everything on every run". Those are the same observable outcome after one
 * pass, and the second one is a defect that costs a real customer their tier:
 * every sweep would put its own provenance over a genuine event's, the write
 * path's monotonic rule would then drop the NEXT genuine delivery as stale, and
 * the drift the sweep exists to heal would become permanent. So the agreement
 * cases here run the command TWICE and assert `plan_source_event_at` never
 * moved.
 *
 * ## The billable casts nothing, deliberately, and one of them casts everything
 *
 * The package ships the ten provenance columns and NOT the model that owns them,
 * so both shapes are an adopter's to choose and this file has to survive both.
 * They fail differently, which is why both are here:
 *
 *  - UNCAST is the shape {@see ReconcileBillingEntitlements::snapshot()} is
 *    dangerous in. Read straight off the model, `plan` is a string that
 *    `?->value` answers null for (a warning), `plan_current_period_end` is a
 *    string that `?->toIso8601ZuluString()` is a FATAL Error on, and
 *    `plan_renews` is SQLite's `1`, which `===` compares unequal to `true`
 *    forever. The third is the quiet one: the row agrees perfectly and the
 *    comparison says it does not, which is exactly the restamp loop above.
 *  - CAST is the shape the two `fromWire()` reads are dangerous in: an adopter
 *    casting `plan_provider` to an enum of their own hands an enum instance to a
 *    `?string` parameter, which is a TypeError on a scheduled fleet walk.
 *
 * ## The rails are asserted separately because they are read differently
 *
 * The STORE rail re-reads RevenueCat and applies what it finds unconditionally,
 * so its provenance moves on every run BY DESIGN and the no-op assertion for it
 * is "0 corrected" rather than an unmoved timestamp. The STRIPE rail projects a
 * local Cashier row, which is no fresher than the delivery that wrote it, so it
 * claims only on disagreement and the unmoved timestamp IS the assertion.
 */
class ReconcileBillingEntitlementsTest extends TestCase
{
    /**
     * The API key every store-rail scenario configures. A fake value: the client
     * refuses to call at all without one, and no real key belongs in a public
     * repository.
     */
    protected const API_KEY = 'sk_test_revenuecat_secret';

    /**
     * The store's own management surface, which is the only source
     * `plan_manage_url` has on a store rail.
     */
    protected const MANAGE_URL = 'https://apps.apple.com/account/subscriptions';

    /**
     * The App Store product the store scenarios buy.
     */
    protected const APP_STORE_BUSINESS = 'starter_business_monthly';

    /**
     * Whether the billing feature is on for the test currently running.
     *
     * A PROPERTY rather than a config write in the attribute callback, and the
     * ordering is why. Testbench runs `#[DefineEnvironment]` callbacks BEFORE
     * `defineEnvironment()` (`TestingFeature::run()` defers the default resolver
     * to its last line), so a callback writing `magic-starter.features` would be
     * overwritten a moment later by the class-wide setup below. The flag is read
     * there instead, which is the one place that cannot be undone by ordering.
     */
    private bool $billingEnabled = true;

    /**
     * Configure the application before its providers BOOT.
     *
     * `use_uuids` is FALSE here, against the package's own default, and that is
     * the one setting this file needs rather than prefers: the chunked-walk case
     * assigns each selection arm to a contiguous block of keys and then asserts
     * how many subjects one walk visited, which only means something while the
     * key order is the insertion order. It also makes the malformed-key guard
     * assertable, since `TeamKey::looksLikeOne()` reads this same switch.
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
            'magic-starter.use_uuids' => false,
            'magic-starter.features' => $this->billingEnabled ? [Features::billing()] : [],
            'magic-starter.billing.billable' => 'user',
            // The adopter's own ranking, cheapest first. The write path reads it,
            // so a scenario that moves a tier has to be decidable.
            'magic-starter.billing.tier_order' => ['free', 'pro', 'business'],
            'magic-starter.billing.prices' => [
                'price_pro' => 'pro',
                'price_business' => 'business',
            ],
            'magic-starter.billing.store_products' => [
                self::APP_STORE_BUSINESS => 'business',
            ],
            'magic-starter.billing.revenuecat.secret_api_key' => self::API_KEY,
            'magic-starter.billing.revenuecat.base_url' => RevenueCatClient::DEFAULT_BASE_URL,
            'magic-starter.billing.revenuecat.accept_sandbox' => false,
        ]);
    }

    /**
     * Turn the billing feature OFF, before the providers boot.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function withBillingDisabled($app): void
    {
        $this->billingEnabled = false;
    }

    /**
     * Publish an hourly cadence, which is what an adopter selling mostly through
     * the stores sets.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function withHourlyCadence($app): void
    {
        $app['config']->set('magic-starter.billing.reconcile.cadence', 'hourly');
    }

    /**
     * Publish a raw cron expression, the escape hatch for a cadence no word
     * names.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function withCronCadence($app): void
    {
        $app['config']->set('magic-starter.billing.reconcile.cadence', '17 */4 * * *');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The billable has to be the Cashier-aware fixture BEFORE the schema is
        // built: `create_subscriptions_table` derives its foreign key from the
        // billable model's own basename, exactly as Cashier's relation does.
        MagicStarter::useUserModel(User::class);

        // A STATIC, so a model another test in this process registered outlives
        // that test's application. Pinned rather than assumed.
        Cashier::useSubscriptionModel(Subscription::class);

        // Every claim this sweep issues is checked against the feeder invariant,
        // which is the pairing WriteEntitlement's rule 2 depends on and has no
        // guard for. A decorator rather than a test of its own, because the
        // revocation paths worth checking are the ones nobody enumerated: this
        // way every scenario below is an invariant check without having to
        // remember to make it one.
        FeederInvariantWriter::reset();

        $this->app->extend(
            WritesEntitlement::class,
            fn (WritesEntitlement $inner): WritesEntitlement => new FeederInvariantWriter($inner),
        );

        // Every fixture below decides "is this subscription still live" against
        // `now()`, and the reconciler stamps its own claims with `now()` too, so
        // the clock is pinned to the moment the entitlement on record was
        // granted. Without this a period a month wide is live today and lapsed
        // next month, and the file would start failing on a date.
        $this->travelTo($this->grantedAt());

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Cashier::useSubscriptionModel(CashierSubscription::class);
        MagicStarter::reset();

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Convergence: the drift each rail can actually heal
    // -------------------------------------------------------------------------

    /**
     * A Stripe subject whose record disagrees with its local row converges.
     *
     * The drift is the one a dropped `customer.subscription.updated` leaves: the
     * Cashier row was synced by a delivery that landed, the entitlement
     * projection in the same transaction was not, so the record still names the
     * tier the customer used to be on.
     */
    public function test_a_drifted_stripe_subject_converges_to_what_its_local_row_says(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_source_event_at' => $this->grantedAt()->subDay(),
        ]);

        $this->makeSubscription($billable, 'price_business');

        $this->artisan(ReconcileBillingEntitlements::NAME)
            ->expectsOutputToContain('Reconciled 1 billable(s): 1 corrected, 0 unreadable.')
            ->assertExitCode(0)
            ->run();

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
        $this->assertSame(PlanStatus::ACTIVE->value, $billable->getAttribute('plan_status'));
        $this->assertSame(BillingProvider::STRIPE->value, $billable->getAttribute('plan_provider'));
        $this->assertSame('price_business', $billable->getAttribute('plan_product_id'));
    }

    /**
     * A local row that says the subscription finished revokes the tier, and
     * revokes it by NULLING it.
     *
     * The sweep's revocation branch, which nothing else here reached. It is the
     * expensive direction to get wrong in both senses: this is the only path
     * where a scheduled fleet-wide walk TAKES a tier away, and the shape of the
     * claim it writes is what the feeder invariant is about. A non-granting
     * status has to arrive with a null tier, because rule 2 reads that pairing to
     * decide whether a cross-rail write is taking access away and has no guard
     * for the pairing being wrong.
     *
     * Asserted twice over, deliberately. The columns below are the outcome, and
     * {@see FeederInvariantWriter}, installed over the contract in setUp, is what
     * inspects the claim on its way through: measured, with `plan: null` in the
     * command's revocation branch changed to a tier, the column assertions here
     * fail AND the invariant guard names the violation. Before this test existed
     * that mutation was invisible in this file, because every other scenario
     * either converges a tier or declines to walk at all.
     */
    public function test_a_finished_local_subscription_revokes_the_tier_and_nulls_it(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_source_event_at' => $this->grantedAt()->subDay(),
        ]);

        // A positive statement that it finished, rather than an absent row: the
        // command treats those two differently and only this one revokes.
        $this->makeSubscription($billable, 'price_business', status: 'canceled');

        $this->artisan(ReconcileBillingEntitlements::NAME)
            ->expectsOutputToContain('Reconciled 1 billable(s): 1 corrected, 0 unreadable.')
            ->assertExitCode(0)
            ->run();

        $billable->refresh();
        $this->assertNull(
            $billable->getAttribute('plan'),
            'A finished subscription owes nothing, so the tier is nulled rather than renamed to a '
            . 'free one: the package ships no tier vocabulary and cannot know what a free tier is called.',
        );
        $this->assertFalse(
            PlanStatus::fromWire($billable->getAttribute('plan_status'))->grants(),
            'The status has to stop granting, or the row still entitles the customer to what the '
            . 'rail just said they no longer have.',
        );
        $this->assertSame(BillingProvider::STRIPE->value, $billable->getAttribute('plan_provider'));

        $this->assertGreaterThan(
            0,
            FeederInvariantWriter::$checked,
            'The invariant guard saw no claims, so its silence above proves nothing.',
        );
    }

    /**
     * A store subject whose record disagrees with the authoritative read
     * converges.
     *
     * The drift here is the expensive direction of a dropped delivery: the
     * customer upgraded in the App Store, the `INITIAL_PURCHASE` never arrived,
     * and there is NO self-serve recovery because the store will not resell what
     * they already own. Nothing but this sweep ever mentions it again.
     */
    public function test_a_drifted_store_subject_converges_to_the_authoritative_read(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $this->grantedAt()->subDay(),
        ]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription(),
            ]),
        ]);

        $this->artisan(ReconcileBillingEntitlements::NAME)
            ->expectsOutputToContain('Reconciled 1 billable(s): 1 corrected, 0 unreadable.')
            ->assertExitCode(0)
            ->run();

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
        $this->assertSame(BillingProvider::APP_STORE->value, $billable->getAttribute('plan_provider'));
        $this->assertSame(self::MANAGE_URL, $billable->getAttribute('plan_manage_url'));
    }

    // -------------------------------------------------------------------------
    // The no-op, which is the property one run cannot measure
    // -------------------------------------------------------------------------

    /**
     * A Stripe subject that already agrees with its rail is not re-stamped, and
     * a SECOND run does not re-stamp it either.
     *
     * This is the case that separates a reconciler from a restamp loop, and the
     * second run is the whole assertion: after one pass a loop and a converged
     * sweep look identical. Under a loop every scheduled run writes this run's
     * provenance over a genuine event's, the write path's monotonic rule drops
     * the next real delivery as stale, and the customer's next tier change never
     * lands.
     *
     * It is also where three separate undecoded reads die at once, and each one
     * kills it differently, which is why the fixture populates all three columns
     * rather than only the tier: `plan` read as an enum answers null and
     * disagrees, `plan_current_period_end` read with `?->` on a string is a fatal
     * Error, and `plan_renews` read raw is SQLite's `1`, which never equals
     * `true`.
     */
    public function test_a_stripe_subject_that_agrees_with_its_rail_is_never_restamped(): void
    {
        $grantedAt = $this->grantedAt()->subDay();

        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_source_event_at' => $grantedAt,
            'plan_current_period_end' => $this->periodEnd(),
            'plan_renews' => true,
        ]);

        // The shape is the point: the package's own migration, and a model that
        // casts none of it.
        $this->assertIsNotBool($billable->fresh()?->getAttribute('plan_renews'));
        $this->assertIsString($billable->fresh()?->getAttribute('plan_current_period_end'));

        $this->makeSubscription($billable, 'price_pro');

        foreach ([1, 2] as $pass) {
            $this->artisan(ReconcileBillingEntitlements::NAME)
                ->expectsOutputToContain('Reconciled 1 billable(s): 0 corrected, 0 unreadable.')
                ->assertExitCode(0)
                ->run();

            $billable->refresh();

            $this->assertSame(
                $grantedAt->getTimestamp(),
                $this->storedTimestamp($billable, 'plan_source_event_at'),
                "Pass {$pass} re-stamped a subject that agreed with its rail.",
            );
            $this->assertSame('pro', $billable->getAttribute('plan'));
            $this->assertSame(PlanStatus::ACTIVE->value, $billable->getAttribute('plan_status'));
            $this->assertSame(
                $this->periodEnd()->getTimestamp(),
                $this->storedTimestamp($billable, 'plan_current_period_end'),
            );
            $this->assertTrue((bool) $billable->getAttribute('plan_renews'));
        }
    }

    /**
     * A subject that CASTS the provenance columns is not corrected either, on
     * two runs, and does not raise on the first.
     *
     * The store rail, deliberately. Its claim is authoritative and is applied
     * unconditionally, so its provenance moves on every run BY DESIGN and an
     * unmoved timestamp is the wrong assertion; what must not move is the
     * MEANING, which is what "0 corrected" reports.
     *
     * The mutant this kills is the one the uncast case cannot see. Both
     * `fromWire()` calls take a `?string`, and on this model `plan_provider` is a
     * `BillingProvider` instance and `plan` is a tier enum, so a read taken
     * straight off the model is a TypeError on a scheduled fleet walk rather
     * than a wrong answer on one row.
     */
    public function test_a_subject_casting_its_provenance_columns_is_read_the_same_way(): void
    {
        MagicStarter::useUserModel(EnumCastingUser::class);

        $billable = EnumCastingUser::query()->create([
            'name' => 'Payer',
            'email' => 'cast-' . Str::random(8) . '@example.test',
            'password' => 'secret',
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $this->grantedAt()->subDay(),
            'plan_product_id' => self::APP_STORE_BUSINESS,
            'plan_current_period_end' => $this->periodEnd(),
            'plan_renews' => true,
            'plan_manage_url' => self::MANAGE_URL,
        ]);

        $this->fakeAuthoritativeReads([
            (string) $billable->getKey() => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription(),
            ]),
        ]);

        foreach ([1, 2] as $pass) {
            $this->artisan(ReconcileBillingEntitlements::NAME)
                ->expectsOutputToContain('Reconciled 1 billable(s): 0 corrected, 0 unreadable.')
                ->assertExitCode(0)
                ->run();

            $billable->refresh();

            $this->assertSame(
                TestTier::BUSINESS,
                $billable->getAttribute('plan'),
                "Pass {$pass} moved the tier of a subject its rail still funds.",
            );
            $this->assertSame(BillingProvider::APP_STORE, $billable->getAttribute('plan_provider'));
        }
    }

    // -------------------------------------------------------------------------
    // What the sweep may not touch
    // -------------------------------------------------------------------------

    /**
     * An operator-granted plan is never walked at all.
     *
     * `manual` is the fourth provider value and it is deliberately absent from
     * the selection, which reads like an oversight and is the opposite: an
     * operator grant has no rail to compare against, so every comparison this
     * command could make against it would be a revocation. Completing the list
     * is the most natural tidy imaginable and it would put a scheduled fleet
     * sweep in a position to take away comps, migrations and support gestures.
     *
     * The assertion is the WALK COUNT rather than the surviving row, and that is
     * load bearing. The subject below carries a canceled Cashier row from before
     * it was comped, so a widened selection would assemble a real revocation
     * against it; the write path's cross-rail rule would then refuse that claim
     * and the row would survive anyway. A test asserting only the row passes with
     * `manual` added to the selection, which is the mutant this file exists to
     * kill.
     */
    public function test_an_operator_granted_plan_is_never_walked(): void
    {
        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::MANUAL->value,
            'plan_source_event_at' => $this->grantedAt()->subDay(),
        ]);

        $this->makeSubscription($billable, 'price_pro', status: 'canceled');

        $this->artisan(ReconcileBillingEntitlements::NAME)
            ->expectsOutputToContain('Reconciled 0 billable(s): 0 corrected, 0 unreadable.')
            ->assertExitCode(0)
            ->run();

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
        $this->assertSame(BillingProvider::MANUAL->value, $billable->getAttribute('plan_provider'));
    }

    /**
     * A granting subscription whose price nobody mapped is a config gap, never a
     * downgrade.
     *
     * The absence of a reason to grant is not a reason to revoke: an unmapped
     * price means somebody added a price in Stripe and not in
     * `magic-starter.billing.prices`, and taking a tier away from the customer
     * whose card just cleared is the wrong direction to resolve that in.
     */
    public function test_an_unmapped_price_leaves_a_paying_subject_untouched(): void
    {
        Log::spy();

        $grantedAt = $this->grantedAt()->subDay();

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_source_event_at' => $grantedAt,
        ]);

        $this->makeSubscription($billable, 'price_nobody_mapped');

        $this->artisan(ReconcileBillingEntitlements::NAME)
            ->expectsOutputToContain('Reconciled 1 billable(s): 0 corrected, 0 unreadable.')
            ->assertExitCode(0)
            ->run();

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
        $this->assertSame($grantedAt->getTimestamp(), $this->storedTimestamp($billable, 'plan_source_event_at'));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => ($context['reason'] ?? null) === 'unmapped_price');
    }

    /**
     * A rail that could not be READ leaves the entitlement alone and exits
     * non-zero.
     *
     * No answer is not an expired subscription, so the row is untouched; and the
     * non-zero exit is the only thing that tells an operator running this by hand
     * that the absence of a correction says nothing about the subject.
     */
    public function test_an_unreadable_rail_leaves_the_entitlement_and_exits_non_zero(): void
    {
        Log::spy();

        $grantedAt = $this->grantedAt()->subDay();

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $grantedAt,
        ]);

        Http::fake(fn (): PromiseInterface => Http::response(['message' => 'service unavailable'], 503));

        $this->artisan(ReconcileBillingEntitlements::NAME)
            ->expectsOutputToContain('Reconciled 1 billable(s): 0 corrected, 1 unreadable.')
            ->assertExitCode(1)
            ->run();

        $billable->refresh();
        $this->assertSame('business', $billable->getAttribute('plan'));
        $this->assertSame($grantedAt->getTimestamp(), $this->storedTimestamp($billable, 'plan_source_event_at'));

        Log::shouldHaveReceived('warning')
            ->withArgs(
                fn (string $message, array $context): bool => ($context['reason'] ?? null) === 'authoritative_read_failed',
            );
    }

    /**
     * A malformed `--billable` never reaches the query.
     *
     * On a UUID deployment the key column is a PostgreSQL `uuid`, so a bad value
     * in `where id = ?` is a 500 there and a clean empty set on SQLite, which is
     * exactly the shape of defect a SQLite-only suite cannot see. The guard is
     * what makes the two engines agree, and the assertion is that NOTHING was
     * walked rather than that an error was printed.
     */
    public function test_a_malformed_billable_key_never_reaches_the_query(): void
    {
        $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
        ]);

        $this->artisan(ReconcileBillingEntitlements::NAME, ['--billable' => 'not-a-key'])
            ->expectsOutputToContain('cannot be a billable key, so nothing was queried.')
            ->doesntExpectOutputToContain('Reconciled')
            ->assertExitCode(1)
            ->run();
    }

    // -------------------------------------------------------------------------
    // The selection, under the pagination it actually runs under
    // -------------------------------------------------------------------------

    /**
     * Both selection arms survive a walk that crosses a page boundary.
     *
     * `chunkById` keyset-paginates by appending `id > ?` AT THE TOP LEVEL of the
     * query, and SQL binds AND tighter than OR, so an ungrouped `orWhere` would
     * attach the page boundary to the SECOND arm alone. The first arm would then
     * be re-selected in full on every page.
     *
     * The fixture is arranged so that the mutant miscounts rather than hangs: the
     * NULL-provenance arm holds the lower half of the key range and the
     * `plan_provider` arm the upper half, so a flattened query returns the first
     * page unchanged and then a second page carrying the whole upper arm again.
     * The count is the assertion because the OUTCOME is identical either way:
     * every subject here skips without a correction, so a test reading rows would
     * pass with the group removed.
     */
    public function test_both_selection_arms_survive_a_chunked_walk(): void
    {
        // Two thirds of a page, then two thirds of a page. Sized against the
        // command's own chunk so the walk is guaranteed to cross a boundary
        // rather than assumed to.
        $perArm = 60;

        // Lower half of the key range: no provenance at all, with the local
        // Stripe signal that makes such a row worth reading.
        for ($index = 0; $index < $perArm; $index++) {
            $this->makeBillable(['stripe_id' => 'cus_unprovenanced_' . $index]);
        }

        // Upper half: provenance on record, no local signal.
        for ($index = 0; $index < $perArm; $index++) {
            $this->makeBillable([
                'plan' => 'pro',
                'plan_status' => PlanStatus::ACTIVE->value,
                'plan_provider' => BillingProvider::STRIPE->value,
            ]);
        }

        $this->artisan(ReconcileBillingEntitlements::NAME)
            ->expectsOutputToContain('Reconciled ' . ($perArm * 2) . ' billable(s): 0 corrected, 0 unreadable.')
            ->assertExitCode(0)
            ->run();
    }

    /**
     * A subject with NO provenance and NO local signal is left out of the walk.
     *
     * The signal is required with the NULL arm rather than optional, because
     * NULL provenance on its own is also every subject that has never bought
     * anything. Without it the sweep would walk the entire free tier on a
     * schedule and log a skip per subject, forever.
     */
    public function test_a_subject_that_has_never_bought_anything_is_not_walked(): void
    {
        $this->makeBillable([]);

        $this->artisan(ReconcileBillingEntitlements::NAME)
            ->expectsOutputToContain('Reconciled 0 billable(s): 0 corrected, 0 unreadable.')
            ->assertExitCode(0)
            ->run();
    }

    // -------------------------------------------------------------------------
    // The schedule
    // -------------------------------------------------------------------------

    /**
     * The sweep is scheduled DAILY out of the box.
     *
     * Daily rather than the hourly the application this rail came from runs, and
     * the difference is the package's only lever on what the sweep costs: it
     * makes one authoritative RevenueCat read per store subject per run, and this
     * package registers the schedule for every adopter who switches billing on,
     * including the ones who never thought about the cadence.
     */
    public function test_the_sweep_is_scheduled_daily_out_of_the_box(): void
    {
        $event = $this->scheduledReconciler();

        $this->assertNotNull($event, 'The reconciler is not on the schedule with billing enabled.');
        $this->assertSame('0 0 * * *', $event->expression);
    }

    /**
     * The registration carries the two guards, and a name an operator can find.
     *
     * `withoutOverlapping()` is not boilerplate: the sweep makes one network read
     * per store subject, so it can outlive its own tick, and two of them would
     * re-read the same subscriber and write the same row twice. The NAME is what
     * makes a duplicate registration findable at all, which matters because an
     * adopter migrating from a hand-rolled rail already has a schedule entry of
     * their own.
     */
    public function test_the_schedule_is_named_and_guarded(): void
    {
        $event = $this->scheduledReconciler();

        $this->assertNotNull($event);
        $this->assertSame(ReconcileBillingEntitlements::NAME, $event->description);
        $this->assertTrue($event->withoutOverlapping, 'Two concurrent fleet sweeps are allowed.');
        $this->assertTrue($event->onOneServer, 'Every server in the fleet runs its own sweep.');
    }

    /**
     * The cadence comes from config, so an adopter selling through the stores can
     * heal inside the window the damage arrives in.
     */
    #[DefineEnvironment('withHourlyCadence')]
    public function test_the_configured_cadence_decides_when_the_sweep_runs(): void
    {
        $event = $this->scheduledReconciler();

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }

    /**
     * Any cron expression is accepted, which is the escape hatch for a cadence no
     * word names: a staggered sweep, or four times a day at hours the adopter
     * picks rather than the ones a helper picked for them.
     */
    #[DefineEnvironment('withCronCadence')]
    public function test_a_cron_expression_is_accepted_as_a_cadence(): void
    {
        $event = $this->scheduledReconciler();

        $this->assertNotNull($event);
        $this->assertSame('17 */4 * * *', $event->expression);
    }

    /**
     * With billing off there is no schedule entry and no command at all.
     *
     * Both halves matter. A schedule entry would run a sweep over columns that
     * do not exist on an application that never installed the billing
     * migrations, and a registered `billing:reconcile` in the artisan list is an
     * invitation to run exactly that by hand.
     */
    #[DefineEnvironment('withBillingDisabled')]
    public function test_nothing_is_scheduled_or_registered_while_billing_is_off(): void
    {
        $this->assertNull($this->scheduledReconciler());
        $this->assertArrayNotHasKey(ReconcileBillingEntitlements::NAME, Artisan::all());
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * The reconciler's own entry on the schedule, or null when it has none.
     */
    private function scheduledReconciler(): ?ScheduledEvent
    {
        $matched = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn (ScheduledEvent $event): bool => str_contains(
                (string) $event->command,
                ReconcileBillingEntitlements::NAME,
            ),
        ));

        $this->assertLessThan(2, count($matched), 'The reconciler is on the schedule more than once.');

        return $matched[0] ?? null;
    }

    /**
     * A billable carrying whatever entitlement provenance the scenario needs.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeBillable(array $attributes): User
    {
        $this->assertSame(
            User::class,
            MagicStarter::billableModel(),
            'The billable subject is not the fixture this file writes.',
        );

        return User::query()->create([
            'name' => 'Payer',
            'email' => 'payer-' . Str::random(10) . '@example.test',
            'password' => 'secret',
            ...$attributes,
        ]);
    }

    /**
     * A local Cashier subscription row, which is all the Stripe half of this
     * command ever reads.
     *
     * `forceFill` rather than a create call, so the row does not depend on
     * Cashier's own mass-assignment configuration, and `save()` so `updated_at`
     * is written: an undated row is one this command deliberately skips.
     */
    private function makeSubscription(Model $billable, string $priceId, string $status = 'active'): Subscription
    {
        $subscription = new Subscription;

        $subscription->forceFill([
            $billable->getForeignKey() => $billable->getKey(),
            'type' => 'default',
            'stripe_id' => 'sub_' . Str::random(10),
            'stripe_status' => $status,
            'stripe_price' => $priceId,
            'quantity' => 1,
            'ends_at' => null,
        ])->save();

        return $subscription;
    }

    /**
     * A `GET /subscribers/{app_user_id}` body, in the shape the API documents.
     *
     * @param  array<string, array<string, mixed>>  $subscriptions
     * @return array<string, mixed>
     */
    private function subscriber(array $subscriptions): array
    {
        return [
            'subscriber' => [
                'original_app_user_id' => 'irrelevant-to-this-command',
                'first_seen' => $this->grantedAt()->subYear()->toIso8601ZuluString(),
                'management_url' => self::MANAGE_URL,
                'subscriptions' => $subscriptions,
                'entitlements' => [],
                'non_subscriptions' => [],
            ],
        ];
    }

    /**
     * One live App Store subscription, with every field the store feeder reads.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function subscription(array $overrides = []): array
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
     * An id the test did not name answers 404 rather than an empty subscriber: an
     * empty body is the one answer that could quietly revoke, so a sweep asking
     * for the wrong subscriber has to fail loudly instead of passing.
     *
     * @param  array<string, array<string, mixed>>  $byAppUserId
     */
    private function fakeAuthoritativeReads(array $byAppUserId): void
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
     * Read a stored date column as a Unix timestamp.
     *
     * Parsed here rather than cast on the fixture, because the fixture casting it
     * would hide the very decode this file exists to pin: the package ships the
     * column and not the model that owns it.
     */
    private function storedTimestamp(Model $billable, string $attribute): ?int
    {
        $stored = $billable->getAttribute($attribute);

        if ($stored === null) {
            return null;
        }

        return $stored instanceof \DateTimeInterface
            ? Carbon::instance($stored)->getTimestamp()
            : Carbon::parse((string) $stored)->getTimestamp();
    }

    /**
     * When the entitlement on record was granted, and the instant the clock is
     * pinned to.
     */
    private function grantedAt(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-22 12:00:00');
    }

    /**
     * A period end a month past the pinned clock, i.e. comfortably live.
     */
    private function periodEnd(): CarbonImmutable
    {
        return $this->grantedAt()->addMonth();
    }

    /**
     * Build the billing schema the way a consumer's `migrate` does.
     *
     * The package's own migrations rather than a hand-written Blueprint, because
     * the COLUMN TYPES are what half the assertions here depend on: `plan_renews`
     * is a real boolean that SQLite hands back as `1`, and the three dates are
     * `timestampTz` that it hands back as strings. A hand-rolled string column
     * would quietly certify a decode the shipped schema never asks for.
     */
    private function createSchema(): void
    {
        $this->runPackageMigration('create_users_table.php');
        $this->runPackageMigration('add_cashier_customer_columns_to_billable_table.php');
        $this->runPackageMigration('add_entitlement_provenance_to_billable_table.php');
        $this->runPackageMigration('create_subscriptions_table.php');

        // The items table is not read by this command, and it is not optional
        // either: the package's `Subscription` eager-loads its items, so a
        // missing table is a query exception on the first local row this sweep
        // looks at.
        $this->runPackageMigration('create_subscription_items_table.php');
    }

    private function runPackageMigration(string $filename): void
    {
        $migration = require __DIR__ . '/../../database/migrations/' . $filename;

        $migration->up();
    }
}

/**
 * The billable, with a basename a consumer actually ships.
 *
 * `Str::snake(class_basename($this)) . '_id'` is how Cashier names the relation
 * column and how `create_subscriptions_table.php` derives it, so a fixture named
 * for the test would pin a column no application ever sees.
 *
 * NOTHING here casts the ten provenance columns, deliberately: the package ships
 * those columns and not the model that owns them, so an uncast model is a shape
 * an adopter is entitled to ship and the one three of this file's reads have to
 * survive.
 */
class User extends ConcreteUser
{
    use Billable;

    protected $table = 'users';
}

/**
 * The adopter's own tier vocabulary, which this package deliberately does not
 * ship.
 */
enum TestTier: string
{
    case FREE = 'free';
    case PRO = 'pro';
    case BUSINESS = 'business';
}

/**
 * A billable that CASTS the provenance columns, which an adopter is free to do.
 *
 * `getForeignKey()` is pinned to the other fixture's, because Cashier derives the
 * relation column from the model's own BASENAME and this class has a different
 * one: left alone it would look for `enum_casting_user_id` in a table whose
 * column is `user_id`. It reaches no Cashier relation in this file (it is
 * exercised on the store rail, which reads no local row at all), and the pin is
 * here so that a scenario later moved to the card rail does not fail for a reason
 * that has nothing to do with billing.
 */
class EnumCastingUser extends ConcreteUser
{
    use Billable;

    protected $table = 'users';

    public function getForeignKey(): string
    {
        return 'user_id';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan' => TestTier::class,
            'plan_provider' => BillingProvider::class,
            'plan_source_event_at' => 'datetime',
        ];
    }
}
