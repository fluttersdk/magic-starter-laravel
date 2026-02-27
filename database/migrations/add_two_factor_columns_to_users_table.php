<?php

use FlutterSdk\MagicStarter\MagicStarter;
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
        $userModel = MagicStarter::userModel();
        $table = (new $userModel)->getTable();

        Schema::table($table, function (Blueprint $table) use ($table) {
            if (! Schema::hasColumn($table, 'two_factor_secret')) {
                $table->text('two_factor_secret')
                    ->after('password')
                    ->nullable();
            }

            if (! Schema::hasColumn($table, 'two_factor_recovery_codes')) {
                $table->text('two_factor_recovery_codes')
                    ->after('two_factor_secret')
                    ->nullable();
            }

            if (! Schema::hasColumn($table, 'two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')
                    ->after('two_factor_recovery_codes')
                    ->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $userModel = MagicStarter::userModel();
        $table = (new $userModel)->getTable();

        Schema::table($table, function (Blueprint $table) use ($table) {
            $columnsToDrop = [];

            if (Schema::hasColumn($table, 'two_factor_secret')) {
                $columnsToDrop[] = 'two_factor_secret';
            }

            if (Schema::hasColumn($table, 'two_factor_recovery_codes')) {
                $columnsToDrop[] = 'two_factor_recovery_codes';
            }

            if (Schema::hasColumn($table, 'two_factor_confirmed_at')) {
                $columnsToDrop[] = 'two_factor_confirmed_at';
            }

            if (count($columnsToDrop) > 0) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
