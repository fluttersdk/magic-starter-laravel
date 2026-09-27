<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push devices table: one row per push SUBSCRIPTION a person's client has
 * reported, so a server can tell a person whose phone rings from one whose
 * phone cannot. OneSignal accepts a push for an unreachable subscription
 * without complaint, so the client's own report is the only evidence there is.
 *
 * `subscription_id` is the device key and is nullable, because "this device
 * holds no address" is a state worth recording (a fresh install, a device
 * mid-logout). On PostgreSQL two NULLs are distinct under the unique index,
 * which is harmless: a row without a subscription id can never claim
 * reachability.
 *
 * `external_id` is the alias the DEVICE reports carrying, kept beside `user_id`
 * because the two disagree on a shared phone still subscribed as its previous
 * user. `captured_at` is the device's clock, kept for diagnosis; `reported_at`
 * is the server's, and it is the one freshness reads.
 *
 * Guarded by `hasTable`, so an application that built this table itself keeps
 * its shape; this stub creates the same one.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('push_devices')) {
            return;
        }

        Schema::create('push_devices', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('external_id')->nullable();
            $table->string('subscription_id')->nullable();
            $table->string('reachability', 16);
            $table->timestamp('captured_at');
            $table->timestamp('reported_at');

            $table->timestamps();

            $table->unique([
                'user_id',
                'subscription_id',
            ]);
            // The reachability read: this user's devices, narrowed by how
            // recently each was heard from.
            $table->index([
                'user_id',
                'reported_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
