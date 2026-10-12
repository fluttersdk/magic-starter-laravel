<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support messages: the thread of one conversation, in order.
 *
 * `kind` separates a reply (`message`) from a staff-only `note` and a recorded
 * `activity` line. `sender_type` says who wrote it; `sender_id` points at the
 * user who did, and is null for a visitor writing through the public link, for
 * the system, and once the sender's account is deleted, because the thread
 * outlives a departed staff member.
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
        if (Schema::hasTable('support_messages')) {
            return;
        }

        Schema::create('support_messages', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            MigrationHelper::foreignKey($table, 'conversation_id')
                ->constrained('support_conversations')
                ->cascadeOnDelete();

            $table->string('sender_type', 32);
            MigrationHelper::foreignKey($table, 'sender_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('kind', 32)->default('message');
            $table->text('body');

            $table->timestamps();

            // The thread read: one conversation's messages in order.
            $table->index([
                'conversation_id',
                'created_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};
