<?php

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the `subscriptions` table Cashier reads, keyed the way the rest of
 * this package is keyed and pointed at whichever subject
 * `magic-starter.billing.billable` names.
 *
 * Cashier's own `2019_05_03_000002_create_subscriptions_table.php` is
 * `$table->id()` plus `foreignId('user_id')`, and both halves are wrong here.
 * The key type has to follow `magic-starter.use_uuids` like every other table
 * the package ships, or a UUID application gets a bigint parent whose child
 * table (`subscription_items`) then cannot reference it; and the foreign key
 * has to follow the billable subject, or a team-billing application gets a
 * column no relation reads.
 *
 * The foreign key NAME is derived rather than chosen, from
 * `(new (MagicStarter::billableModel()))->getForeignKey()`, because that is the
 * exact expression Cashier resolves the relation with
 * (`ManagesSubscriptions::subscriptions()` is
 * `hasMany(Cashier::$subscriptionModel, $this->getForeignKey())`). Deriving it
 * the same way is what makes the column and the relation agree by
 * construction: hardcoding `team_id` would be right for one subject and silent
 * for the other, and hardcoding either one would drift the moment a consumer
 * points the subject at a model of their own.
 *
 * The key TYPE is likewise `MigrationHelper`'s and not this file's: it reads
 * `use_uuids` once, so the parent key here and the child key in
 * `create_subscription_items_table.php` cannot disagree.
 *
 * There is deliberately NO foreign-key CONSTRAINT, which is where this file
 * departs from the package's own `create_team_user_table.php` and follows
 * Cashier instead. A subscription row is a payment RECORD: it has to outlive
 * the billable row it points at for as long as a reconciler or a support
 * question needs it, and a `cascadeOnDelete()` would delete the evidence of a
 * charge along with the customer. `MigrationHelper::foreignKey()` is used for
 * the column TYPE, which is the part that has to follow the parent; the
 * referential action is a separate decision and Cashier's is the one to keep.
 *
 * Created only when absent, so an adopter who already ran Cashier's published
 * migration keeps their table and this one is a no-op.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('subscriptions')) {
            return;
        }

        $billableKey = $this->billableForeignKey();

        Schema::create('subscriptions', function (Blueprint $table) use ($billableKey): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, $billableKey);
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index([$billableKey, 'stripe_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }

    /**
     * The column name Cashier will resolve the billable relation through.
     */
    private function billableForeignKey(): string
    {
        $model = MagicStarter::billableModel();

        return (new $model)->getForeignKey();
    }
};
