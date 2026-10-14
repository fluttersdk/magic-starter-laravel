<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app feedback: one row each time a signed-in customer answers, or dismisses,
 * the occasional 0-10 card.
 *
 * `score` is null when the customer dismissed the card, so a dismissal still
 * counts toward the interval before they are asked again. `conversation_id`
 * points at the support conversation a low score opened or continued; deleting
 * that conversation only clears the pointer. Deleting the customer deletes
 * their feedback, like every other row the package keeps about a user.
 * `team_id` carries no foreign key, because the `teams` table is published only
 * with the teams feature.
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
        if (Schema::hasTable('support_feedback')) {
            return;
        }

        Schema::create('support_feedback', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            MigrationHelper::foreignKey($table, 'user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // No constraint: `teams` exists only with the teams feature.
            MigrationHelper::foreignKey($table, 'team_id')->nullable();

            $table->unsignedTinyInteger('score')->nullable();
            $table->text('comment')->nullable();

            MigrationHelper::foreignKey($table, 'conversation_id')
                ->nullable()
                ->constrained('support_conversations')
                ->nullOnDelete();

            $table->timestamps();

            // The eligibility read: one customer's newest answer.
            $table->index([
                'user_id',
                'created_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_feedback');
    }
};
