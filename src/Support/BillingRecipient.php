<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Policies\BillingPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Who a billing lifecycle mail is addressed to: the person who manages the billable's billing.
 *
 * It answers the same question as {@see BillingPolicy::manage()}, the single source of "who manages billing": under
 * a `team` billable that is the team's owner, under a `user` billable the user themselves. A member who cannot
 * change the plan is never mailed about it.
 *
 * The owner is read through the `owner()` relation, never the `owner` property: a consumer with strict models
 * would throw on the property inside the webhook transaction that calls this. A team with no owner row, or a
 * billable with no `owner()` relation, yields null and the caller skips the mail.
 */
final class BillingRecipient
{
    public static function for(Model $billable): ?Model
    {
        // The literal default mirrors BillingPolicy::manage(): a config published before the key existed has none.
        if (config('magic-starter.billing.billable', 'user') !== 'team') {
            return $billable;
        }

        if (! method_exists($billable, 'owner')) {
            return null;
        }

        $owner = $billable->owner()->first();

        return $owner instanceof Model ? $owner : null;
    }
}
