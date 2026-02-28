<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('personal_access_tokens', 'ip_address')) {
                $table->string('ip_address', 45)->nullable();
            }

            if (! Schema::hasColumn('personal_access_tokens', 'user_agent')) {
                $table->text('user_agent')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $columnsToDrop = array_filter([
                Schema::hasColumn('personal_access_tokens', 'ip_address') ? 'ip_address' : null,
                Schema::hasColumn('personal_access_tokens', 'user_agent') ? 'user_agent' : null,
            ]);

            if (count($columnsToDrop) > 0) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
