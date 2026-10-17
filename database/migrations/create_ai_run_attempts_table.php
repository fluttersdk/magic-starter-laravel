<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI run attempts: one row per provider call inside a run, so a retry or a
 * fallback to another provider keeps its own status, usage and cost instead of
 * being folded into the run's totals.
 *
 * An attempt belongs to its run and goes with it: deleting the run deletes its
 * attempts. `attempt` counts from 1 and is unique within the run.
 *
 * The migration must run after `create_ai_runs_table`, which the install
 * command guarantees by stamping the runs table first. Guarded by `hasTable`:
 * a second run is a plain no-op.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('ai_run_attempts')) {
            return;
        }

        Schema::create('ai_run_attempts', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            MigrationHelper::foreignKey($table, 'ai_run_id')
                ->constrained('ai_runs')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt');

            $table->string('provider', 64);
            $table->string('model', 128)->nullable();
            $table->string('status', 16);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('finish_reason', 64)->nullable();
            $table->string('error_class', 255)->nullable();

            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cache_read_tokens')->nullable();
            $table->unsignedInteger('cache_write_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->decimal('cost_usd', 14, 8)->nullable();
            $table->string('cost_source', 16)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // Bodies: blanked by the prune once they age out.
            $table->json('tool_calls')->nullable();
            $table->longText('error_message')->nullable();

            $table->timestamp('bodies_pruned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique([
                'ai_run_id',
                'attempt',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_run_attempts');
    }
};
