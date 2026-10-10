<?php

namespace FlutterSdk\MagicStarter\Tests\Models;

use FlutterSdk\MagicStarter\Models\BillingTrial;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Locks the `billing_trials` table to what the trial anti-abuse record needs:
 * one row per trialing Stripe subscription that outlives both the user and the
 * billable it was opened for.
 */
class BillingTrialTest extends TestCase
{
    /**
     * @return array<string, array{bool}>
     */
    public static function keyModes(): array
    {
        return [
            'uuid keys' => [
                true,
            ],
            'integer keys' => [
                false,
            ],
        ];
    }

    #[DataProvider('keyModes')]
    public function test_it_creates_the_table_with_its_columns_and_indexes(bool $useUuids): void
    {
        $this->prepare($useUuids);

        $this->assertTrue(Schema::hasColumns('billing_trials', [
            'id',
            'user_id',
            'billable_type',
            'billable_id',
            'stripe_subscription_id',
            'subscription_created_at',
            'card_fingerprint',
            'checked_at',
            'refused_at',
            'refusal_reason',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasIndex('billing_trials', ['stripe_subscription_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex('billing_trials', ['card_fingerprint']));
        $this->assertTrue(Schema::hasIndex('billing_trials', ['billable_type', 'billable_id']));
        $this->assertTrue(Schema::hasIndex('billing_trials', ['user_id']));
    }

    /**
     * The only foreign key is the user's, and it nulls rather than cascades: the
     * billable column deliberately has none, so a deleted team keeps its row.
     */
    #[DataProvider('keyModes')]
    public function test_the_only_foreign_key_is_the_user_with_null_on_delete(bool $useUuids): void
    {
        $this->prepare($useUuids);

        $foreignKeys = Schema::getForeignKeys('billing_trials');

        $this->assertCount(1, $foreignKeys);
        $this->assertSame(['user_id'], $foreignKeys[0]['columns']);
        $this->assertSame('users', $foreignKeys[0]['foreign_table']);
        $this->assertSame('set null', $foreignKeys[0]['on_delete']);
    }

    #[DataProvider('keyModes')]
    public function test_deleting_the_user_leaves_the_row_with_a_null_user_id(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $user = $this->makeUser();
        $trial = $this->makeTrial($user);

        $user->delete();

        $trial = BillingTrial::query()->findOrFail($trial->getKey());
        $this->assertNull($trial->user_id);
        $this->assertSame('card_abc', $trial->card_fingerprint);
    }

    #[DataProvider('keyModes')]
    public function test_a_row_outlives_a_billable_that_no_longer_exists(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $user = $this->makeUser();

        // The billable id points at nothing: with a foreign key this insert would
        // be refused, and a team deleted later would take the row with it.
        $trial = $this->makeTrial($user, [
            'billable_type' => 'team',
            'billable_id' => $useUuids ? '0198a1b2-0000-7000-8000-000000000000' : 987654,
        ]);

        $this->assertSame('team', BillingTrial::query()->findOrFail($trial->getKey())->billable_type);
    }

    #[DataProvider('keyModes')]
    public function test_stripe_subscription_id_is_unique(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $user = $this->makeUser();
        $this->makeTrial($user, ['stripe_subscription_id' => 'sub_same']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makeTrial($user, ['stripe_subscription_id' => 'sub_same']);
    }

    public function test_live_scope_leaves_out_refused_rows(): void
    {
        $this->prepare(true);
        $user = $this->makeUser();
        $live = $this->makeTrial($user, ['stripe_subscription_id' => 'sub_live']);
        $this->makeTrial($user, [
            'stripe_subscription_id' => 'sub_refused',
            'refused_at' => now(),
            'refusal_reason' => 'card_reused',
        ]);

        $ids = BillingTrial::query()->live()->pluck('id')->all();

        $this->assertSame([$live->getKey()], $ids);
    }

    public function test_it_casts_its_timestamps_and_resolves_the_user(): void
    {
        $this->prepare(true);
        $user = $this->makeUser();
        $trial = $this->makeTrial($user, ['checked_at' => '2026-10-01 10:00:00']);

        $trial = BillingTrial::query()->findOrFail($trial->getKey());

        $this->assertInstanceOf(Carbon::class, $trial->subscription_created_at);
        $this->assertInstanceOf(Carbon::class, $trial->checked_at);
        $this->assertNull($trial->refused_at);
        $this->assertTrue($trial->user->is($user));
    }

    public function test_the_migration_is_a_no_op_against_an_existing_table(): void
    {
        Schema::create('billing_trials', function (Blueprint $table): void {
            $table->id();
            $table->string('legacy_column');
        });

        $this->migrate('create_billing_trials_table.php');

        $this->assertTrue(Schema::hasColumn('billing_trials', 'legacy_column'));
        $this->assertFalse(Schema::hasColumn('billing_trials', 'stripe_subscription_id'));
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
        $this->migrate('create_billing_trials_table.php');
    }

    private function makeUser(): ConcreteUser
    {
        return ConcreteUser::query()->create([
            'name' => 'Trial Person',
            'email' => 'trial-' . uniqid() . '@example.com',
            'password' => 'secret',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTrial(ConcreteUser $user, array $overrides = []): BillingTrial
    {
        return BillingTrial::query()->create(array_merge([
            'user_id' => $user->getKey(),
            'billable_type' => $user->getMorphClass(),
            'billable_id' => $user->getKey(),
            'stripe_subscription_id' => 'sub_' . uniqid(),
            'subscription_created_at' => now(),
            'card_fingerprint' => 'card_abc',
        ], $overrides));
    }

    /**
     * Run one of the package's migration stubs by file name.
     */
    private function migrate(string $file): void
    {
        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/' . $file,
            '--realpath' => true,
        ]);
    }
}
