<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a user exist without a password, which an account created from a social
 * provider does until its owner sets one.
 *
 * The core users table declares `password` NOT NULL, and only the guest-auth and
 * phone-otp migration relaxed it, so an install with social login alone could not
 * create a single provider user. A column that is already nullable is left alone,
 * which keeps this safe beside that migration and on a re-run.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $password = collect(Schema::getColumns('users'))->firstWhere('name', 'password');

        if ($password === null || $password['nullable']) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately a no-op: password-less accounts may exist by then, and
     * restoring NOT NULL would fail on them or force a value onto them.
     */
    public function down(): void {}
};
