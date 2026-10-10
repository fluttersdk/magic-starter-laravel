<?php

namespace FlutterSdk\MagicStarter\Tests\Models;

use FlutterSdk\MagicStarter\Enums\GrantEndReason;
use FlutterSdk\MagicStarter\Models\BillingGrant;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Locks `billing_grants` to a history of manual plan grants that outlives the
 * operator who made one and the billable it was made for.
 */
class BillingGrantTest extends TestCase
{
    /**
     * @return iterable<string, array{0: bool}>
     */
    public static function keyModes(): iterable
    {
        yield 'uuid keys' => [true];
        yield 'integer keys' => [false];
    }

    #[DataProvider('keyModes')]
    public function test_it_creates_the_table_with_its_columns_and_indexes(bool $useUuids): void
    {
        $this->prepare($useUuids);

        $this->assertTrue(Schema::hasColumns('billing_grants', [
            'id',
            'billable_type',
            'billable_id',
            'plan',
            'reason',
            'expires_at',
            'granted_by',
            'ended_at',
            'end_reason',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasIndex('billing_grants', ['expires_at']));
        $this->assertTrue(Schema::hasIndex('billing_grants', ['billable_type', 'billable_id', 'ended_at']));
    }

    /**
     * The only foreign key is the operator's, and it nulls rather than cascades:
     * the billable column deliberately has none, so a deleted team keeps its row.
     */
    #[DataProvider('keyModes')]
    public function test_the_only_foreign_key_is_the_operator_with_null_on_delete(bool $useUuids): void
    {
        $this->prepare($useUuids);

        $foreignKeys = Schema::getForeignKeys('billing_grants');

        $this->assertCount(1, $foreignKeys);
        $this->assertSame(['granted_by'], $foreignKeys[0]['columns']);
        $this->assertSame('users', $foreignKeys[0]['foreign_table']);
        $this->assertSame('set null', $foreignKeys[0]['on_delete']);
    }

    #[DataProvider('keyModes')]
    public function test_a_row_persists_with_its_casts_and_a_key_of_the_configured_kind(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $operator = $this->makeUser();

        $grant = $this->makeGrant($operator, [
            'expires_at' => '2026-12-01 00:00:00',
            'ended_at' => '2026-11-01 00:00:00',
            'end_reason' => GrantEndReason::REVOKED,
        ]);

        $fresh = BillingGrant::query()->findOrFail($grant->getKey());

        $this->assertSame($useUuids, is_string($fresh->getKey()));
        $this->assertInstanceOf(Carbon::class, $fresh->expires_at);
        $this->assertInstanceOf(Carbon::class, $fresh->ended_at);
        $this->assertSame(GrantEndReason::REVOKED, $fresh->end_reason);
        $this->assertSame('revoked', DB::table('billing_grants')->where('id', $fresh->getKey())->value('end_reason'));
        $this->assertTrue($fresh->grantedBy->is($operator));
        $this->assertTrue($fresh->billable->is($operator));
    }

    #[DataProvider('keyModes')]
    public function test_a_grant_without_an_expiry_or_an_end_reads_back_as_null(bool $useUuids): void
    {
        $this->prepare($useUuids);

        $fresh = BillingGrant::query()->findOrFail($this->makeGrant($this->makeUser())->getKey());

        $this->assertNull($fresh->expires_at);
        $this->assertNull($fresh->ended_at);
        $this->assertNull($fresh->end_reason);
    }

    #[DataProvider('keyModes')]
    public function test_the_open_scope_leaves_out_an_ended_grant(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $user = $this->makeUser();
        $open = $this->makeGrant($user);
        $this->makeGrant($user, [
            'ended_at' => now(),
            'end_reason' => GrantEndReason::SUPERSEDED,
        ]);

        $this->assertSame([$open->getKey()], BillingGrant::query()->open()->pluck('id')->all());
    }

    #[DataProvider('keyModes')]
    public function test_the_product_id_is_the_grant_id_the_entitlement_stores(bool $useUuids): void
    {
        $this->prepare($useUuids);

        $grant = $this->makeGrant($this->makeUser());

        $this->assertSame('grant:' . $grant->getKey(), $grant->productId());
    }

    #[DataProvider('keyModes')]
    public function test_for_billable_binds_the_key_as_a_string_and_leaves_other_billables_out(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $user = $this->makeUser();
        $other = $this->makeUser();
        $mine = $this->makeGrant($user);
        $this->makeGrant($other);

        $this->assertSame([$mine->getKey()], BillingGrant::forBillable($user)->pluck('id')->all());

        // A bare integer binding against a string column misses on PostgreSQL.
        $this->assertContains((string) $user->getKey(), BillingGrant::forBillable($user)->getBindings());
    }

    #[DataProvider('keyModes')]
    public function test_deleting_the_operator_leaves_the_row_with_a_null_granted_by(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $operator = $this->makeUser();
        $grant = $this->makeGrant($operator);

        $operator->delete();

        $fresh = BillingGrant::query()->findOrFail($grant->getKey());
        $this->assertNull($fresh->granted_by);
        $this->assertSame('pro', $fresh->plan);
    }

    #[DataProvider('keyModes')]
    public function test_a_row_outlives_a_billable_that_no_longer_exists(bool $useUuids): void
    {
        $this->prepare($useUuids);

        $grant = $this->makeGrant($this->makeUser(), [
            'billable_type' => 'team',
            'billable_id' => $useUuids ? '0198a1b2-0000-7000-8000-000000000000' : '987654',
        ]);

        $this->assertSame('team', BillingGrant::query()->findOrFail($grant->getKey())->billable_type);
    }

    public function test_the_end_reasons_carry_the_documented_wire_values(): void
    {
        $this->assertSame(
            ['expired', 'revoked', 'superseded'],
            array_map(static fn (GrantEndReason $reason): string => $reason->value, GrantEndReason::cases()),
        );
    }

    public function test_the_migration_is_a_no_op_against_an_existing_table(): void
    {
        Schema::create('billing_grants', function (Blueprint $table): void {
            $table->id();
            $table->string('legacy_column');
        });

        $this->migrate('create_billing_grants_table.php');

        $this->assertTrue(Schema::hasColumn('billing_grants', 'legacy_column'));
        $this->assertFalse(Schema::hasColumn('billing_grants', 'plan'));
    }

    public function test_the_billing_admin_config_keys_default_to_empty_lists(): void
    {
        $this->assertSame([], config('magic-starter.admin.billing_emails'));
        $this->assertSame([], config('magic-starter.billing.revenuecat.sandbox_app_user_ids'));
    }

    /**
     * Pick the key mode, then build the tables the model reads and writes.
     */
    private function prepare(bool $useUuids): void
    {
        config()->set('magic-starter.use_uuids', $useUuids);

        // SQLite ignores foreign keys unless asked, and `nullOnDelete()` is the
        // behaviour under test.
        DB::statement('PRAGMA foreign_keys = ON');

        $this->migrate('create_users_table.php');
        $this->migrate('create_billing_grants_table.php');
    }

    private function makeUser(): ConcreteUser
    {
        return ConcreteUser::query()->create([
            'name' => 'Grant Person',
            'email' => 'grant-' . uniqid() . '@example.com',
            'password' => 'secret',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeGrant(ConcreteUser $user, array $overrides = []): BillingGrant
    {
        return BillingGrant::query()->create(array_merge([
            'billable_type' => $user->getMorphClass(),
            'billable_id' => (string) $user->getKey(),
            'plan' => 'pro',
            'reason' => 'Support goodwill',
            'granted_by' => $user->getKey(),
        ], $overrides));
    }

    private function migrate(string $file): void
    {
        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/' . $file,
            '--realpath' => true,
        ]);
    }
}
