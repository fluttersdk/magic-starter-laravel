<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'locale')) {
                $table->string('locale')->default('en')->after('remember_token');
            }

            if (! Schema::hasColumn('users', 'timezone')) {
                $table->string('timezone')->default('UTC')->after('locale');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columnsToDrop = array_filter([
                Schema::hasColumn('users', 'locale') ? 'locale' : null,
                Schema::hasColumn('users', 'timezone') ? 'timezone' : null,
            ]);

            if (count($columnsToDrop) > 0) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
