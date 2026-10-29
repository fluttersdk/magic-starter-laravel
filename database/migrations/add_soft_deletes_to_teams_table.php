<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('teams', 'deleted_at')) {
            Schema::table('teams', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        // The end of a trashed team's restore window. Indexed because the purge
        // selects on it, and null on a live team and on one trashed without a window.
        if (! Schema::hasColumn('teams', 'restore_until')) {
            Schema::table('teams', function (Blueprint $table): void {
                $table->timestamp('restore_until')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('teams', 'restore_until')) {
            // Dropped first: SQLite refuses to drop a column an index still covers.
            Schema::table('teams', function (Blueprint $table): void {
                $table->dropIndex(['restore_until']);
                $table->dropColumn('restore_until');
            });
        }

        if (Schema::hasColumn('teams', 'deleted_at')) {
            Schema::table('teams', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
