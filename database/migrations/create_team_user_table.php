<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('team_user')) {
            Schema::create('team_user', function (Blueprint $table) {
                MigrationHelper::primaryKey($table);
                MigrationHelper::foreignKey($table, 'team_id')->constrained()->cascadeOnDelete();
                MigrationHelper::foreignKey($table, 'user_id')->constrained()->cascadeOnDelete();
                $table->string('role')->nullable()->default('member');
                $table->timestamps();

                $table->unique(['team_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('team_user');
    }
};
