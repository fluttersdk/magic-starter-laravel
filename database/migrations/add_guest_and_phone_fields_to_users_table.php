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
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();

            $table->boolean('is_guest')
                ->default(false)
                ->after('password');

            $table->string('device_id', 255)
                ->nullable()
                ->unique()
                ->after('is_guest');

            $table->char('phone_country', 2)
                ->nullable()
                ->after('phone');
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

            if (Illuminate\Support\Facades\Config::get('database.default') !== 'sqlite') {
                $table->dropUnique(['device_id']);
            }

            $table->dropColumn([
                'is_guest',
                'device_id',
                'phone_country',
            ]);
        });
    }
};
