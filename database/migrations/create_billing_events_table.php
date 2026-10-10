<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing events table: the append-only history of every billing outcome the
 * package reached, one row per outcome (an entitlement applied or dropped, a
 * checkout started, a delivery or a request refused, a trial recorded, ...).
 * It answers "what happened to this subscriber's plan, and why" after the
 * webhook that caused it is long gone.
 *
 * The table is a history, so it outlives everything it points at:
 *
 * - `actor_user_id` is a nullable foreign key with `nullOnDelete()`. Deleting
 *   the user clears the pointer and keeps the row, because the row is about
 *   money, not about the account.
 * - `billable_type` and `billable_id` carry NO foreign key and are plain
 *   nullable strings, the same shape `magic_starter_audits` uses. A refusal may
 *   have no billable at all, the billable is a user or a team depending on
 *   `magic-starter.billing.billable`, and a raw store id (an anonymous RevenueCat
 *   id) must never be written into a uuid column that would refuse it.
 *   `billable_type` holds `$billable->getMorphClass()` when there is one.
 *
 * Rows are written once: there is a `created_at` and no `updated_at`, and the
 * model refuses updates and deletes. Only the prune command removes rows, by
 * age, through the query builder.
 *
 * Guarded by `hasTable`: this migration creates a table it owns outright, so a
 * second run is a plain no-op.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('billing_events')) {
            return;
        }

        Schema::create('billing_events', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            $table->string('type', 64)->index();
            $table->string('source', 32);
            $table->string('provider', 32)->nullable();

            $table->string('billable_type')->nullable();
            $table->string('billable_id')->nullable();

            MigrationHelper::foreignKey($table, 'actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('reason', 64)->nullable();
            $table->string('external_id')->nullable()->index();
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->nullable();

            // The per-subscriber history read: one billable, newest first.
            $table->index([
                'billable_type',
                'billable_id',
                'created_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_events');
    }
};
