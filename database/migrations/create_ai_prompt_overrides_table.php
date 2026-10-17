<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI prompt overrides: one row per registered prompt whose body staff replaced
 * from the admin panel. The row holds only the body; the boundary block and the
 * required phrases stay in code and are never stored.
 *
 * `key` is the host's own prompt name (`incident.analysis`) and is unique, so a
 * prompt has at most one override. `default_hash` is the hash of the code
 * default the staff edit started from, which lets the panel say when the
 * default has since changed underneath an override.
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
        if (Schema::hasTable('ai_prompt_overrides')) {
            return;
        }

        Schema::create('ai_prompt_overrides', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            $table->string('key', 100)->unique();
            $table->longText('body');
            $table->char('default_hash', 64);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_overrides');
    }
};
