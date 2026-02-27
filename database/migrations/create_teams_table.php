<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('teams')) {
            Schema::create('teams', function (Blueprint $table) {
                MigrationHelper::primaryKey($table);
                MigrationHelper::foreignKey($table, 'user_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->boolean('personal_team')->default(true);
                $table->string('profile_photo_path', 2048)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
