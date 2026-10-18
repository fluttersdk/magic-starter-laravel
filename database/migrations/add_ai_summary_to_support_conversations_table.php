<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stored summary the AI support desk keeps of the older messages in a thread.
 *
 * `ai_summary` is the model's condensed account of the messages the desk no
 * longer sends verbatim, and `ai_summary_through_id` the newest message that
 * account covers, so the next turn summarises only what came after it.
 *
 * Like the other message pointers on this table, `ai_summary_through_id`
 * carries no foreign key: a key from conversations to messages closes a cycle
 * with the messages' own key to conversations, which makes every delete
 * order-sensitive.
 *
 * Additive only: both columns are nullable and no existing row is read or
 * rewritten. A conversation that was never summarised keeps null in both. Each
 * column is guarded by `hasColumn`, so a second run is a no-op, and a host
 * without the support tables skips the migration.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('support_conversations')) {
            return;
        }

        Schema::table('support_conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('support_conversations', 'ai_summary')) {
                $table->text('ai_summary')->nullable();
            }

            if (! Schema::hasColumn('support_conversations', 'ai_summary_through_id')) {
                MigrationHelper::foreignKey($table, 'ai_summary_through_id')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ([
            'ai_summary',
            'ai_summary_through_id',
        ] as $column) {
            if (Schema::hasColumn('support_conversations', $column)) {
                Schema::table('support_conversations', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
