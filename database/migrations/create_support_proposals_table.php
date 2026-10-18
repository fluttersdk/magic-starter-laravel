<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support proposals: one row per change to a customer's subscription that the
 * account owner asked the AI desk for inside a conversation, which a staff
 * member then approves or rejects.
 *
 * `billable_type` and `billable_id` name the account the change would touch and
 * carry NO foreign key: the billable is a user or a team depending on
 * `magic-starter.billing.billable`, and the row is a record of what was asked,
 * so it does not follow the billable out of the database. `billable_type`
 * holds `$billable->getMorphClass()`.
 *
 * `action` and `params` say what would run (`params` is JSON whose shape the
 * action defines); `status` is where the row stands, and `expires_at` is the
 * deadline after which an open row can no longer be approved. `trigger_message_id`
 * is the customer message the proposal answers: a draft is promoted to pending
 * once that turn has been delivered. `requested_by` and `decided_by` are users
 * and clear to null when the account is deleted, so the decision stays on
 * record. Deleting the conversation deletes its proposals.
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
        if (Schema::hasTable('support_proposals')) {
            return;
        }

        Schema::create('support_proposals', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            MigrationHelper::foreignKey($table, 'conversation_id')
                ->constrained('support_conversations')
                ->cascadeOnDelete();

            // `billable_type` and `billable_id`, the key following `use_uuids`,
            // with their composite index and no foreign key: see above.
            MigrationHelper::morphColumns($table, 'billable');

            MigrationHelper::foreignKey($table, 'requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('action', 32);
            $table->json('params');
            $table->text('rationale')->nullable();

            MigrationHelper::foreignKey($table, 'trigger_message_id')
                ->nullable()
                ->constrained('support_messages')
                ->nullOnDelete();

            $table->string('status', 16)->index();
            $table->timestamp('expires_at');

            MigrationHelper::foreignKey($table, 'decided_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 255)->nullable();

            $table->timestamps();

            // The thread read: one conversation's proposals in a given state.
            $table->index([
                'conversation_id',
                'status',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_proposals');
    }
};
