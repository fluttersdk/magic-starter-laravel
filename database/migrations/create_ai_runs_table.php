<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI runs: one row per model call a host application records, with the usage
 * and cost it reported and, until the prune blanks them, the bodies it sent
 * and received. The row answers "what did this feature spend, and why did it
 * fail" long after the request is gone.
 *
 * `feature` is the host's own name for the calling feature (`incident.analysis`),
 * `source` says whether the prompt came from code or from an override, and a
 * `cache_hit` run's `source_run_id` points at the run whose answer it reused.
 *
 * The row outlives everything it points at:
 *
 * - `team_id` carries NO foreign key and is a plain nullable string, because
 *   `teams` is published only with the teams feature and the key may be an
 *   integer or a UUID. A deleted team leaves the value behind and the relation
 *   reads null.
 * - `subject_type` and `subject_id` are plain nullable strings, the same shape
 *   `billing_events` uses for its billable: a run may have no subject, and the
 *   subject's key kind is the host's business, not `use_uuids`.
 * - `source_run_id` is nulled when the run it names is deleted.
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
        if (Schema::hasTable('ai_runs')) {
            return;
        }

        Schema::create('ai_runs', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            // No constraint: `teams` exists only with the teams feature.
            $table->string('team_id', 255)->nullable();
            $table->string('subject_type', 255)->nullable();
            $table->string('subject_id', 255)->nullable();

            $table->string('feature', 100);
            $table->string('source', 16)->default('code');
            $table->char('prompt_hash', 64)->nullable();
            $table->string('status', 16);

            MigrationHelper::foreignKey($table, 'source_run_id')->nullable();

            $table->string('provider', 64)->nullable();
            $table->string('model', 128)->nullable();
            $table->unsignedSmallInteger('attempts_count')->default(0);

            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cache_read_tokens')->nullable();
            $table->unsignedInteger('cache_write_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->decimal('cost_usd', 14, 8)->nullable();
            $table->string('cost_source', 16)->nullable();

            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_class', 255)->nullable();

            // Bodies: blanked by the prune once they age out.
            $table->longText('instructions')->nullable();
            $table->longText('input')->nullable();
            $table->json('context')->nullable();
            $table->json('tools')->nullable();
            $table->longText('output')->nullable();
            $table->json('structured')->nullable();
            $table->longText('error_message')->nullable();

            $table->timestamp('bodies_pruned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index([
                'subject_type',
                'subject_id',
            ]);
            $table->index([
                'created_at',
            ]);
            // The list's default sort and the widget's windows.
            $table->index([
                'started_at',
            ]);
            // The prune's read for bodies not blanked yet.
            $table->index([
                'bodies_pruned_at',
                'created_at',
            ]);
            // Also serves every lookup by team alone, so team_id has no index of its own.
            $table->index([
                'team_id',
                'created_at',
            ]);
            $table->index([
                'feature',
                'created_at',
            ]);
            // The prune's read for runs that never closed.
            $table->index([
                'status',
                'started_at',
            ]);
            $table->index([
                'prompt_hash',
            ]);
        });

        // A separate statement: in the CREATE above, PostgreSQL would meet this
        // self reference before the uuid primary key constraint exists.
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->foreign('source_run_id')
                ->references('id')
                ->on('ai_runs')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
