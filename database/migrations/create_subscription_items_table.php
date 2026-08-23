<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the `subscription_items` table, the child of `subscriptions`.
 *
 * Cashier's stock file cannot be copied here, and this is the one migration in
 * the set where copying it produces a table that is wrong in TWO independent
 * ways at once:
 *
 * 1. It is `$table->id()` plus `foreignId('subscription_id')`, so on a UUID
 *    application the child key is a bigint pointed at a `char(36)` parent. Both
 *    types come from `MigrationHelper` here, which reads `use_uuids` once, so
 *    the child follows the parent BY CONSTRUCTION rather than by anyone
 *    remembering to change two files together.
 * 2. It has neither `meter_id` nor `meter_event_name`. Cashier adds those in
 *    two LATER migrations (`2025_06_06_000004` and `2025_06_06_000005`), and
 *    both are folded in below rather than shipped as alters of their own: a
 *    package has no history to replay, and one file removes two chances to
 *    forget a column.
 *
 * The second one is not cosmetic and it is not usage-billing-only.
 * `SubscriptionBuilder::createSubscription()` writes `meter_id` and
 * `meter_event_name` on EVERY item it creates, unconditionally, passing null
 * when the price has no meter. So a `subscription_items` table missing either
 * column does not degrade, it throws on the first real checkout, while every
 * assertion about the table's shape that does not name those two columns still
 * passes.
 *
 * The column name `subscription_id` is Cashier's own derivation and not a
 * choice: `Subscription::items()` is a bare `hasMany(Cashier::$subscriptionItemModel)`,
 * whose foreign key Eloquent derives from the PARENT model's basename, and the
 * package's `Subscription` subclass keeps that basename precisely so the
 * derivation keeps answering `subscription_id`.
 *
 * No foreign-key CONSTRAINT, for the reason
 * `create_subscriptions_table.php` gives at length: an item is a line on a
 * payment record and Cashier ships no constraint on either table.
 * `MigrationHelper::foreignKey()` is here for the column TYPE.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('subscription_items')) {
            return;
        }

        Schema::create('subscription_items', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'subscription_id');
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'stripe_price']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
    }
};
