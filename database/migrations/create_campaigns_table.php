<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns: a staff-managed, time-boxed Stripe promotion code offered on some
 * catalogue products.
 *
 * Global to the app, not to a team, so there is no `team_id`. A campaign runs
 * while `enabled` and `starts_at <= now < ends_at`. `products` lists catalogue
 * product keys; `headline` and `body` are locale => text maps. The `discount_*`
 * columns and `currency_options` are a snapshot of the code's coupon taken when
 * the code was last checked on save, null until it has been: `discount_value`
 * is a percentage for `percent` and a minor-unit amount in `discount_currency`
 * for `amount`, whose other currencies sit in `currency_options`.
 *
 * Guarded by `hasTable`: this migration creates a table it owns outright, so a
 * second run is a plain no-op.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('campaigns')) {
            return;
        }

        Schema::create('campaigns', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            $table->string('name', 255);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('enabled')->default(false);
            $table->json('products');
            $table->json('headline');
            $table->json('body');
            $table->string('stripe_promotion_code_id', 255);
            $table->string('discount_type', 16)->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->char('discount_currency', 3)->nullable();
            $table->json('currency_options')->nullable();

            $table->timestamps();

            $table->index([
                'enabled',
                'starts_at',
                'ends_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
