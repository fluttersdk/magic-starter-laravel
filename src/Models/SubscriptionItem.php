<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;

/**
 * The package's Cashier subscription item, keyed the way the package keys
 * everything.
 *
 * Same reason as {@see Subscription}: Cashier's stock migration is
 * `$table->id()`, the package's `create_subscription_items_table.php` follows
 * `magic-starter.use_uuids`, and the model has to follow the same switch or a
 * UUID application inserts an item with no primary key.
 *
 * {@see ConditionallyUsesUuids} supplies `$incrementing` and `$keyType` from
 * that one config read rather than this file picking an answer at
 * class-definition time.
 *
 * The parent key this model belongs to is resolved by
 * {@see CashierSubscriptionItem::subscription()} as
 * `(new Cashier::$subscriptionModel)->getForeignKey()`, so it answers
 * `subscription_id` for as long as the package's subscription model keeps its
 * basename. That is the column `create_subscription_items_table.php` creates,
 * and neither side names it literally twice.
 *
 * Nothing else is overridden, including the `meter_id` and `meter_event_name`
 * casts Cashier already declares: the package's contribution is the two
 * COLUMNS, folded into the create migration instead of arriving as two later
 * alters.
 */
class SubscriptionItem extends CashierSubscriptionItem
{
    use ConditionallyUsesUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'subscription_items';
}
