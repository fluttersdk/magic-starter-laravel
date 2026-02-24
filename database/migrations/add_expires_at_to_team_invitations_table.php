<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_invitations', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('team_invitations', function (Blueprint $table): void {
            $table->dropColumn('expires_at');
        });
    }
};
