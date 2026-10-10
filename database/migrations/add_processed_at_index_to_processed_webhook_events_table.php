<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes `processed_webhook_events.processed_at`, the column the retention
 * prune filters on. Without it every prune scans the whole dedup table.
 *
 * A separate migration rather than an edit of the create one, because that
 * migration is already published: an adopter who ran it never runs it again,
 * so a new index has to arrive as a new file. Guarded twice for the same
 * reason the provenance migration guards per column: a table the adopter has
 * not created yet is a no-op, and an index that already exists is too, so a
 * second run never throws.
 */
return new class extends Migration
{
    private const INDEX = 'processed_webhook_events_processed_at_index';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('processed_webhook_events')) {
            return;
        }

        if (Schema::hasIndex('processed_webhook_events', self::INDEX)) {
            return;
        }

        Schema::table('processed_webhook_events', function (Blueprint $table): void {
            $table->index('processed_at', self::INDEX);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('processed_webhook_events')) {
            return;
        }

        if (! Schema::hasIndex('processed_webhook_events', self::INDEX)) {
            return;
        }

        Schema::table('processed_webhook_events', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
