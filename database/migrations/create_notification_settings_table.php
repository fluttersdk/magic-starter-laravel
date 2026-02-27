<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('notification_settings')) {
            Schema::create('notification_settings', function (Blueprint $table) {
                MigrationHelper::primaryKey($table);
                MigrationHelper::morphColumns($table, 'notifiable');
                $table->string('type');
                $table->string('channel');
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();

                $table->unique([
                    'notifiable_id',
                    'notifiable_type',
                    'type',
                    'channel',
                ], 'notification_settings_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
