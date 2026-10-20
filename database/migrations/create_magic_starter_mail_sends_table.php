<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mail sends: one row per mail OneSignal accepted, so a delivery can be read back
 * by its OneSignal id long after the request is gone.
 *
 * The row holds no address, subject or body. `recipient_hash` is the sha256 of the
 * first lowercased To address, enough to tell whether two sends went to the same
 * person and not enough to read who. `mail_class` is null on a send that did not
 * come through the router. The row is written once and never updated, so the
 * table carries `created_at` only.
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
        if (Schema::hasTable('magic_starter_mail_sends')) {
            return;
        }

        Schema::create('magic_starter_mail_sends', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            $table->string('onesignal_id')->unique();
            $table->string('mail_class')->nullable();
            $table->string('recipient_hash', 64);
            $table->unsignedSmallInteger('recipients');
            $table->unsignedSmallInteger('invalid_recipients')->default(0);

            // The prune's read for rows past the retention.
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('magic_starter_mail_sends');
    }
};
