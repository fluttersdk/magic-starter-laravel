<?php

namespace FlutterSdk\MagicStarter\Tests\Models;

use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Locks `billing_events` to an append-only history that outlives its actor and
 * its billable, and the model to refusing every update and delete.
 */
class BillingEventTest extends TestCase
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

        $this->assertTrue(Schema::hasColumns('billing_events', [
            'id',
            'type',
            'source',
            'provider',
            'billable_type',
            'billable_id',
            'actor_user_id',
            'reason',
            'external_id',
            'properties',
            'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('billing_events', 'updated_at'));
        $this->assertTrue(Schema::hasIndex('billing_events', ['type']));
        $this->assertTrue(Schema::hasIndex('billing_events', ['external_id']));
        $this->assertTrue(Schema::hasIndex('billing_events', ['billable_type', 'billable_id', 'created_at']));
    }

    public function test_running_the_migration_twice_is_a_no_op(): void
    {
        $this->prepare(true);

        $this->migrate('create_billing_events_table.php');

        $this->assertTrue(Schema::hasTable('billing_events'));
    }

    #[DataProvider('keyModes')]
    public function test_a_row_persists_with_its_casts_and_a_key_of_the_configured_kind(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $user = $this->makeUser();

        $event = BillingEvent::query()->create([
            'type' => BillingEventType::ENTITLEMENT_APPLIED,
            'source' => BillingSource::WEBHOOK,
            'provider' => BillingProvider::STRIPE,
            'billable_type' => $user->getMorphClass(),
            'billable_id' => $user->getKey(),
            'actor_user_id' => $user->getKey(),
            'reason' => 'plan_changed',
            'external_id' => 'evt_123',
            'properties' => ['plan_status' => 'active'],
        ]);

        $fresh = BillingEvent::query()->findOrFail($event->getKey());

        $this->assertSame($useUuids, is_string($fresh->getKey()));
        $this->assertSame(BillingEventType::ENTITLEMENT_APPLIED, $fresh->type);
        $this->assertSame(BillingSource::WEBHOOK, $fresh->source);
        $this->assertSame(BillingProvider::STRIPE, $fresh->provider);
        $this->assertSame(['plan_status' => 'active'], $fresh->properties);
        $this->assertSame((string) $user->getKey(), $fresh->billable_id);
        $this->assertSame((string) $user->getKey(), (string) $fresh->actor_user_id);
        $this->assertInstanceOf(Carbon::class, $fresh->created_at);
        $this->assertTrue($fresh->billable->is($user));
        $this->assertTrue($fresh->actor->is($user));
    }

    #[DataProvider('keyModes')]
    public function test_a_refusal_may_carry_no_billable_no_actor_and_no_provider(bool $useUuids): void
    {
        $this->prepare($useUuids);

        $event = BillingEvent::query()->create([
            'type' => BillingEventType::REQUEST_REFUSED,
            'source' => BillingSource::REQUEST,
            'reason' => 'no_billable',
        ]);

        $fresh = BillingEvent::query()->findOrFail($event->getKey());

        $this->assertNull($fresh->provider);
        $this->assertNull($fresh->billable_type);
        $this->assertNull($fresh->billable_id);
        $this->assertNull($fresh->actor_user_id);
        $this->assertNull($fresh->billable);
        $this->assertNull($fresh->actor);
    }

    #[DataProvider('keyModes')]
    public function test_a_raw_store_id_lands_in_the_billable_key_in_either_mode(bool $useUuids): void
    {
        $this->prepare($useUuids);

        $event = BillingEvent::query()->create([
            'type' => BillingEventType::DELIVERY_REFUSED,
            'source' => BillingSource::WEBHOOK,
            'provider' => BillingProvider::APP_STORE,
            'billable_type' => 'user',
            'billable_id' => '$RCAnonymousID:abc123',
        ]);

        $this->assertSame(
            '$RCAnonymousID:abc123',
            BillingEvent::query()->findOrFail($event->getKey())->billable_id,
        );
    }

    #[DataProvider('keyModes')]
    public function test_updating_a_row_throws_and_changes_nothing(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $event = $this->makeEvent();

        try {
            $event->update(['reason' => 'rewritten']);
            $this->fail('An update must be refused.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertSame('original', BillingEvent::query()->findOrFail($event->getKey())->reason);
    }

    #[DataProvider('keyModes')]
    public function test_deleting_a_model_throws_and_keeps_the_row(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $event = $this->makeEvent();

        try {
            $event->delete();
            $this->fail('A delete must be refused.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertSame(1, BillingEvent::query()->count());
    }

    #[DataProvider('keyModes')]
    public function test_the_query_builder_delete_the_prune_command_uses_still_works(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $this->makeEvent();
        $this->makeEvent();

        $deleted = BillingEvent::query()->where('reason', 'original')->delete();

        $this->assertSame(2, $deleted);
        $this->assertSame(0, BillingEvent::query()->count());
    }

    #[DataProvider('keyModes')]
    public function test_deleting_the_actor_nulls_the_pointer_and_keeps_the_row(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $user = $this->makeUser();
        $event = $this->makeEvent(['actor_user_id' => $user->getKey()]);

        $user->delete();

        $fresh = BillingEvent::query()->findOrFail($event->getKey());
        $this->assertNull($fresh->actor_user_id);
        $this->assertSame('original', $fresh->reason);
    }

    #[DataProvider('keyModes')]
    public function test_a_row_outlives_a_billable_that_no_longer_exists(bool $useUuids): void
    {
        $this->prepare($useUuids);

        $event = $this->makeEvent([
            'billable_type' => 'team',
            'billable_id' => $useUuids ? '0198a1b2-0000-7000-8000-000000000000' : '987654',
        ]);

        $this->assertSame('team', BillingEvent::query()->findOrFail($event->getKey())->billable_type);
    }

    public function test_only_the_refusal_types_report_a_refusal(): void
    {
        $refusals = [
            BillingEventType::ENTITLEMENT_DROPPED,
            BillingEventType::REQUEST_REFUSED,
            BillingEventType::DELIVERY_REFUSED,
            BillingEventType::TRIAL_REFUSED,
        ];

        foreach (BillingEventType::cases() as $type) {
            $this->assertSame(in_array($type, $refusals, true), $type->isRefusal(), $type->value);
        }
    }

    public function test_the_enums_carry_the_documented_wire_values(): void
    {
        $this->assertSame(
            [
                'entitlement_applied',
                'entitlement_dropped',
                'checkout_started',
                'subscription_swapped',
                'subscription_cancelled',
                'portal_opened',
                'request_refused',
                'delivery_refused',
                'trial_recorded',
                'trial_refused',
                'trial_cancelled',
                'trial_refusal_withdrawn',
                'grant_added',
                'grant_revoked',
                'grant_expired',
                'trial_extended',
                'trial_ended',
                'subscription_resumed',
                'invoice_refunded',
                'entitlement_synced',
            ],
            array_map(static fn (BillingEventType $type): string => $type->value, BillingEventType::cases()),
        );
        $this->assertSame(
            ['webhook', 'reconcile', 'request', 'trial_check', 'admin'],
            array_map(static fn (BillingSource $source): string => $source->value, BillingSource::cases()),
        );
    }

    public function test_the_retention_keys_default_to_ninety_days_for_webhooks_and_forever_for_events(): void
    {
        $this->assertNull(config('magic-starter.billing.log_channel'));
        $this->assertSame(90, config('magic-starter.billing.webhook_retention_days'));
        $this->assertNull(config('magic-starter.billing.events_retention_days'));
    }

    public function test_the_processed_at_index_is_added_once_and_a_missing_table_is_a_no_op(): void
    {
        // Run through `up()` directly: `artisan migrate` records the first run
        // and would skip the later ones this test is about.
        $this->runMigration('add_processed_at_index_to_processed_webhook_events_table.php');
        $this->assertFalse(Schema::hasTable('processed_webhook_events'));

        $this->runMigration('create_processed_webhook_events_table.php');
        $this->assertFalse(Schema::hasIndex('processed_webhook_events', ['processed_at']));

        $this->runMigration('add_processed_at_index_to_processed_webhook_events_table.php');
        $this->assertTrue(Schema::hasIndex('processed_webhook_events', ['processed_at']));

        $this->runMigration('add_processed_at_index_to_processed_webhook_events_table.php');
        $this->assertTrue(Schema::hasIndex('processed_webhook_events', ['processed_at']));
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
        $this->migrate('create_billing_events_table.php');
    }

    private function makeUser(): ConcreteUser
    {
        return ConcreteUser::query()->create([
            'name' => 'Billing Person',
            'email' => 'billing-' . uniqid() . '@example.com',
            'password' => 'secret',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEvent(array $overrides = []): BillingEvent
    {
        return BillingEvent::query()->create(array_merge([
            'type' => BillingEventType::CHECKOUT_STARTED,
            'source' => BillingSource::REQUEST,
            'reason' => 'original',
        ], $overrides));
    }

    private function runMigration(string $file): void
    {
        (require __DIR__ . '/../../database/migrations/' . $file)->up();
    }

    private function migrate(string $file): void
    {
        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/' . $file,
            '--realpath' => true,
        ]);
    }
}
