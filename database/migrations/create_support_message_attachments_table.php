<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support message attachments: one row per stored file on a support message.
 *
 * `origin` says where the file came from (an upload, the app's auto screenshot,
 * or staff). `disk` and `path` locate the bytes on a private disk, under a
 * generated name; `original_name` is what the sender called it and is only ever
 * shown, never used as a path. `width` and `height` are null for a PDF.
 *
 * The row carries no `user_id` on purpose: whose file it is follows from the
 * conversation, and a row about a person's file is erased with the conversation
 * by cascade. Both keys cascade, so a message or a conversation never leaves a
 * row behind; the files themselves are deleted by the code that deletes them.
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
        if (Schema::hasTable('support_message_attachments')) {
            return;
        }

        Schema::create('support_message_attachments', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            MigrationHelper::foreignKey($table, 'conversation_id')
                ->constrained('support_conversations')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'message_id')
                ->constrained('support_messages')
                ->cascadeOnDelete();

            $table->string('origin', 32);
            $table->string('disk', 64);
            $table->string('path', 2048);
            $table->string('original_name', 255);
            $table->string('mime', 127);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->timestamps();

            // The thread read: one message's files, and one conversation's files.
            $table->index([
                'message_id',
            ]);
            $table->index([
                'conversation_id',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_message_attachments');
    }
};
