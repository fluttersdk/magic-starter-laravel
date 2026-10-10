<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing trials table: one row per trialing Stripe subscription that package
 * checkout opened. It is the anti-abuse record behind "one trial per person and
 * per card": eligibility asks whether this user or this billable already has a
 * row, and a queued job stores the card fingerprint and refuses a later
 * duplicate (the earliest subscription wins).
 *
 * The table is a history, so it outlives everything it points at:
 *
 * - `user_id` is a nullable foreign key with `nullOnDelete()`. Deleting the
 *   user clears the pointer and keeps the row, because the fingerprint is
 *   exactly what has to survive a delete-and-sign-up-again.
 * - `billable_type` and `billable_id` carry NO foreign key. The billable is a
 *   user or a team depending on `magic-starter.billing.billable`, and deleting
 *   a team must not delete the record that it already used a trial.
 *   `billable_type` always holds `$billable->getMorphClass()`.
 *
 * `subscription_created_at` is Stripe's own `created`, not the time this row
 * was written: it is the order "earliest wins" is decided on, and a webhook
 * can land late. `checked_at` is stamped once the card check finished, whether
 * or not it found a fingerprint, so an unchecked row is distinguishable from a
 * clean one. `refused_at` and `refusal_reason` (`card_reused` or `duplicate`)
 * mark a trial that was refused.
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
        if (Schema::hasTable('billing_trials')) {
            return;
        }

        Schema::create('billing_trials', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('billable_type', 255);
            // The billable's key follows `use_uuids` like every other key, but
            // carries no foreign key, so it is declared here rather than through
            // MigrationHelper::foreignKey().
            if (MigrationHelper::usesUuids()) {
                $table->uuid('billable_id');
            } else {
                $table->unsignedBigInteger('billable_id');
            }

            $table->string('stripe_subscription_id', 255)->unique();
            $table->timestamp('subscription_created_at');
            $table->string('card_fingerprint', 255)->nullable()->index();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->string('refusal_reason', 255)->nullable();

            $table->timestamps();

            // The eligibility read: has this billable already had a trial.
            $table->index([
                'billable_type',
                'billable_id',
            ]);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_trials');
    }
};
