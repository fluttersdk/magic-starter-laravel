<?php

namespace FlutterSdk\MagicStarter\Tests;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Models\SubscriptionItem;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Cashier\SubscriptionBuilder;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Stripe\Subscription as StripeSubscription;

/**
 * Locks the three Cashier migrations the package ships in place of Cashier's
 * five, and the two models that make them usable.
 *
 * Everything here is asserted against the LIVE SCHEMA rather than by re-reading
 * a migration's source, because the defect this file exists to catch is a table
 * whose shape disagrees with the code that reads it, and a source read cannot
 * see that disagreement. Two properties carry the whole set:
 *
 * 1. The billable FOREIGN KEY follows the subject token. Cashier resolves the
 *    relation as `hasMany(..., $this->getForeignKey())`, so `team_id` under the
 *    team subject and `user_id` under the user one, from
 *    `Schema::getColumnListing()` and never from the migration text.
 * 2. The child key follows the PARENT key. A bigint `subscription_items.subscription_id`
 *    against a `char(36)` `subscriptions.id` is exactly what copying Cashier's
 *    stock file produces, and it survives every assertion that only checks the
 *    column is PRESENT.
 *
 * Two things this suite honestly cannot prove, both because of SQLite:
 *
 * - `pm_last_four` is `string(4)` and SQLite ignores the length in `varchar(n)`
 *   entirely, so an over-long value inserts here and would be refused on
 *   PostgreSQL. The length is asserted nowhere below because there is nothing
 *   to assert it against; this package's CI has no PostgreSQL job.
 * - The absence of a foreign-key CONSTRAINT (deliberate, matching Cashier) is
 *   likewise unobservable, since SQLite does not enforce one by default anyway.
 *
 * The billable fixtures are declared at the bottom of this file rather than
 * reused from `tests/Fixtures`, and the reason is the property under test:
 * `getForeignKey()` is `Str::snake(class_basename($this)) . '_id'`, so
 * `ConcreteUser` would answer `concrete_user_id` and the assertion would pin a
 * name no real application ever sees. `User` and `Team` are the basenames a
 * consumer's `App\Models\*` actually carries, which is what makes `user_id` and
 * `team_id` the honest expectations.
 */
class CashierMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The billable subject tokens, as a set.
     *
     * The data provider below is checked AGAINST this constant, which is the
     * disarming limb: a provider that yields `user` twice covers one subject and
     * still reports two passing cases, so the parameterisation would look
     * complete while the team column name went unasserted.
     *
     * @var list<string>
     */
    private const BILLABLE_TOKENS = [
        'team',
        'user',
    ];

    /**
     * The four columns Cashier's `Billable` trait needs on the billable table.
     *
     * @var list<string>
     */
    private const CASHIER_CUSTOMER_COLUMNS = [
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
    ];

    protected function tearDown(): void
    {
        // All three are STATICS on Cashier, so they outlive the application a
        // test booted. Put them back or every later test in the process runs
        // against this file's fixtures.
        Cashier::useCustomerModel('App\\Models\\User');
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // The data provider, and the guard on the data provider
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function billableSubjects(): iterable
    {
        yield 'the user subject' => ['user', 'users', 'user_id', 'team_id'];
        yield 'the team subject' => ['team', 'teams', 'team_id', 'user_id'];
    }

    public function test_the_subject_provider_covers_each_token_exactly_once(): void
    {
        $yielded = array_map(
            static fn (array $case): string => $case[0],
            array_values(iterator_to_array(self::billableSubjects())),
        );

        sort($yielded);

        $expected = self::BILLABLE_TOKENS;
        sort($expected);

        $this->assertSame($expected, $yielded);
    }

    // -------------------------------------------------------------------------
    // The billable foreign key follows the subject
    // -------------------------------------------------------------------------

    /**
     * The `subscriptions` foreign key is the one Cashier will look for.
     *
     * The absent-column half is not decoration: a migration that added BOTH
     * columns would satisfy a presence-only assertion under either token while
     * leaving a permanently null column on every row.
     */
    #[DataProvider('billableSubjects')]
    public function test_the_subscriptions_foreign_key_follows_the_billable_subject(
        string $token,
        string $table,
        string $expectedKey,
        string $otherKey,
    ): void {
        $this->useSubject($token);
        $this->runBillingMigrations();

        $columns = Schema::getColumnListing('subscriptions');

        $this->assertContains($expectedKey, $columns, sprintf(
            'The [%s] subject must give subscriptions a [%s] column.',
            $token,
            $expectedKey,
        ));

        $this->assertNotContains($otherKey, $columns, sprintf(
            'The [%s] subject must not also carry [%s].',
            $token,
            $otherKey,
        ));

        // The name is Cashier's derivation and not this file's opinion, so the
        // relation it will actually resolve through has to agree with the
        // column that exists.
        $model = MagicStarter::billableModel();

        $this->assertSame($expectedKey, (new $model)->getForeignKey());
    }

    // -------------------------------------------------------------------------
    // The Cashier customer columns follow the subject
    // -------------------------------------------------------------------------

    #[DataProvider('billableSubjects')]
    public function test_the_billable_table_carries_the_cashier_customer_columns(
        string $token,
        string $table,
    ): void {
        $this->useSubject($token);
        $this->runBillingMigrations();

        $columns = Schema::getColumnListing($table);

        foreach (self::CASHIER_CUSTOMER_COLUMNS as $column) {
            $this->assertContains($column, $columns, sprintf(
                'The [%s] subject must give [%s] the Cashier column [%s].',
                $token,
                $table,
                $column,
            ));
        }

        // Cashier's lookup is a `where('stripe_id', ...)` on the hot path of
        // every webhook delivery, so the index is part of the column.
        $this->assertTrue(Schema::hasIndex($table, ['stripe_id']));
    }

    /**
     * The team subject must leave `users` alone.
     *
     * Cashier's own migration hardcodes `Schema::table('users')`, so this is the
     * exact defect the package's replacement exists to remove, and it is
     * invisible to the test above: `users` exists under both tokens, so a
     * migration that wrote to it unconditionally would still pass every
     * presence assertion on `teams`.
     */
    public function test_the_team_subject_does_not_touch_the_users_table(): void
    {
        $this->useSubject('team');
        $this->runBillingMigrations();

        $columns = Schema::getColumnListing('users');

        foreach (self::CASHIER_CUSTOMER_COLUMNS as $column) {
            $this->assertNotContains($column, $columns, sprintf(
                'Billing a team must not put the Cashier column [%s] on users.',
                $column,
            ));
        }
    }

    /**
     * Cashier's customer lookup resolves a row by its `stripe_id`.
     *
     * `findBillable()` is a `where('stripe_id', ...)` on `Cashier::$customerModel`,
     * and it is the entry point of every webhook delivery: without the column
     * this migration adds it matches nobody, so money moves and no entitlement
     * is ever written.
     *
     * The customer model is pointed at the resolved billable HERE, and by now the
     * service provider does it too: `Cashier::useCustomerModel()` is the one
     * accessor that has to resolve the billable subject in `register()`, and the
     * provider carries the guard beside it as of the Stripe rail. The local call
     * stays because this test is about the COLUMN and not about the wiring: it
     * has to hold whichever subject the data provider names, on a bare migration
     * run, without depending on a provider phase to have fired first. The wiring
     * has its own coverage in `StripeWebhookTest`, where removing the provider's
     * call turns sixteen tests red.
     */
    #[DataProvider('billableSubjects')]
    public function test_cashier_resolves_the_billable_by_its_stripe_id(
        string $token,
        string $table,
    ): void {
        $this->useSubject($token);
        $this->runBillingMigrations();

        $billable = $this->createBillable('cus_lookup');

        Cashier::useCustomerModel(MagicStarter::billableModel());

        $resolved = Cashier::findBillable('cus_lookup');

        $this->assertInstanceOf(Model::class, $resolved);
        $this->assertSame($billable->getKey(), $resolved->getKey());

        $this->assertNull(Cashier::findBillable('cus_nobody'));
    }

    // -------------------------------------------------------------------------
    // The child key follows the parent key
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: bool, 1: string, 2: string}>
     */
    public static function keyStrategies(): iterable
    {
        // The third and fourth arguments are reference columns on the same
        // table, one textual and one numeric, and they are what makes this test
        // non-vacuous without hardcoding a driver's type name: under UUIDs the
        // key has to read like `stripe_id` and not like `quantity`, and under
        // integers the other way round. Comparing the parent to the child alone
        // would pass on a migration that got BOTH of them wrong the same way.
        yield 'uuid keys' => [true, 'stripe_id', 'quantity'];
        yield 'integer keys' => [false, 'quantity', 'stripe_id'];
    }

    #[DataProvider('keyStrategies')]
    public function test_the_child_key_type_matches_the_parent_key_type(
        bool $useUuids,
        string $likeColumn,
        string $unlikeColumn,
    ): void {
        config(['magic-starter.use_uuids' => $useUuids]);

        $this->useSubject('user');
        $this->runBillingMigrations();

        $parent = $this->columnType('subscriptions', 'id');
        $child = $this->columnType('subscription_items', 'subscription_id');

        $this->assertSame($parent, $child, sprintf(
            'subscription_items.subscription_id is [%s] against a subscriptions.id of [%s]; '
            . 'a child key that does not follow its parent cannot reference it.',
            $child,
            $parent,
        ));

        $this->assertSame($this->columnType('subscriptions', $likeColumn), $parent);
        $this->assertNotSame($this->columnType('subscriptions', $unlikeColumn), $parent);

        // The OUTER key has to follow its parent too: `subscriptions.user_id`
        // against `users.id`. Same helper, so the same switch, but a different
        // pair of tables and therefore a separate way to get it wrong.
        $this->assertSame(
            $this->columnType('users', 'id'),
            $this->columnType('subscriptions', 'user_id'),
        );
    }

    // -------------------------------------------------------------------------
    // The meter columns, folded in rather than shipped as two later alters
    // -------------------------------------------------------------------------

    public function test_the_items_table_carries_both_meter_columns(): void
    {
        $this->useSubject('user');
        $this->runBillingMigrations();

        $columns = Schema::getColumnListing('subscription_items');

        $this->assertContains('meter_id', $columns);
        $this->assertContains('meter_event_name', $columns);
    }

    /**
     * A subscription created through Cashier's OWN builder writes a usable item.
     *
     * This is the only check here that exercises the child table rather than
     * describing it. `SubscriptionBuilder::createSubscription()` writes
     * `meter_id` and `meter_event_name` on every item UNCONDITIONALLY, passing
     * null when the price carries no meter, so a `subscription_items` table
     * missing either column dies on the first real checkout while every
     * shape assertion above still passes.
     *
     * `createSubscription()` is reached by reflection because the public entry
     * points around it (`create()`, `checkout()`) all dial Stripe, and the
     * package has no loopback HTTP server to answer them. The Stripe payload is
     * hand-built with no `recurring->meter`, which is the branch that would
     * otherwise retrieve a meter over the network. Everything from that call
     * inwards is Cashier's own code writing Cashier's own columns, which is the
     * part worth exercising; the row is never inserted by this test.
     */
    public function test_cashiers_builder_writes_an_item_row_with_both_meter_columns(): void
    {
        $this->useSubject('user');
        $this->runBillingMigrations();

        (new MagicStarterServiceProvider($this->app))->register();

        $billable = $this->createBillable('cus_builder');
        Cashier::useCustomerModel($billable::class);

        $builder = new SubscriptionBuilder($billable, 'default', 'price_pro');

        $stripeSubscription = StripeSubscription::constructFrom([
            'id' => 'sub_builder',
            'object' => 'subscription',
            'status' => 'active',
            'items' => [
                'object' => 'list',
                'data' => [
                    [
                        'id' => 'si_builder',
                        'object' => 'subscription_item',
                        'quantity' => 2,
                        'price' => [
                            'id' => 'price_pro',
                            'object' => 'price',
                            'product' => 'prod_pro',
                            'recurring' => ['interval' => 'month'],
                        ],
                    ],
                ],
            ],
        ]);

        $subscription = (new ReflectionMethod(SubscriptionBuilder::class, 'createSubscription'))
            ->invoke($builder, $stripeSubscription);

        $this->assertInstanceOf(Subscription::class, $subscription);
        $this->assertSame('sub_builder', $subscription->stripe_id);
        $this->assertSame($billable->getKey(), $subscription->user_id);

        $item = $subscription->items()->firstOrFail();

        $this->assertInstanceOf(SubscriptionItem::class, $item);
        $this->assertSame('si_builder', $item->stripe_id);
        $this->assertSame($subscription->getKey(), $item->subscription_id);
        $this->assertSame(2, $item->quantity);

        // Read straight from the row, so a column the model happens to cast
        // away cannot hide its absence: these two are what the builder writes
        // unconditionally.
        $row = (array) DB::table('subscription_items')->where('stripe_id', 'si_builder')->sole();

        $this->assertArrayHasKey('meter_id', $row);
        $this->assertArrayHasKey('meter_event_name', $row);
        $this->assertNull($row['meter_id']);
        $this->assertNull($row['meter_event_name']);
    }

    // -------------------------------------------------------------------------
    // Idempotency: the customer columns against a table that already has them
    // -------------------------------------------------------------------------

    /**
     * The customer-columns migration is a total no-op where the columns exist.
     *
     * The fixture below is uptizm's `add_billing_to_teams` shape rather than a
     * generic one, because that is the adopter this has to be a no-op for: all
     * four columns present, and `stripe_id` carrying a UNIQUE index rather than
     * the plain one this migration would add. An index guarded on the COLUMN
     * instead of on the index would try to create a second index over
     * `stripe_id` here and fail.
     */
    public function test_the_customer_columns_migration_is_a_no_op_where_the_columns_exist(): void
    {
        $this->useSubject('user');

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('stripe_id')->nullable()->unique();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });

        $before = Schema::getColumnListing('users');
        $indexesBefore = count(Schema::getIndexes('users'));

        $this->runMigration('add_cashier_customer_columns_to_billable_table.php');
        $this->runMigration('add_cashier_customer_columns_to_billable_table.php');

        $this->assertSame($before, Schema::getColumnListing('users'));

        // Not just "no throw": an index guarded on nothing would leave this
        // table carrying both uptizm's UNIQUE index and a redundant plain one
        // over the same column, since the two get different generated names and
        // neither creation fails.
        $this->assertSame($indexesBefore, count(Schema::getIndexes('users')));
    }

    /**
     * A consumer holding SOME of the columns gets exactly the missing ones.
     *
     * This is the per-column half. A table-level early return would leave a
     * consumer who arrived at `stripe_id` alone without `pm_type`,
     * `pm_last_four` or `trial_ends_at`, and Cashier writes all four together
     * on the first payment method it stores.
     *
     * The fixture's `stripe_id` is deliberately UNINDEXED, which is the third
     * state the index guard has to answer for and the one an index guarded on
     * the COLUMN gets wrong: it would skip the index forever and leave
     * `Cashier::findBillable()` on a sequential scan for every webhook.
     */
    public function test_the_customer_columns_migration_fills_only_the_missing_columns(): void
    {
        $this->useSubject('user');

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('stripe_id')->nullable();
            $table->timestamps();
        });

        $this->assertFalse(Schema::hasIndex('users', ['stripe_id']));

        $this->runMigration('add_cashier_customer_columns_to_billable_table.php');

        $columns = Schema::getColumnListing('users');

        foreach (self::CASHIER_CUSTOMER_COLUMNS as $column) {
            $this->assertContains($column, $columns);
        }

        $this->assertTrue(Schema::hasIndex('users', ['stripe_id']));
    }

    /**
     * The two create migrations leave an existing table alone.
     *
     * An adopter who already ran Cashier's published `create_subscriptions_table`
     * has a table with rows in it, and `Schema::create` on an existing table
     * throws rather than skipping.
     */
    public function test_the_create_migrations_are_no_ops_against_existing_tables(): void
    {
        $this->useSubject('user');
        $this->runBillingMigrations();
        $this->runBillingMigrations();

        $this->assertTrue(Schema::hasTable('subscriptions'));
        $this->assertTrue(Schema::hasTable('subscription_items'));
    }

    /**
     * An absent billable table is refused rather than silently skipped.
     *
     * Same reasoning as the provenance migration's own refusal: a table the
     * token names and the database does not have is a configuration mistake,
     * and a `migrate` reporting success while adding nothing surfaces as a
     * missing-column error at the first checkout instead.
     *
     * The message is asserted and not only the exception class, because
     * `Schema::table` on an absent table throws a `QueryException`, which is a
     * `RuntimeException` too: only the config key in the message distinguishes
     * a refusal from a database accident.
     */
    public function test_the_customer_columns_migration_refuses_an_absent_billable_table(): void
    {
        $this->useSubject('user');

        $this->assertFalse(Schema::hasTable('users'));

        try {
            $this->runMigration('add_cashier_customer_columns_to_billable_table.php');

            $this->fail('An absent billable table must fail the migration, not pass it.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('users', $exception->getMessage());
            $this->assertStringContainsString('magic-starter.billing.billable', $exception->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // The models, and the phase they are registered in
    // -------------------------------------------------------------------------

    /**
     * The subscription models are handed to Cashier from register(), not boot().
     *
     * Cashier reads both statics from its own boot() onwards, and every
     * provider's register() runs before any provider's boot(), so this is the
     * last phase that still lands (laravel/cashier#1739). The statics are reset
     * to Cashier's own classes first, or the assertion would pass on whatever a
     * previous test left behind.
     */
    public function test_the_provider_registers_the_package_subscription_models_from_register(): void
    {
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);

        config(['magic-starter.features' => [Features::billing()]]);

        (new MagicStarterServiceProvider($this->app))->register();

        $this->assertSame(Subscription::class, Cashier::$subscriptionModel);
        $this->assertSame(SubscriptionItem::class, Cashier::$subscriptionItemModel);
    }

    public function test_cashier_keeps_its_own_subscription_models_when_billing_is_disabled(): void
    {
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);

        config(['magic-starter.features' => []]);

        (new MagicStarterServiceProvider($this->app))->register();

        $this->assertSame(CashierSubscription::class, Cashier::$subscriptionModel);
        $this->assertSame(CashierSubscriptionItem::class, Cashier::$subscriptionItemModel);
    }

    /**
     * The models name the tables the migrations create, and nothing else.
     *
     * Two literals in two files that have to agree, so one of them asserts
     * against the LIVE schema rather than against the other literal.
     */
    public function test_the_models_name_the_tables_the_migrations_create(): void
    {
        $this->useSubject('user');
        $this->runBillingMigrations();

        $this->assertTrue(Schema::hasTable((new Subscription)->getTable()));
        $this->assertTrue(Schema::hasTable((new SubscriptionItem)->getTable()));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Point the package at one billable subject and create its base tables.
     *
     * The models are swapped for this file's own `User` and `Team` so that
     * `getForeignKey()` answers what a consumer's `App\Models\*` answers; see
     * the class docblock.
     */
    private function useSubject(string $token): void
    {
        MagicStarter::useUserModel(User::class);
        MagicStarter::useTeamModel(Team::class);

        config([
            'magic-starter.features' => [Features::teams(), Features::billing()],
            'magic-starter.billing.billable' => $token,
        ]);
    }

    /**
     * Run the three shipped Cashier migrations the way a consumer's `migrate`
     * does, over the base tables they land on, in declaration order.
     */
    private function runBillingMigrations(): void
    {
        if (! Schema::hasTable('users')) {
            $this->runMigration('create_users_table.php');
            $this->runMigration('create_teams_table.php');
        }

        $this->runMigration('add_cashier_customer_columns_to_billable_table.php');
        $this->runMigration('create_subscriptions_table.php');
        $this->runMigration('create_subscription_items_table.php');
    }

    private function runMigration(string $filename): void
    {
        $migration = require __DIR__ . '/../database/migrations/' . $filename;

        $migration->up();
    }

    /**
     * The resolved billable, holding a Stripe customer id.
     */
    private function createBillable(string $stripeId): Model
    {
        $model = MagicStarter::billableModel();

        if ($model === Team::class) {
            $owner = User::query()->create([
                'name' => 'Owner',
                'email' => 'owner@example.com',
                'password' => 'secret',
            ]);

            return Team::query()->create([
                'name' => 'Acme',
                'user_id' => $owner->getKey(),
                'stripe_id' => $stripeId,
            ]);
        }

        return User::query()->create([
            'name' => 'Payer',
            'email' => 'payer@example.com',
            'password' => 'secret',
            'stripe_id' => $stripeId,
        ]);
    }

    /**
     * The declared type of one column, read from the live schema.
     */
    private function columnType(string $table, string $column): string
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return (string) $definition['type'];
            }
        }

        $this->fail(sprintf('Column [%s] is absent from [%s].', $column, $table));
    }
}

/**
 * A billable user whose class basename is the one a consumer actually ships.
 *
 * `Str::snake(class_basename($this)) . '_id'` is how Cashier names the column,
 * so the basename is the assertion: `ConcreteUser` would answer
 * `concrete_user_id` and pin a name no application ever sees.
 */
class User extends ConcreteUser
{
    use Billable;

    protected $table = 'users';
}

/**
 * A billable team, for the same reason.
 */
class Team extends ConcreteTeam
{
    use Billable;

    protected $table = 'teams';

    // Declared rather than inherited: ConcreteTeam's $fillable does not carry
    // `stripe_id`, and $fillable wins over $guarded whenever both are set, so
    // an inherited list would silently drop the very column under test.
    protected $fillable = [
        'name',
        'personal_team',
        'user_id',
        'stripe_id',
    ];
}
