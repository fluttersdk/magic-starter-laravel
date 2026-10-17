<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App releases: one row per shipped version, with the notes staff wrote for it.
 *
 * A release belongs to the app, not to a team, so there is no `team_id`. A row
 * is a draft until staff publish it; only a published row with a `published_at`
 * reaches a client. `ios_build` and `android_build` are the store build numbers
 * a client compares its own against, and stay null for a platform the release
 * did not ship on. `items` is a JSON list of `{type, text}` entries.
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
        if (Schema::hasTable('app_releases')) {
            return;
        }

        Schema::create('app_releases', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            $table->string('version', 32)->unique();
            $table->string('status', 16)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('ios_build')->nullable();
            $table->unsignedInteger('android_build')->nullable();
            $table->json('items');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};
