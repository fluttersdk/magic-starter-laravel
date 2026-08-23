<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Actions\WriteEntitlement;
use FlutterSdk\MagicStarter\Contracts\WritesEntitlement;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Locks the four ordering rules of the package's single entitlement write path.
 *
 * All four guard the SAME column, so they are pinned in separate tests against
 * separate teams on purpose. A single scenario that trips two of them would keep
 * passing with either one entirely absent: each guard would absorb the other's
 * mutation, and the test would certify a protection that is not there. Every
 * test below is arranged so that exactly ONE rule can decide its outcome.
 *
 * Two of the rules are permissive in one direction and restrictive in the other,
 * and each of those needs BOTH tests. Rule 1b applies a tie and refuses one that
 * would take access away; rule 2b honours an authoritative handover and refuses
 * a projected one. A file holding only the refusing half of either pair passes
 * with the earlier, wrong formulation of that rule back in place, which is not
 * hypothetical: it is how rule 2b's mirror defect shipped once.
 *
 * The stakes are the reason for that care. A dropped write that should have
 * landed leaves a customer on a tier they no longer pay for; a landed write
 * that should have dropped revokes a tier someone IS paying for. Only the
 * second is a support ticket from an angry paying customer, which is why every
 * ambiguous case in {@see WriteEntitlement} resolves toward keeping the
 * entitlement rather than toward taking it away.
 *
 * The feature flag is enabled the way every other feature is tested here, via
 * `config(['magic-starter.features' => [...]])`, and one test deliberately
 * turns it OFF again: the contract has to stay bindable with the feature
 * disabled, because a binding is not a capability.
 */
class WriteEntitlementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The tier catalogue these tests rank against, cheapest first.
     *
     * @var list<string>
     */
    private const TIER_ORDER = [
        'free',
        'pro',
        'business',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Publishing the package's lang files copies them into the skeleton's
        // lang/vendor/magic-starter, and a PUBLISHED override wins over the
        // package path. Any test here that reads the shipped catalogue would
        // then be answered by a leftover copy from an earlier install run
        // instead of by the file it means to assert. Clear it first.
        $this->cleanupPublishedBillingArtifacts();

        config([
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing.tier_order' => self::TIER_ORDER,
        ]);

        // The consuming application owns `plan` and `plan_status` (the tier
        // vocabulary is its own); the package ships the eight provenance
        // columns. Both halves are created here because the action writes both.
        Schema::create('teams', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(true);
            $table->string('plan')->nullable();
            $table->string('plan_status')->nullable();
            $table->string('plan_provider')->nullable();
            $table->timestamp('plan_source_event_at')->nullable();
            $table->string('plan_provider_status')->nullable();
            $table->string('plan_product_id')->nullable();
            $table->timestamp('plan_current_period_end')->nullable();
            $table->boolean('plan_renews')->nullable();
            $table->timestamp('plan_grace_period_ends_at')->nullable();
            $table->string('plan_manage_url', 2048)->nullable();
            $table->timestamps();
        });
    }

    /**
     * The feature joins the registry the same way the other thirteen do.
     */
    public function test_the_billing_feature_is_registered(): void
    {
        $this->assertSame('billing', Features::billing());
        $this->assertTrue(Features::hasBillingFeatures());

        config(['magic-starter.features' => []]);

        $this->assertFalse(Features::hasBillingFeatures());
    }

    /**
     * With the feature DISABLED the contract still resolves, and the migration
     * is not published.
     *
     * That split is how every other feature behaves and it is worth pinning:
     * the bindings block is unconditional, so a consumer can override the
     * action before deciding to switch billing on, while the schema only
     * arrives when the feature is actually selected. Binding the contract
     * conditionally would make the override impossible to register early, and
     * publishing the migration unconditionally would put eight columns on the
     * teams table of every consumer that never asked for billing.
     */
    public function test_the_contract_is_bindable_with_the_feature_disabled_but_the_migration_is_not_published(): void
    {
        config(['magic-starter.features' => []]);

        $this->assertFalse(Features::hasBillingFeatures());
        $this->assertInstanceOf(
            WriteEntitlement::class,
            $this->app->make(WritesEntitlement::class),
        );

        $this->cleanupPublishedBillingArtifacts();

        $this->artisan('magic-starter:install', [
            '--features' => ['teams'],
        ])->assertExitCode(0);

        $this->assertSame(
            [],
            glob(database_path('migrations/*_add_entitlement_provenance_to_billable_table.php')) ?: [],
            'The provenance migration must not be published unless billing is selected.',
        );

        $this->artisan('magic-starter:install', [
            '--features' => ['teams', 'billing'],
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertNotEmpty(
            glob(database_path('migrations/*_add_entitlement_provenance_to_billable_table.php')) ?: [],
            'The provenance migration must be published when billing is selected.',
        );

        $this->cleanupPublishedBillingArtifacts();
    }

    /**
     * Every case of the neutral vocabulary has copy in EVERY shipped locale.
     *
     * Driven off `cases()` rather than off a hand-written list, so a tenth
     * status or a fifth rail added later fails here instead of shipping the raw
     * translation key to a customer.
     *
     * The raw-key check alone would be BLIND to the Turkish catalogue, and that
     * is measured, not assumed: a key missing from `tr` falls back to `en`, so
     * it resolves to a perfectly plausible English string rather than to the
     * key. Verified by deleting the Turkish `past_due` line, which the raw-key
     * loop passed. What actually catches it is the second loop, which requires
     * the two locales to DIFFER for everything except the two store brands: a
     * brand name is the same word in both languages, and everything else is not.
     */
    public function test_the_neutral_vocabulary_has_a_label_in_every_shipped_locale(): void
    {
        // The two mobile stores are brands, so they read identically in both
        // catalogues on purpose and are exempt from the difference check below.
        $brandNames = [
            BillingProvider::APP_STORE->value,
            BillingProvider::PLAY_STORE->value,
        ];

        foreach (['en', 'tr'] as $locale) {
            $this->app->setLocale($locale);

            foreach (BillingProvider::cases() as $provider) {
                $this->assertNotSame(
                    'magic-starter::billing.providers.' . $provider->value,
                    $provider->label(),
                    sprintf('Missing [%s] copy for provider [%s].', $locale, $provider->value),
                );
            }

            foreach (PlanStatus::cases() as $status) {
                $this->assertNotSame(
                    'magic-starter::billing.statuses.' . $status->value,
                    $status->label(),
                    sprintf('Missing [%s] copy for status [%s].', $locale, $status->value),
                );
            }
        }

        foreach (BillingProvider::cases() as $provider) {
            if (in_array($provider->value, $brandNames, true)) {
                continue;
            }

            $this->app->setLocale('en');
            $english = $provider->label();

            $this->app->setLocale('tr');

            $this->assertNotSame(
                $english,
                $provider->label(),
                sprintf('Provider [%s] falls back to English; the tr entry is missing.', $provider->value),
            );
        }

        foreach (PlanStatus::cases() as $status) {
            $this->app->setLocale('en');
            $english = $status->label();

            $this->app->setLocale('tr');

            $this->assertNotSame(
                $english,
                $status->label(),
                sprintf('Status [%s] falls back to English; the tr entry is missing.', $status->value),
            );
        }

        // One anchored value per locale, so the loops above cannot be satisfied
        // by a catalogue that resolves to the wrong language's strings.
        $this->app->setLocale('en');
        $this->assertSame('Payment due', PlanStatus::PAST_DUE->label());

        $this->app->setLocale('tr');
        $this->assertSame('Ödeme bekliyor', PlanStatus::PAST_DUE->label());
    }

    /**
     * The provenance migration adds its eight columns, and removes them again.
     *
     * Every other test here hand-builds the schema so it can arrange a
     * scenario, which leaves the shipped migration itself unexecuted: a file
     * nothing runs is a file whose first execution happens in a consumer's
     * database. So this one drops that hand-built table, rebuilds it the way a
     * teams install leaves it, and runs the real migration over it.
     *
     * The absent-table case is exercised in the same test rather than in its own,
     * because it is the same file's contract, and its EXPECTATION IS REVERSED
     * from what this test first asserted. It read "no teams table at all: billing
     * without teams is a no-op, not a throw", which was wrong twice over: the
     * table is no longer teams by definition (it is resolved from
     * `magic-starter.billing.billable`), and a silent skip on a table the token
     * named is not a no-op, it is a `migrate` that reports success, adds nothing,
     * and comes back as a missing-column error on the first payment. Billing
     * without teams is now served by the USER subject, so the only thing an
     * absent resolved table can mean is a misconfiguration.
     */
    public function test_the_provenance_migration_adds_and_drops_its_eight_columns(): void
    {
        // This whole test arranges a TEAMS schema, so the token has to say so:
        // the migration resolves its table from the billable subject, and the
        // shipped default is the user.
        config(['magic-starter.billing.billable' => 'team']);

        $provenanceColumns = [
            'plan_provider',
            'plan_source_event_at',
            'plan_provider_status',
            'plan_product_id',
            'plan_current_period_end',
            'plan_renews',
            'plan_grace_period_ends_at',
            'plan_manage_url',
        ];

        $migration = require __DIR__ . '/../../database/migrations/add_entitlement_provenance_to_billable_table.php';

        // 1. The resolved table is absent: a THROW naming it, not a silent skip.
        Schema::drop('teams');

        try {
            $migration->up();

            $this->fail('An absent billable table must fail the migration, not pass it.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('teams', $exception->getMessage());
            $this->assertStringContainsString('magic-starter.billing.billable', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasTable('teams'));

        // 2. A teams table as the teams feature leaves it, with no provenance.
        Schema::create('teams', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(true);
            $table->timestamps();
        });

        $migration->up();

        foreach ($provenanceColumns as $column) {
            $this->assertTrue(
                Schema::hasColumn('teams', $column),
                sprintf('Expected the migration to add [%s].', $column),
            );
        }

        // 3. Idempotent: re-running over its own columns adds nothing and throws
        // nothing, which is what makes a re-published copy survivable.
        $migration->up();
        $this->assertTrue(Schema::hasColumn('teams', 'plan_provider'));

        // 4. Down drops exactly the eight, leaving the teams table itself.
        $migration->down();

        foreach ($provenanceColumns as $column) {
            $this->assertFalse(
                Schema::hasColumn('teams', $column),
                sprintf('Expected the rollback to drop [%s].', $column),
            );
        }

        $this->assertTrue(Schema::hasColumn('teams', 'name'));
    }

    /**
     * The migration also creates the entitlement ITSELF where a consumer has not
     * got it, and leaves a consumer's own copy alone.
     *
     * Provenance with nothing to be provenance FOR is not a feature: the default
     * action writes `plan` and `plan_status` on every apply, so a fresh consumer
     * enabling billing without these two would get a throw on its first write.
     * Both halves are asserted because the two audiences differ and only one of
     * them is safe to get wrong: a consumer that already sells something holds
     * its live entitlement in `plan`, so creating it again must be a no-op, and
     * dropping it on rollback would not be recoverable.
     */
    public function test_the_migration_creates_the_entitlement_only_where_it_is_missing(): void
    {
        // A teams schema, so the billable token has to name the team subject.
        config(['magic-starter.billing.billable' => 'team']);

        $migration = require __DIR__ . '/../../database/migrations/add_entitlement_provenance_to_billable_table.php';

        // 1. A FRESH consumer: teams as the teams feature leaves it, no billing
        //    columns of any kind.
        Schema::drop('teams');
        Schema::create('teams', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->timestamps();
        });

        $this->assertFalse(Schema::hasColumn('teams', 'plan'));
        $this->assertFalse(Schema::hasColumn('teams', 'plan_status'));

        $migration->up();

        $this->assertTrue(
            Schema::hasColumn('teams', 'plan'),
            'A fresh consumer needs somewhere to store the tier itself.',
        );
        $this->assertTrue(Schema::hasColumn('teams', 'plan_status'));

        // 2. Rollback keeps them. It cannot tell whether it created them, and a
        //    consumer that already had `plan` keeps a paid tier in it.
        $migration->down();
        $this->assertFalse(Schema::hasColumn('teams', 'plan_provider'));
        $this->assertTrue(
            Schema::hasColumn('teams', 'plan'),
            'Rollback must not drop the column a paid tier is stored in.',
        );

        // 3. A consumer that ALREADY has the entitlement: running this migration
        //    must not duplicate the column or throw, and the stored value has to
        //    survive, which a re-create would not do.
        Schema::drop('teams');
        Schema::create('teams', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->string('plan')->nullable();
            $table->string('plan_status')->nullable();
            $table->timestamps();
        });
        DB::table('teams')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'name' => 'Already selling',
            'plan' => 'business',
            'plan_status' => 'active',
        ]);

        $migration->up();

        $this->assertSame(
            'business',
            DB::table('teams')->value('plan'),
            'An existing entitlement must survive the provenance migration.',
        );
        $this->assertSame('active', DB::table('teams')->value('plan_status'));
    }

    /**
     * RULE 1, monotonic per rail: a write from the rail that already granted
     * the entitlement, carrying an event OLDER than the one on record, is
     * dropped.
     *
     * Same rail on both sides, so rule 2 (which only fires cross-rail) cannot
     * reach this scenario and the timestamp is the only thing that can decide
     * it. The hazard is documented delivery behaviour rather than paranoia:
     * store rails retry a failed webhook on a schedule measured in tens of
     * minutes, so a promptly delivered EXPIRATION genuinely does arrive before
     * a RENEWAL whose first attempt failed. Without this rule the late
     * renewal's team lands on the cheapest tier while still paying.
     */
    public function test_a_stale_write_on_the_same_rail_is_dropped(): void
    {
        Log::spy();

        $grantedAt = Carbon::parse('2026-08-22 12:00:00');

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'starter_business_monthly',
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: 'free',
            status: PlanStatus::EXPIRED,
            provider: BillingProvider::APP_STORE,
            eventAt: $grantedAt->copy()->subMinute(),
            providerStatus: 'EXPIRATION',
        );

        $this->assertFalse($applied);

        $billable->refresh();
        $this->assertSame('business', $billable->plan);
        $this->assertSame(PlanStatus::ACTIVE->value, $billable->plan_status);
        $this->assertSame('starter_business_monthly', $billable->plan_product_id);

        $this->assertDropWasLogged($billable, 'app_store', 'app_store', 'downgrade');
    }

    /**
     * A write carrying the SAME timestamp as the one on record is dropped when
     * it would REVOKE.
     *
     * This test predates the tie-break rule and its reasoning was once "equal
     * timestamps carry no ordering information, so keep what is already there".
     * That reading dropped paid upgrades as well, so a tie is now decided by
     * direction. The scenario here is business to free, a downgrade, so the
     * outcome is unchanged and the reason is better: a tie that would take the
     * entitlement away still loses.
     *
     * {@see self::test_a_same_instant_upgrade_on_the_same_rail_is_applied()} is
     * the other half, and pairing them is what stops either from passing with
     * the tie-break deleted.
     */
    public function test_a_write_with_the_same_event_timestamp_is_dropped(): void
    {
        Log::spy();

        $grantedAt = Carbon::parse('2026-08-22 12:00:00');

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_source_event_at' => $grantedAt,
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: 'free',
            status: PlanStatus::CANCELED,
            provider: BillingProvider::STRIPE,
            eventAt: $grantedAt->copy(),
            providerStatus: 'canceled',
        );

        $this->assertFalse($applied);

        $billable->refresh();
        $this->assertSame('business', $billable->plan);
    }

    /**
     * The other half of the tie-break: a same-instant UPGRADE applies.
     *
     * A rail that stamps its events to the SECOND (Stripe's `created` is a Unix
     * second) emits paired events from one API call inside a single second, in
     * an order it does not guarantee, and one of the pair carries a tier read
     * from the consumer's own not-yet-resynced local state. Dropping the other
     * left a customer who had just paid for a higher tier on the lower one, and
     * the opposite delivery order was always correct, so the bug was invisible
     * half the time.
     */
    public function test_a_same_instant_upgrade_on_the_same_rail_is_applied(): void
    {
        Log::spy();

        $eventAt = Carbon::parse('2026-08-22 12:00:00');

        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_source_event_at' => $eventAt,
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: 'business',
            status: PlanStatus::ACTIVE,
            provider: BillingProvider::STRIPE,
            eventAt: $eventAt->copy(),
            providerStatus: 'active',
            productId: 'starter_business_monthly',
        );

        $this->assertTrue($applied);

        $billable->refresh();
        $this->assertSame('business', $billable->plan);
        $this->assertSame('starter_business_monthly', $billable->plan_product_id);
    }

    /**
     * RULE 1b covers a status-only revocation, not only a tier downgrade.
     *
     * Same rail, same second, same tier, and the two events disagree about
     * whether that tier still grants. Ranked on the tier alone the pair reads as
     * `same`, so the tie-break's own promise, that a tie which would take the
     * entitlement away still loses, held for a downgrade and not for this: the
     * cancellation half landed and a paying team lost access on a coin flip.
     * Stripe stamps `created` to the SECOND, so a pair emitted from one API call
     * genuinely does share a timestamp.
     *
     * The tier could not answer this question because the loss arrives through
     * the other column. Access is what is being arbitrated, so the tie-break
     * asks about access.
     */
    public function test_a_same_instant_status_only_revocation_on_the_same_rail_is_dropped(): void
    {
        Log::spy();

        $grantedAt = Carbon::parse('2026-08-22 12:00:00');

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_source_event_at' => $grantedAt,
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: 'business',
            status: PlanStatus::EXPIRED,
            provider: BillingProvider::STRIPE,
            // The SAME instant, not a later one: rule 1 cannot decide this, and
            // the tier cannot either, so only the status half can.
            eventAt: $grantedAt->copy(),
            providerStatus: 'canceled',
        );

        $this->assertFalse($applied);

        $billable->refresh();
        $this->assertSame(PlanStatus::ACTIVE->value, $billable->plan_status);
        $this->assertSame('business', $billable->plan);

        $this->assertDropWasLogged($billable, 'stripe', 'stripe', 'same');
    }

    /**
     * RULE 2b: a cross-rail write carrying the SAME tier does not get to take
     * over the provenance of a rail that is still granting.
     *
     * Rule 2 only stops a cross-rail REVOCATION, so this write used to pass and
     * the persist step then rewrote `plan_provider` unconditionally. The damage
     * lands one step later, which is why no other test in this file caught it:
     * with the record naming the new rail, that rail's next revocation is
     * SAME-rail, so rule 2 can no longer see it and rule 1 lets it through. The
     * team ends on free while the rail that was actually billing it never
     * stopped.
     */
    public function test_a_cross_rail_same_tier_write_cannot_take_over_provenance(): void
    {
        Log::spy();

        $grantedAt = Carbon::parse('2026-08-22 12:00:00');

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'starter_business_monthly',
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: 'business',
            status: PlanStatus::ACTIVE,
            provider: BillingProvider::STRIPE,
            eventAt: $grantedAt->copy()->addMinute(),
            // A PROJECTION: assembled from a row this rail wrote earlier, not
            // from reading the rail. That, and not the tier, is what makes it
            // unfit to move the record.
            authoritative: false,
            providerStatus: 'active',
            productId: 'price_business_monthly',
        );

        $this->assertFalse($applied);

        $billable->refresh();
        $this->assertSame(BillingProvider::APP_STORE->value, $billable->plan_provider);
        $this->assertSame('business', $billable->plan);
        $this->assertSame('starter_business_monthly', $billable->plan_product_id);

        $this->assertDropWasLogged($billable, 'app_store', 'stripe', 'same');
    }

    /**
     * The positive twin of the rule above, and the behaviour rule 2b exists to
     * PERMIT rather than to block: a store selling the tier a customer already
     * holds on the web rail is a MIGRATION, and it takes the record.
     *
     * This is the case an earlier formulation got wrong. Written as a blanket
     * same-tier drop, it refused this write, left provenance on the rail that
     * was about to stop billing, and that rail's later cancellation was then
     * SAME-rail: rule 2 could not see it and the team was revoked to free while
     * the store it had just moved to went on charging. Without this test the
     * tier formulation passes the whole file, which is how that mirror defect
     * shipped once already.
     *
     * Byte-identical to the test above apart from `authoritative`, deliberately:
     * the writer's standing is the ONLY thing separating a dropped projection
     * from an honoured handover, so the pair isolates it.
     */
    public function test_an_authoritative_cross_rail_same_tier_write_takes_over_provenance(): void
    {
        Log::spy();

        $grantedAt = Carbon::parse('2026-08-22 12:00:00');

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'price_business_monthly',
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: 'business',
            status: PlanStatus::ACTIVE,
            provider: BillingProvider::APP_STORE,
            eventAt: $grantedAt->copy()->addMinute(),
            // The store speaking for itself: a webhook payload, or a re-read of
            // its API. That standing is what lets it claim the handover.
            authoritative: true,
            providerStatus: 'ACTIVE',
            productId: 'starter_business_monthly',
        );

        $this->assertTrue($applied);

        $billable->refresh();
        $this->assertSame(BillingProvider::APP_STORE->value, $billable->plan_provider);
        $this->assertSame('business', $billable->plan);
        $this->assertSame('starter_business_monthly', $billable->plan_product_id);
    }

    /**
     * The other half of the widened condition: rule 2b consults no direction, so
     * a PROJECTED cross-rail UPGRADE is dropped as well.
     *
     * The tier formulation this replaced applied a cross-rail upgrade and logged
     * a warning, so this is a deliberate behaviour change and not a leftover. A
     * projection is stale by construction: the higher tier it reports is one
     * this database already held, and the rail's own event follows carrying the
     * standing to move the record. One delayed upgrade with a log line naming it
     * is the cost; letting a stale row move provenance is the defect rule 2b
     * closes.
     */
    public function test_a_projected_cross_rail_upgrade_is_dropped(): void
    {
        Log::spy();

        $grantedAt = Carbon::parse('2026-08-22 12:00:00');

        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $grantedAt,
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: 'business',
            status: PlanStatus::ACTIVE,
            provider: BillingProvider::STRIPE,
            eventAt: $grantedAt->copy()->addMinute(),
            authoritative: false,
            providerStatus: 'active',
        );

        $this->assertFalse($applied);

        $billable->refresh();
        $this->assertSame('pro', $billable->plan);
        $this->assertSame(BillingProvider::APP_STORE->value, $billable->plan_provider);

        $this->assertDropWasLogged($billable, 'app_store', 'stripe', 'upgrade');
    }

    /**
     * The control that decides how rule 2b may be written: it asks about the
     * stored STATUS, not only the stored rail.
     *
     * `BillingProvider::grants()` is a per-RAIL table, true for every real rail,
     * so a rule 2b gated on it alone would drop this write. That would be a
     * customer buying Business on a store, for a team whose long-lapsed record
     * on another rail still names Business, and receiving nothing for it. Worse
     * than the defect rule 2b closes. A lapsed record is not an entitlement
     * another rail can take over, it is a slot another rail can fill.
     */
    public function test_a_cross_rail_same_tier_write_applies_when_the_stored_rail_has_lapsed(): void
    {
        Log::spy();

        $grantedAt = Carbon::parse('2026-08-22 12:00:00');

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::EXPIRED->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_source_event_at' => $grantedAt,
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: 'business',
            status: PlanStatus::ACTIVE,
            provider: BillingProvider::APP_STORE,
            eventAt: $grantedAt->copy()->addMinute(),
            providerStatus: 'ACTIVE',
            productId: 'starter_business_monthly',
        );

        $this->assertTrue($applied);

        $billable->refresh();
        $this->assertSame(BillingProvider::APP_STORE->value, $billable->plan_provider);
        $this->assertSame(PlanStatus::ACTIVE->value, $billable->plan_status);
    }

    /**
     * RULE 2, a rail may only revoke what it granted: a downgrade arriving from
     * a rail OTHER than the one on record is dropped.
     *
     * The incoming event is strictly newer, so rule 1 cannot decide this one
     * and the provenance mismatch is the only thing that can. This is what
     * stops a late card-rail cancellation from revoking a store grant halfway
     * through a web-to-store migration, where BOTH rails legitimately hold a
     * record of the same customer and only one of them is still being paid.
     */
    public function test_a_cross_rail_downgrade_is_dropped(): void
    {
        Log::spy();

        $grantedAt = Carbon::parse('2026-08-22 12:00:00');

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'starter_business_monthly',
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: 'free',
            status: PlanStatus::CANCELED,
            provider: BillingProvider::STRIPE,
            eventAt: $grantedAt->copy()->addMinute(),
            providerStatus: 'canceled',
        );

        $this->assertFalse($applied);

        $billable->refresh();
        $this->assertSame('business', $billable->plan);
        $this->assertSame(BillingProvider::APP_STORE->value, $billable->plan_provider);
        $this->assertSame('starter_business_monthly', $billable->plan_product_id);

        $this->assertDropWasLogged($billable, 'app_store', 'stripe', 'downgrade');
    }

    /**
     * RULE 2 covers a status-only revocation too, and an AUTHORITATIVE claim is
     * exactly the one that needs it.
     *
     * Same tier on both sides, so the direction is `same` and a rule 2 that
     * ranks TIERS alone sees no revocation. Rule 2b cannot catch it either: the
     * claim is the rail speaking for itself, which is the standing rule 2b
     * exists to honour. So the write landed, wrote a lapsed status over a rail
     * that is still billing, and every downstream reader gating on
     * `plan_status` cut a paying customer off. The loss arrives through the
     * other column, exactly as it does at rule 1b, so rule 2 asks the same
     * question rule 1b does: does this write take access away.
     *
     * Not a duplicate of the tie-break test above. That one is SAME-rail and
     * needs a shared instant to reach rule 1b at all; this one is cross-rail
     * with a strictly newer event, so rules 1 and 1b cannot see it and only
     * rule 2 can decide it.
     */
    public function test_an_authoritative_cross_rail_same_tier_write_with_a_lapsed_status_is_dropped(): void
    {
        Log::spy();

        $grantedAt = Carbon::parse('2026-08-22 12:00:00');

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'starter_business_monthly',
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: 'business',
            status: PlanStatus::EXPIRED,
            provider: BillingProvider::STRIPE,
            eventAt: $grantedAt->copy()->addMinute(),
            // The rail speaking for itself, so rule 2b lets it through: this is
            // the case only rule 2 can refuse.
            authoritative: true,
            providerStatus: 'canceled',
        );

        $this->assertFalse($applied);

        $billable->refresh();
        $this->assertSame(PlanStatus::ACTIVE->value, $billable->plan_status);
        $this->assertSame('business', $billable->plan);
        $this->assertSame(BillingProvider::APP_STORE->value, $billable->plan_provider);

        $this->assertDropWasLogged($billable, 'app_store', 'stripe', 'same');
    }

    /**
     * A cross-rail UPGRADE is honoured, and warns.
     *
     * Two rails claiming the same customer at different tiers means somebody is
     * demonstrably paying twice. The resolution writes the HIGHER tier: they
     * paid for it, and refusing it would punish the double payment. Nothing
     * here attempts a refund, because no automated path can know which of the
     * two subscriptions the customer meant to keep.
     *
     * The warning is the whole point of the branch. Without it a double charge
     * is invisible until the customer notices, and an operator has to be told
     * to go and cancel one side by hand.
     */
    public function test_a_cross_rail_upgrade_is_honoured_and_warns(): void
    {
        Log::spy();

        $grantedAt = Carbon::parse('2026-08-22 12:00:00');
        $eventAt = $grantedAt->copy()->addMinute();

        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'price_pro',
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: 'business',
            status: PlanStatus::ACTIVE,
            provider: BillingProvider::APP_STORE,
            eventAt: $eventAt,
            providerStatus: 'ACTIVE',
            productId: 'starter_business_monthly',
        );

        $this->assertTrue($applied);

        $billable->refresh();
        $this->assertSame('business', $billable->plan);
        $this->assertSame(BillingProvider::APP_STORE->value, $billable->plan_provider);
        $this->assertSame('starter_business_monthly', $billable->plan_product_id);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($billable): bool {
                return $context['billable_id'] === $billable->id
                    && $context['stored_provider'] === 'stripe'
                    && $context['incoming_provider'] === 'app_store'
                    && $context['direction'] === 'upgrade';
            });
    }

    /**
     * The positive control: a strictly newer write from the rail on record
     * applies, and lands EVERY provenance column.
     *
     * The column sweep is here rather than in its own test because this is the
     * only path that writes at all. A field the action forgets has no other
     * symptom: a consumer's wire simply serves null forever, on a rail whose
     * data was present in the payload the whole time.
     *
     * The absent warning is a mutation guard. An implementation that logged a
     * warning on every write would satisfy the tests above and say nothing;
     * asserting silence on the ordinary path is what makes those warnings mean
     * something.
     */
    public function test_a_newer_write_on_the_same_rail_applies_every_column(): void
    {
        Log::spy();

        $grantedAt = Carbon::parse('2026-08-22 12:00:00');
        $eventAt = $grantedAt->copy()->addMinute();
        $periodEnd = Carbon::parse('2026-09-22 12:00:00');
        $graceEnd = Carbon::parse('2026-09-29 12:00:00');

        $billable = $this->makeBillable([
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'price_pro',
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: 'business',
            status: PlanStatus::PAST_DUE,
            provider: BillingProvider::STRIPE,
            eventAt: $eventAt,
            providerStatus: 'past_due',
            productId: 'price_business',
            currentPeriodEnd: $periodEnd,
            renews: true,
            gracePeriodEndsAt: $graceEnd,
            manageUrl: 'https://example.test/manage/abc',
        );

        $this->assertTrue($applied);

        $billable->refresh();
        $this->assertSame('business', $billable->plan);
        $this->assertSame(PlanStatus::PAST_DUE->value, $billable->plan_status);
        $this->assertSame(BillingProvider::STRIPE->value, $billable->plan_provider);
        $this->assertSame(
            $eventAt->toDateTimeString(),
            Carbon::parse((string) $billable->plan_source_event_at)->toDateTimeString(),
        );
        $this->assertSame('past_due', $billable->plan_provider_status);
        $this->assertSame('price_business', $billable->plan_product_id);
        $this->assertSame(
            $periodEnd->toDateTimeString(),
            Carbon::parse((string) $billable->plan_current_period_end)->toDateTimeString(),
        );
        $this->assertTrue((bool) $billable->plan_renews);
        $this->assertSame(
            $graceEnd->toDateTimeString(),
            Carbon::parse((string) $billable->plan_grace_period_ends_at)->toDateTimeString(),
        );
        $this->assertSame('https://example.test/manage/abc', $billable->plan_manage_url);

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * A cross-rail write whose tier a PUBLISHED catalogue cannot rank is
     * dropped.
     *
     * A tier order that names one of the two tiers and not the other is a
     * config gap, and the direction of the write is genuinely unknown. Unknown
     * is not an upgrade: applying it could revoke a tier another rail granted,
     * which is the exact loss rule 2 exists to prevent. The customer keeps what
     * they have and an operator gets a log line naming the gap.
     */
    public function test_a_cross_rail_write_of_a_tier_missing_from_the_catalogue_is_dropped(): void
    {
        Log::spy();

        // A catalogue that has lost the business row: the stored tier can no
        // longer be ranked, so no incoming tier can be proven higher than it.
        config(['magic-starter.billing.tier_order' => ['free', 'pro']]);

        $grantedAt = Carbon::parse('2026-08-22 12:00:00');

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $grantedAt,
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: 'pro',
            status: PlanStatus::ACTIVE,
            provider: BillingProvider::STRIPE,
            eventAt: $grantedAt->copy()->addMinute(),
            providerStatus: 'active',
        );

        $this->assertFalse($applied);

        $billable->refresh();
        $this->assertSame('business', $billable->plan);
        $this->assertSame(BillingProvider::APP_STORE->value, $billable->plan_provider);

        $this->assertDropWasLogged($billable, 'app_store', 'stripe', 'unknown');
    }

    /**
     * With NO tier order published, a cross-rail write against a HELD tier is
     * dropped, and the log names the key that would let it be ranked.
     *
     * This test asserted the opposite until the contract was re-signed, and the
     * reversal is the whole point of it. The fail-open argued that an
     * unpublished catalogue is the normal state of a fresh install, so refusing
     * every cross-rail write would leave a paying customer with nothing. The
     * argument is sound and it is served entirely by the branch BELOW this one:
     * a fresh install has nothing on record, and a billable holding no tier
     * still applies (see the next test). What is left once that case is
     * subtracted is the one state the fail-open actually governed: the billable
     * ALREADY holds a tier and no order is published, which is the SHIPPED
     * DEFAULT of this package. There, failing open costs a payer the tier they
     * hold, on a config value nobody has to have touched.
     *
     * The incoming status is ACTIVE deliberately. A cancellation would be
     * refused by rule 2's access half regardless of the direction, so this
     * scenario would keep passing with the empty-order fail-open back in place
     * and would be certifying nothing. A plan swap down to a cheaper tier is
     * `active` on the new tier, so only the direction can decide it.
     */
    public function test_a_cross_rail_write_against_a_held_tier_is_dropped_when_no_tier_order_is_published(): void
    {
        Log::spy();

        config(['magic-starter.billing.tier_order' => []]);

        $grantedAt = Carbon::parse('2026-08-22 12:00:00');

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'starter_business_monthly',
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: 'free',
            status: PlanStatus::ACTIVE,
            provider: BillingProvider::STRIPE,
            eventAt: $grantedAt->copy()->addMinute(),
            providerStatus: 'active',
        );

        $this->assertFalse($applied);

        $billable->refresh();
        $this->assertSame('business', $billable->plan);
        $this->assertSame(BillingProvider::APP_STORE->value, $billable->plan_provider);
        $this->assertSame('starter_business_monthly', $billable->plan_product_id);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($billable): bool {
                return $context['billable_id'] === $billable->id
                    && $context['direction'] === 'unknown'
                    && str_contains($message, 'tier_order');
            });
    }

    /**
     * The other limb of the same branch: with NO tier order published, a
     * cross-rail write against a billable holding NOTHING still applies.
     *
     * This is the case the empty-order fail-open was written for, and it needs
     * its own test rather than sharing one with the drop above: a single
     * scenario covering both limbs would pass with either limb's decision
     * reversed. Absence and unrankability are different facts, and the
     * direction table names them apart for that reason. There is no tier here
     * to take away, so refusing the write could only withhold a tier somebody
     * has already paid for, which is the one error this file never accepts.
     *
     * Cross-rail on purpose: the stored rail is a store whose subscription has
     * lapsed and whose tier was already revoked to absence, and the customer is
     * now buying on the card rail. Rule 2 is the only rule that can see it.
     */
    public function test_a_cross_rail_write_applies_when_nothing_is_held_and_no_tier_order_is_published(): void
    {
        Log::spy();

        config(['magic-starter.billing.tier_order' => []]);

        $lapsedAt = Carbon::parse('2026-08-22 12:00:00');

        $billable = $this->makeBillable([
            'plan' => null,
            'plan_status' => PlanStatus::EXPIRED->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $lapsedAt,
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: 'pro',
            status: PlanStatus::ACTIVE,
            provider: BillingProvider::STRIPE,
            eventAt: $lapsedAt->copy()->addMinute(),
            providerStatus: 'active',
        );

        $this->assertTrue($applied);

        $billable->refresh();
        $this->assertSame('pro', $billable->plan);
        $this->assertSame(BillingProvider::STRIPE->value, $billable->plan_provider);
        $this->assertSame(PlanStatus::ACTIVE->value, $billable->plan_status);
    }

    /**
     * A rail saying NOTHING is owed ranks below every tier, so a cross-rail
     * revocation carrying no plan at all is a downgrade and is dropped.
     *
     * Null is how a revocation says what it means. The alternative is naming a
     * free-tier id, which this package cannot know, and an implementation that
     * invented one got the comparison wrong in both directions at once: SAME
     * against a billable already on that tier, and UNKNOWN in a catalogue that
     * publishes no such row. Both readings let a cross-rail cancellation
     * through, which is the exact loss rule 2 exists to prevent.
     *
     * The asserted direction is the point of the test. Reading an absent
     * incoming plan as merely unrankable would also drop this write, and the
     * two answers stop agreeing the moment nothing is stored: unrankable is a
     * maybe, and a revocation is not a maybe. So this pins `downgrade` rather
     * than pinning the drop alone.
     */
    public function test_an_incoming_null_plan_ranks_as_a_downgrade_against_a_held_tier(): void
    {
        Log::spy();

        $grantedAt = Carbon::parse('2026-08-22 12:00:00');

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'starter_business_monthly',
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: null,
            status: PlanStatus::CANCELED,
            provider: BillingProvider::STRIPE,
            eventAt: $grantedAt->copy()->addMinute(),
            providerStatus: 'canceled',
        );

        $this->assertFalse($applied);

        $billable->refresh();
        $this->assertSame('business', $billable->plan);
        $this->assertSame(BillingProvider::APP_STORE->value, $billable->plan_provider);

        $this->assertDropWasLogged($billable, 'app_store', 'stripe', 'downgrade');
    }

    /**
     * The rail on record saying nothing is owed WRITES the absence, rather than
     * a tier standing in for it.
     *
     * The counterpart to the test above and the reason the parameter is
     * nullable at all: a revocation has to be expressible end to end. Without
     * this, `?string $plan` would only ever be exercised on a path that drops
     * the write, so a persist step that could not store the absence would look
     * proven. Same rail and a strictly newer event, so no rule can decide it
     * and only the write itself is under test.
     */
    public function test_a_revocation_from_the_rail_on_record_writes_an_absent_plan(): void
    {
        Log::spy();

        $grantedAt = Carbon::parse('2026-08-22 12:00:00');

        $billable = $this->makeBillable([
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'price_business_monthly',
        ]);

        $applied = $this->write(
            billable: $billable,
            plan: null,
            status: PlanStatus::CANCELED,
            provider: BillingProvider::STRIPE,
            eventAt: $grantedAt->copy()->addMinute(),
            providerStatus: 'canceled',
        );

        $this->assertTrue($applied);

        $billable->refresh();
        $this->assertNull($billable->plan);
        $this->assertSame(PlanStatus::CANCELED->value, $billable->plan_status);
        $this->assertSame(BillingProvider::STRIPE->value, $billable->plan_provider);
    }

    /**
     * A first grant onto a team with no entitlement on record applies.
     *
     * Nothing is stored, so no write can take anything away, and rule 2 has
     * nothing to protect. Pinned because an implementation that treated an
     * absent stored tier as unrankable-therefore-revocation would refuse every
     * customer's FIRST purchase, which is the loudest possible version of the
     * wrong error direction.
     */
    public function test_a_first_grant_on_a_team_with_no_entitlement_applies(): void
    {
        Log::spy();

        $billable = $this->makeBillable([]);

        $applied = $this->write(
            billable: $billable,
            plan: 'pro',
            status: PlanStatus::TRIALING,
            provider: BillingProvider::STRIPE,
            eventAt: Carbon::parse('2026-08-22 12:00:00'),
            providerStatus: 'trialing',
        );

        $this->assertTrue($applied);

        $billable->refresh();
        $this->assertSame('pro', $billable->plan);
        $this->assertSame(PlanStatus::TRIALING->value, $billable->plan_status);
        $this->assertSame(BillingProvider::STRIPE->value, $billable->plan_provider);
    }

    /**
     * Rule 2 stays armed on a team model that CASTS its plan column to an enum.
     *
     * This is not a hypothetical model. A consuming application owns the tier
     * vocabulary, so casting `plan` to its own backed enum is the natural thing
     * to do, and Eloquent then hands the action an enum instance where the
     * column holds text. An implementation that only accepts a string reads the
     * stored tier as ABSENT on exactly those models, concludes there is nothing
     * to revoke, and lets every cross-rail downgrade through: rule 2 would be
     * present, tested, and disarmed in production.
     *
     * The scenario is otherwise identical to the plain-string cross-rail
     * downgrade above, so the cast is the only difference between them.
     */
    public function test_rule_two_survives_a_plan_column_cast_to_an_enum(): void
    {
        Log::spy();

        $grantedAt = Carbon::parse('2026-08-22 12:00:00');

        $billable = new EnumCastingTeam;

        $billable->forceFill([
            'user_id' => (string) Str::orderedUuid(),
            'name' => 'Ops Team',
            'personal_team' => true,
            'plan' => 'business',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_source_event_at' => $grantedAt,
        ])->save();

        $this->assertInstanceOf(TestPlan::class, $billable->refresh()->plan);

        $applied = $this->write(
            billable: $billable,
            plan: 'free',
            status: PlanStatus::CANCELED,
            provider: BillingProvider::STRIPE,
            eventAt: $grantedAt->copy()->addMinute(),
            providerStatus: 'canceled',
        );

        $this->assertFalse($applied);

        $billable->refresh();
        $this->assertSame(TestPlan::BUSINESS, $billable->plan);
        $this->assertSame(BillingProvider::APP_STORE->value, $billable->plan_provider);

        $this->assertDropWasLogged($billable, 'app_store', 'stripe', 'downgrade');

        // The write side of the same cast, which no rule decides: the action
        // hands the column a plain string because the contract takes a plain
        // string, and the consumer's cast has to accept it. A money path that
        // can read an enum-cast column but not write one would fail on the
        // first genuine renewal rather than in this suite.
        $applied = $this->write(
            billable: $billable,
            plan: 'pro',
            status: PlanStatus::ACTIVE,
            provider: BillingProvider::APP_STORE,
            eventAt: $grantedAt->copy()->addHour(),
            providerStatus: 'RENEWAL',
        );

        $this->assertTrue($applied);
        $this->assertSame(TestPlan::PRO, $billable->refresh()->plan);
    }

    /**
     * Assert the drop was reported with every fact an operator needs to
     * reconstruct the decision: which billable, both rails, both timestamps and
     * the direction the write would have moved the tier.
     */
    protected function assertDropWasLogged(
        Model $billable,
        string $storedProvider,
        string $incomingProvider,
        string $direction,
    ): void {
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use (
                $billable,
                $storedProvider,
                $incomingProvider,
                $direction,
            ): bool {
                return $context['billable_id'] === $billable->id
                    && $context['stored_provider'] === $storedProvider
                    && $context['incoming_provider'] === $incomingProvider
                    && $context['direction'] === $direction
                    && $context['stored_event_at'] !== null
                    && $context['incoming_event_at'] !== null;
            });
    }

    /**
     * Run one entitlement write through the container-resolved contract.
     *
     * Resolved through the contract rather than the class so the test also
     * covers the binding a consumer would override.
     */
    protected function write(
        Model $billable,
        ?string $plan,
        PlanStatus $status,
        BillingProvider $provider,
        CarbonInterface $eventAt,
        // Defaulted HERE and required on the contract, deliberately: a feeder
        // must decide, a test scenario that is not about the distinction should
        // not have to restate it. Most scenarios below model a rail speaking for
        // itself, which is what `true` means.
        bool $authoritative = true,
        ?string $providerStatus = null,
        ?string $productId = null,
        ?CarbonInterface $currentPeriodEnd = null,
        ?bool $renews = null,
        ?CarbonInterface $gracePeriodEndsAt = null,
        ?string $manageUrl = null,
    ): bool {
        return $this->app->make(WritesEntitlement::class)->write(
            billable: $billable,
            plan: $plan,
            status: $status,
            provider: $provider,
            eventAt: $eventAt,
            authoritative: $authoritative,
            providerStatus: $providerStatus,
            productId: $productId,
            currentPeriodEnd: $currentPeriodEnd,
            renews: $renews,
            gracePeriodEndsAt: $gracePeriodEndsAt,
            manageUrl: $manageUrl,
        );
    }

    /**
     * A billable carrying whatever entitlement provenance the scenario needs.
     *
     * A team here because a team is what the fixture models; the action reads it
     * through `Model` and never asks what kind of subject it is.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeBillable(array $attributes): Model
    {
        $billable = new ConcreteTeam;

        $billable->forceFill([
            'user_id' => (string) Str::orderedUuid(),
            'name' => 'Ops Team',
            'personal_team' => true,
            ...$attributes,
        ])->save();

        return $billable;
    }

    /**
     * Remove the artifacts the install assertions publish into the skeleton.
     *
     * Scoped to the PACKAGE's own migration filenames (the same scoping
     * tests/Console/InstallCommandTest.php uses) rather than to everything
     * under database/migrations: the testbench skeleton ships Laravel's own
     * migrations there, and a blanket delete would take those with it.
     *
     * The published lang directory is removed whole, exactly as that file
     * removes it, and for a sharper reason here: a published override WINS over
     * the package path, so a copy left behind by an earlier install answers the
     * catalogue assertions in this file instead of the shipped catalogue they
     * mean to measure. That is not hypothetical, it is how a deleted Turkish
     * line first went undetected here.
     */
    private function cleanupPublishedBillingArtifacts(): void
    {
        File::delete(config_path('magic-starter.php'));

        if (File::isDirectory($this->app->langPath('vendor/magic-starter'))) {
            File::deleteDirectory($this->app->langPath('vendor/magic-starter'));
        }

        $stubs = [
            app_path('Models/User.php'),
            app_path('Policies/TeamPolicy.php'),
            database_path('factories/UserFactory.php'),
        ];

        foreach ($stubs as $stub) {
            File::delete($stub);
        }

        $packageMigrations = glob(__DIR__ . '/../../database/migrations/*.php') ?: [];

        foreach ($packageMigrations as $source) {
            $filename = basename($source);

            $patterns = [
                database_path('migrations/*_' . $filename),
                database_path('migrations/' . $filename),
            ];

            foreach ($patterns as $pattern) {
                foreach (glob($pattern) ?: [] as $published) {
                    File::delete($published);
                }
            }
        }
    }
}

/**
 * A consumer-shaped tier vocabulary, for the enum-cast test above.
 *
 * The package deliberately ships no tier enum (the vocabulary belongs to the
 * consuming application), so the only way to exercise a model that casts `plan`
 * to one is to declare one here. It lives beside its single test rather than in
 * tests/Fixtures because it is not shared: nothing else in the package has any
 * business knowing what `business` means.
 */
enum TestPlan: string
{
    case FREE = 'free';
    case PRO = 'pro';
    case BUSINESS = 'business';
}

/**
 * A team whose plan column is cast to a backed enum, the way a consuming
 * application would cast it to its own.
 */
class EnumCastingTeam extends ConcreteTeam
{
    protected $table = 'teams';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
            'plan' => TestPlan::class,
            'plan_source_event_at' => 'datetime',
        ];
    }
}
