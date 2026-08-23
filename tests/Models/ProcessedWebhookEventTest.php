<?php

namespace FlutterSdk\MagicStarter\Tests\Models;

use FlutterSdk\MagicStarter\Models\ProcessedWebhookEvent;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Locks the two properties {@see ProcessedWebhookEvent} exists to guarantee:
 * the unique `event_id` column is the actual dedup mechanism, and the
 * migration that creates the table is a no-op on a second run.
 *
 * The transaction half of `recordIfNew()` (the inner `DB::transaction()` that
 * scopes the insert in a SAVEPOINT) is honestly untestable here: SQLite does
 * not poison the outer transaction on a caught unique violation the way
 * PostgreSQL does, so `test_record_if_new_leaves_the_surrounding_transaction_usable()`
 * proves the surrounding transaction survives on SQLite, not that the
 * SAVEPOINT is load-bearing. This package's CI has no PostgreSQL job.
 */
class ProcessedWebhookEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runMigration();
    }

    public function test_record_if_new_returns_true_on_first_delivery_and_false_on_a_replay(): void
    {
        $this->assertTrue(ProcessedWebhookEvent::recordIfNew('evt_123', 'checkout.session.completed'));
        $this->assertFalse(ProcessedWebhookEvent::recordIfNew('evt_123', 'checkout.session.completed'));

        $this->assertSame(1, ProcessedWebhookEvent::query()->where('event_id', 'evt_123')->count());
    }

    /**
     * The mutant this test exists to detect: a migration that drops
     * `->unique()` from `event_id` still returns true. Without the unique
     * index nothing raises `UniqueConstraintViolationException`, so
     * `recordIfNew()` always returns true and every re-delivered webhook is
     * processed again, silently.
     *
     * This is asserted against the LIVE schema rather than by re-reading the
     * migration source, because the schema is what the constraint actually
     * depends on.
     */
    public function test_event_id_column_carries_a_unique_index(): void
    {
        $this->assertTrue(
            Schema::hasColumn('processed_webhook_events', 'event_id'),
            'The dedup column must exist before its uniqueness can be asserted.',
        );

        // Insert the same event id twice at the query builder level, bypassing
        // recordIfNew()'s own catch, so this test is not itself protected by
        // the very code path it is trying to prove is load-bearing.
        DB::table('processed_webhook_events')->insert([
            'id' => (string) Str::uuid(),
            'event_id' => 'evt_unique_check',
            'type' => 'checkout.session.completed',
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('processed_webhook_events')->insert([
            'id' => (string) Str::uuid(),
            'event_id' => 'evt_unique_check',
            'type' => 'checkout.session.completed',
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_record_if_new_leaves_the_surrounding_transaction_usable(): void
    {
        DB::transaction(function (): void {
            $this->assertTrue(ProcessedWebhookEvent::recordIfNew('evt_savepoint', 'invoice.paid'));
            $this->assertFalse(ProcessedWebhookEvent::recordIfNew('evt_savepoint', 'invoice.paid'));

            // The surrounding transaction must still accept further writes
            // after the caught violation, which is exactly what the inner
            // DB::transaction() SAVEPOINT is there to guarantee.
            ProcessedWebhookEvent::query()->create([
                'event_id' => 'evt_after_replay',
                'type' => 'invoice.paid',
                'processed_at' => now(),
            ]);
        });

        $this->assertSame(2, ProcessedWebhookEvent::query()->count());
    }

    public function test_migration_is_a_no_op_against_an_existing_table(): void
    {
        // The table already exists from setUp()'s first run. Running it a
        // second time in the same test must not throw "table already exists".
        $this->runMigration();

        $this->assertTrue(Schema::hasTable('processed_webhook_events'));
    }

    private function runMigration(): void
    {
        $migration = require __DIR__ . '/../../database/migrations/create_processed_webhook_events_table.php';
        $migration->up();
    }
}
