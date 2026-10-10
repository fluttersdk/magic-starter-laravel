<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * What happened, as `billing_events.type` stores it.
 *
 * One case per billing outcome the package leaves a row for. The values are
 * stored and read back by dashboards and alerts, so a value never changes once
 * shipped.
 */
enum BillingEventType: string
{
    /** A subscriber's entitlement changed in a way a reader would notice. */
    case ENTITLEMENT_APPLIED = 'entitlement_applied';

    /** A rail's entitlement write was refused and the stored one stands; the row's `reason` says which rule. */
    case ENTITLEMENT_DROPPED = 'entitlement_dropped';

    case CHECKOUT_STARTED = 'checkout_started';

    case SUBSCRIPTION_SWAPPED = 'subscription_swapped';

    case SUBSCRIPTION_CANCELLED = 'subscription_cancelled';

    case PORTAL_OPENED = 'portal_opened';

    /** An authenticated request was turned away for a billing reason. */
    case REQUEST_REFUSED = 'request_refused';

    /** A webhook delivery was verified and then turned away. */
    case DELIVERY_REFUSED = 'delivery_refused';

    case TRIAL_RECORDED = 'trial_recorded';

    case TRIAL_REFUSED = 'trial_refused';

    case TRIAL_CANCELLED = 'trial_cancelled';

    /** A refusal was taken back because Stripe no longer reports the subscription as trialing; nothing was cancelled. */
    case TRIAL_REFUSAL_WITHDRAWN = 'trial_refusal_withdrawn';

    /**
     * True when the outcome is the package saying no, which is the subset an
     * operator alerts on.
     */
    public function isRefusal(): bool
    {
        return match ($this) {
            self::ENTITLEMENT_DROPPED, self::REQUEST_REFUSED, self::DELIVERY_REFUSED, self::TRIAL_REFUSED => true,
            self::ENTITLEMENT_APPLIED,
            self::CHECKOUT_STARTED,
            self::SUBSCRIPTION_SWAPPED,
            self::SUBSCRIPTION_CANCELLED,
            self::PORTAL_OPENED,
            self::TRIAL_RECORDED,
            self::TRIAL_CANCELLED,
            self::TRIAL_REFUSAL_WITHDRAWN => false,
        };
    }
}
