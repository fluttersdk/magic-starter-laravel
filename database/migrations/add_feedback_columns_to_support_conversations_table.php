<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns the customer satisfaction rating and the client diagnostics write
 * on `support_conversations`.
 *
 * `csat_rating` is the opener's thumbs (`up` or `down`) for the current
 * resolution, with the optional `csat_comment` and the `csat_rated_at` it was
 * given at. `csat_assignee_id` freezes who held the conversation when it was
 * rated, so a later reassignment does not move the rating to somebody else;
 * deleting that staff member only clears the pointer. `csat_alerted_at` records
 * that staff were told about a thumbs-down, so one resolution alerts once.
 * `client_context` keeps the app, platform and route the customer wrote from.
 *
 * Additive only: every column is nullable and no existing row is read or
 * rewritten. Each column is guarded by `hasColumn`, so a second run is a no-op.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('support_conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('support_conversations', 'csat_rating')) {
                $table->string('csat_rating', 16)->nullable();
            }

            if (! Schema::hasColumn('support_conversations', 'csat_comment')) {
                $table->text('csat_comment')->nullable();
            }

            if (! Schema::hasColumn('support_conversations', 'csat_rated_at')) {
                $table->timestamp('csat_rated_at')->nullable();
            }

            if (! Schema::hasColumn('support_conversations', 'csat_assignee_id')) {
                MigrationHelper::foreignKey($table, 'csat_assignee_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('support_conversations', 'csat_alerted_at')) {
                $table->timestamp('csat_alerted_at')->nullable();
            }

            if (! Schema::hasColumn('support_conversations', 'client_context')) {
                $table->json('client_context')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('support_conversations', 'csat_assignee_id')) {
            Schema::table('support_conversations', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('csat_assignee_id');
            });
        }

        foreach ([
            'csat_rating',
            'csat_comment',
            'csat_rated_at',
            'csat_alerted_at',
            'client_context',
        ] as $column) {
            if (Schema::hasColumn('support_conversations', $column)) {
                Schema::table('support_conversations', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
