<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing grants table: one row per manual plan grant an operator made in the
 * admin panel, a plan given to a billable with no payment behind it. A grant is
 * open until it ends, and `ended_at` plus `end_reason` (`expired`, `revoked` or
 * `superseded`) record how, so the row stays as the history of who gave what and
 * why. At most one grant per billable is open at a time; the entitlement it
 * writes carries the row's id in `plan_product_id`, which is how an expiry or a
 * revoke tells whether the billable is still on it.
 *
 * The table is a history, so it outlives everything it points at:
 *
 * - `granted_by` is a nullable foreign key with `nullOnDelete()`. Deleting the
 *   operator clears the pointer and keeps the row, because the grant is about
 *   the billable's plan, not about the operator's account.
 * - `billable_type` and `billable_id` carry NO foreign key and are plain
 *   strings, the same shape `billing_events` uses: the billable is a user or a
 *   team depending on `magic-starter.billing.billable`, and deleting it must
 *   not erase the record that it was once given a plan. `billable_type` holds
 *   `$billable->getMorphClass()`.
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
        if (Schema::hasTable('billing_grants')) {
            return;
        }

        Schema::create('billing_grants', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            $table->string('billable_type', 255);
            $table->string('billable_id', 255);

            $table->string('plan', 255);
            $table->string('reason', 500);
            $table->timestamp('expires_at')->nullable()->index();

            MigrationHelper::foreignKey($table, 'granted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 32)->nullable();

            $table->timestamps();

            // The per-billable read: is a grant open for this subscriber.
            $table->index([
                'billable_type',
                'billable_id',
                'ended_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_grants');
    }
};
