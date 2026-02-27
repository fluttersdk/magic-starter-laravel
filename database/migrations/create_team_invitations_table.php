<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('team_invitations')) {
            Schema::create('team_invitations', function (Blueprint $table) {
                MigrationHelper::primaryKey($table);
                MigrationHelper::foreignKey($table, 'team_id')->constrained()->cascadeOnDelete();
                $table->string('email');
                $table->string('role')->nullable()->default('member');
                $table->string('token')->unique();
                $table->timestamps();

                $table->unique(['team_id', 'email']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invitations');
    }
};
