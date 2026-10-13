<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns the AI support desk reads and writes, added to the two support tables.
 *
 * On `support_conversations`: `topic` is what the opener picked from
 * `support.topics`; `last_customer_message_id` is the newest customer message
 * the desk owes an answer to and `answered_message_id` the one it last
 * answered, so "pending" is the two differing and a persisted reply is claimed
 * by id rather than by time; `ai_failures` counts failed turns toward
 * `support.ai.failures_before_handoff`. The existing `handoff_reason` column
 * already fits and is reused.
 *
 * The two message pointers carry no foreign key. A key from conversations to
 * messages closes a cycle with the messages' own key to conversations, which
 * makes every delete order-sensitive; a message never outlives its
 * conversation anyway, it goes with it by cascade.
 *
 * On `support_messages`: `meta` keeps what the desk recorded about an AI
 * message (confidence, handoff reason, tokens). Staff-side only.
 *
 * Additive only: every column is nullable or defaulted and no existing row is
 * read or rewritten. Each column is guarded by `hasColumn`, so a second run is
 * a no-op.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('support_conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('support_conversations', 'topic')) {
                $table->string('topic', 32)->nullable();
            }

            if (! Schema::hasColumn('support_conversations', 'last_customer_message_id')) {
                MigrationHelper::foreignKey($table, 'last_customer_message_id')->nullable();
            }

            if (! Schema::hasColumn('support_conversations', 'answered_message_id')) {
                MigrationHelper::foreignKey($table, 'answered_message_id')->nullable();
            }

            if (! Schema::hasColumn('support_conversations', 'ai_failures')) {
                $table->unsignedSmallInteger('ai_failures')->default(0);
            }
        });

        Schema::table('support_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('support_messages', 'meta')) {
                $table->json('meta')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('support_messages', 'meta')) {
                $table->dropColumn('meta');
            }
        });

        foreach ([
            'topic',
            'last_customer_message_id',
            'answered_message_id',
            'ai_failures',
        ] as $column) {
            if (Schema::hasColumn('support_conversations', $column)) {
                Schema::table('support_conversations', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
