<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * The package's Cashier subscription, keyed the way the package keys everything.
 *
 * Cashier's own {@see CashierSubscription} assumes an auto-incrementing integer
 * key, because its migration is `$table->id()`. The package ships its own
 * `create_subscriptions_table.php` whose key follows
 * `magic-starter.use_uuids`, so the model has to follow the same switch or a
 * UUID application inserts a row with no primary key at all: Eloquent would
 * expect the database to mint one, and a `char(36)` column does not.
 *
 * `$incrementing` and `$keyType` therefore come from
 * {@see ConditionallyUsesUuids} rather than being written out here. Writing
 * them out would mean picking one of the two answers at class-definition time,
 * and the correct answer is a config read: the trait sets both in
 * `initializeConditionallyUsesUuids()` and mints an ordered UUID on `creating`
 * when, and only when, UUIDs are on. It is the same trait every other
 * package-owned model uses, so there is one place the key strategy is decided.
 *
 * `$table` is declared explicitly even though the basename already resolves to
 * it. It is what pins the name a consumer's `Cashier::useSubscriptionModel()`
 * override has to keep pointing at, and it is the assertion the migration test
 * compares the created table against.
 *
 * The CLASS BASENAME is load-bearing and must stay `Subscription`.
 * {@see CashierSubscription::items()} is a bare
 * `hasMany(Cashier::$subscriptionItemModel)`, so Eloquent derives the child's
 * foreign key from this class's basename; renaming it would silently look for
 * a column `subscription_items` does not have.
 *
 * Nothing else is overridden. Every accessor, every Stripe call and every cast
 * stays Cashier's, which is the point of subclassing rather than reimplementing.
 */
class Subscription extends CashierSubscription
{
    use ConditionallyUsesUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'subscriptions';
}
