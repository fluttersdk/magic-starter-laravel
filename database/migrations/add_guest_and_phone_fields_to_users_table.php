<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();

            if (! Schema::hasColumn('users', 'is_guest')) {
                $table->boolean('is_guest')
                    ->default(false)
                    ->after('password');
            }

            if (! Schema::hasColumn('users', 'device_id')) {
                $table->string('device_id', 255)
                    ->nullable()
                    ->unique()
                    ->after('is_guest');
            }

            if (! Schema::hasColumn('users', 'phone_country')) {
                $table->char('phone_country', 2)
                    ->nullable()
                    ->after('phone');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();

            if (Config::get('database.default') !== 'sqlite') {
                if (Schema::hasColumn('users', 'device_id')) {
                    $table->dropUnique(['device_id']);
                }
            }

            $columnsToDrop = array_filter([
                Schema::hasColumn('users', 'is_guest') ? 'is_guest' : null,
                Schema::hasColumn('users', 'device_id') ? 'device_id' : null,
                Schema::hasColumn('users', 'phone_country') ? 'phone_country' : null,
            ]);

            if (count($columnsToDrop) > 0) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
