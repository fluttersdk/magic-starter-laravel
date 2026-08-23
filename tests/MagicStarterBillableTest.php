<?php

namespace FlutterSdk\MagicStarter\Tests;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Locks the billable subject: WHICH KIND of thing this application bills.
 *
 * The subject is a closed token, `'user'` or `'team'`, and never a model class
 * name: the package has to know what kind of thing it is writing an entitlement
 * to, and a class name cannot answer that, since a consumer's `App\Models\Account`
 * could be either. Everything downstream of the token (the resolved model, its
 * table, and in a later plan Cashier's customer model) has to name the same
 * thing, so the token is the one place the decision is made.
 *
 * Three states are pinned, and the two-conjunct boot refusal gets one test per
 * limb rather than one covering both: `'team'` with teams off must be refused,
 * `'team'` with teams on must not, and `'user'` with teams off must not. A single
 * test covering the refusal alone passes with either conjunct dropped.
 */
class MagicStarterBillableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The eight provenance columns plus the two entitlement columns the
     * migration is responsible for, whichever table it resolves.
     *
     * @var list<string>
     */
    private const ENTITLEMENT_COLUMNS = [
        'plan',
        'plan_status',
        'plan_provider',
        'plan_source_event_at',
        'plan_provider_status',
        'plan_product_id',
        'plan_current_period_end',
        'plan_renews',
        'plan_grace_period_ends_at',
        'plan_manage_url',
    ];

    /**
     * The shipped default is the USER subject, and it resolves the user model.
     *
     * `'user'` rather than `'team'` because the teams feature ships off on both
     * halves of this stack, so a `'team'` default would be wrong on every fresh
     * install rather than on an exotic one.
     */
    public function test_the_billable_token_defaults_to_the_user_subject(): void
    {
        $this->assertSame('user', config('magic-starter.billing.billable'));
        $this->assertSame(ConcreteUser::class, MagicStarter::billableModel());
    }

    /**
     * A config published BEFORE this key existed still resolves the user model.
     *
     * `mergeConfigFrom` is a shallow `array_merge`, so a published
     * `config/magic-starter.php` whose `billing` array carries only `tier_order`
     * replaces the package's whole `billing` block and leaves no `billable` at
     * all. That branch is reachable for every existing adopter and is reached by
     * nothing else in this suite, which is exactly the shape a `??` right-hand
     * side is usually left untested in.
     */
    public function test_a_billing_config_without_the_key_falls_back_to_the_user_subject(): void
    {
        config(['magic-starter.billing' => ['tier_order' => []]]);

        $this->assertNull(config('magic-starter.billing.billable'));
        $this->assertSame(ConcreteUser::class, MagicStarter::billableModel());

        (new MagicStarterServiceProvider($this->app))->boot();
    }

    /**
     * The team token resolves the team model, through the existing accessor.
     *
     * `billableModel()` composes `userModel()` and `teamModel()` rather than
     * resolving anything itself, so a consumer that published its own
     * `App\Models\Team` keeps being auto-detected exactly as before.
     */
    public function test_the_team_token_resolves_the_team_model(): void
    {
        config([
            'magic-starter.features' => [Features::teams(), Features::billing()],
            'magic-starter.billing.billable' => 'team',
        ]);

        $this->assertSame(ConcreteTeam::class, MagicStarter::billableModel());
    }

    /**
     * A token outside the two accepted values is refused, naming the key.
     *
     * There is no third arm and no fallback to a default: a token nobody
     * recognises means the adopter believes they are billing something this
     * package will never write to, and silently billing a user instead is how
     * that belief survives to the first payment.
     */
    public function test_an_unsupported_token_is_refused_naming_the_key(): void
    {
        config(['magic-starter.billing.billable' => 'account']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/magic-starter\.billing\.billable/');

        MagicStarter::billableModel();
    }

    /**
     * Limb one of the refusal: the team subject with the teams feature OFF.
     *
     * With teams off this package registers no personal-team listener, so there
     * is no team at all, not even a personal one, and a team-billing app would
     * have nothing to write an entitlement to. The provider refuses to boot
     * rather than letting that surface as a missing row at the first payment.
     */
    public function test_booting_with_the_team_token_and_teams_off_is_refused(): void
    {
        config([
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing.billable' => 'team',
        ]);

        try {
            (new MagicStarterServiceProvider($this->app))->boot();

            $this->fail('Booting a team billable with the teams feature off must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('magic-starter.billing.billable', $exception->getMessage());
            $this->assertStringContainsString(Features::teams(), $exception->getMessage());
        }
    }

    /**
     * An unrecognised token is refused at BOOT, not at first resolution.
     *
     * `test_an_unsupported_token_is_refused_naming_the_key` above proves the
     * throw through `billableModel()`, which is the LAZY path: nothing in
     * production resolves the billable until something needs it. So a typo like
     * `teams` used to boot cleanly and surface at whatever first asked, and from
     * the moment a rail ships that is a payment webhook, which means money taken
     * and no entitlement written with a plural as the cause. This is the same
     * shape of deferred failure the provenance migration was rescued from, so the
     * guard validates the whole token set rather than only the `team` branch.
     */
    public function test_booting_with_an_unrecognised_token_is_refused(): void
    {
        config([
            'magic-starter.features' => [Features::billing()],
            // A plural, which is the typo this catches, and which the team branch
            // alone would have waved through.
            'magic-starter.billing.billable' => 'teams',
        ]);

        try {
            (new MagicStarterServiceProvider($this->app))->boot();

            $this->fail('Booting an unrecognised billable token must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('magic-starter.billing.billable', $exception->getMessage());
            $this->assertStringContainsString('teams', $exception->getMessage());
        }
    }

    /**
     * Limb two: the same token with the teams feature ON boots and resolves.
     */
    public function test_booting_with_the_team_token_and_teams_on_is_allowed(): void
    {
        config([
            'magic-starter.features' => [Features::teams(), Features::billing()],
            'magic-starter.billing.billable' => 'team',
        ]);

        (new MagicStarterServiceProvider($this->app))->boot();

        $this->assertSame(ConcreteTeam::class, MagicStarter::billableModel());
    }

    /**
     * Limb three: the USER token with teams off is the shipped shape and boots.
     *
     * Without this the refusal could be an unconditional "teams must be on"
     * check and still look correct, which would make billing impossible in
     * exactly the app this default exists for.
     */
    public function test_booting_with_the_user_token_and_teams_off_is_allowed(): void
    {
        config([
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing.billable' => 'user',
        ]);

        (new MagicStarterServiceProvider($this->app))->boot();

        $this->assertSame(ConcreteUser::class, MagicStarter::billableModel());
    }

    /**
     * The migration follows the token: on the user subject it alters `users`.
     *
     * Asserted against a table the old shape could never have touched, since it
     * hardcoded `teams` in every guard. This is the positive half of that
     * defect; the negative half is the test below.
     */
    public function test_the_provenance_migration_follows_the_token_to_the_resolved_table(): void
    {
        config([
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing.billable' => 'user',
        ]);

        Schema::create('users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        $this->migration()->up();

        foreach (self::ENTITLEMENT_COLUMNS as $column) {
            $this->assertTrue(
                Schema::hasColumn('users', $column),
                sprintf('Expected the migration to add [%s] to the resolved table.', $column),
            );
        }

        $this->assertFalse(
            Schema::hasTable('teams'),
            'The migration must not reach for a teams table the token never named.',
        );
    }

    /**
     * A resolved table that does not exist THROWS rather than doing nothing.
     *
     * This is the defect the rename closes, and it would have passed silently
     * under the old `hasTable` early return: an adopter billing a user got a
     * `migrate` that reported success, added no column, and surfaced as a
     * missing-column error on the first payment. A wrong table is a
     * configuration mistake, and `migrate` is the cheapest place to say so.
     *
     * The message is asserted and not just the exception CLASS, because a class
     * assertion cannot fail here: measured against the restored early return,
     * `Schema::table` on the absent table throws a QueryException, which is a
     * RuntimeException too, so the test passed the mutant. Only the config key
     * in the message distinguishes a refusal from a database accident.
     */
    public function test_the_provenance_migration_refuses_a_resolved_table_that_does_not_exist(): void
    {
        config([
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing.billable' => 'user',
        ]);

        $this->assertFalse(Schema::hasTable('users'));

        try {
            $this->migration()->up();

            $this->fail('An absent billable table must fail the migration, not pass it.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('users', $exception->getMessage());
            $this->assertStringContainsString('magic-starter.billing.billable', $exception->getMessage());
        }
    }

    /**
     * Load the shipped provenance migration the way a consumer's `migrate` does.
     */
    private function migration(): object
    {
        return require __DIR__ . '/../database/migrations/add_entitlement_provenance_to_billable_table.php';
    }
}
