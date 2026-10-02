<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_accounts')) {
            Schema::create('social_accounts', function (Blueprint $table) {
                MigrationHelper::primaryKey($table);
                MigrationHelper::foreignKey($table, 'user_id')->constrained()->cascadeOnDelete();
                $table->string('provider', 32);
                $table->string('provider_user_id', 255);
                $table->string('tenant_id', 255)->nullable();
                $table->string('email_at_link', 255)->nullable();
                $table->string('client_id', 255)->nullable();
                $table->text('refresh_token')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'provider_user_id']);
                $table->unique(['user_id', 'provider']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
