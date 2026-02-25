<?php

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
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('notifiable');
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
