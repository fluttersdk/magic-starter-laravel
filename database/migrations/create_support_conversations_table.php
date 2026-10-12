<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support conversations: one row per customer contact, whichever door it came
 * in through (`source`), answered by staff from the panel.
 *
 * `user_id` is the opener and the only account that may read the thread; it is
 * null for a visitor who wrote in without one, who follows the thread through a
 * mailed link instead. That link's secret is never stored: `thread_token_hash`
 * holds its sha256, `thread_token_version` counts every mint and revoke, and
 * `thread_token_expires_at` bounds it.
 *
 * Deleting the opener deletes their conversations, the same as every other row
 * the package keeps about a user. Deleting the assignee only clears the
 * pointer. `team_id` carries no foreign key at all, because the `teams` table
 * is published only with the teams feature; a deleted team leaves the value
 * behind and the relation reads null. The conversation is about the opener,
 * not about the team or the assignee.
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
        if (Schema::hasTable('support_conversations')) {
            return;
        }

        Schema::create('support_conversations', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            // The display id staff and customers quote; the key itself may be a UUID.
            $table->unsignedBigInteger('number')->unique();

            // No constraint: `teams` exists only with the teams feature, and
            // support ships without it.
            MigrationHelper::foreignKey($table, 'team_id')->nullable();
            MigrationHelper::foreignKey($table, 'user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();

            // Nullable because a guest account has neither.
            $table->string('email', 255)->nullable();
            $table->string('name', 255)->nullable();
            $table->string('subject', 255);

            $table->string('source', 32);
            $table->string('status', 32)->default('open');
            $table->string('priority', 32)->default('normal');

            MigrationHelper::foreignKey($table, 'assignee_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('handoff_reason', 255)->nullable();

            $table->timestamp('waiting_since')->nullable();
            $table->timestamp('first_reply_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('opener_seen_at')->nullable();
            $table->timestamp('staff_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            // Unique so a public link resolves by its hash in one indexed read.
            $table->char('thread_token_hash', 64)->nullable()->unique();
            $table->unsignedInteger('thread_token_version')->default(0);
            $table->timestamp('thread_token_expires_at')->nullable();

            $table->timestamps();

            // The opener's own inbox, newest activity first.
            $table->index([
                'user_id',
                'last_activity_at',
            ]);
            $table->index([
                'team_id',
            ]);
            // The staff queue by status.
            $table->index([
                'status',
                'last_activity_at',
            ]);
            $table->index([
                'email',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_conversations');
    }
};
