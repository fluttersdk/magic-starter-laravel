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
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                // Always a UUID: Laravel's database channel writes the notification's own
                // UUID as the id in either `use_uuids` mode. Only the morph key follows it.
                $table->uuid('id')->primary();
                $table->string('type');
                MigrationHelper::morphColumns($table, 'notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index([
                    'notifiable_type',
                    'notifiable_id',
                    'read_at',
                ]);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
