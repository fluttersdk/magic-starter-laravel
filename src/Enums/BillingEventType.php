<?php

namespace FlutterSdk\MagicStarter\Enums;

use FlutterSdk\MagicStarter\Events\Billing\BillingOutcomeEvent;
use FlutterSdk\MagicStarter\Events\Billing\CheckoutStarted;
use FlutterSdk\MagicStarter\Events\Billing\DeliveryRefused;
use FlutterSdk\MagicStarter\Events\Billing\EntitlementApplied;
use FlutterSdk\MagicStarter\Events\Billing\EntitlementDropped;
use FlutterSdk\MagicStarter\Events\Billing\EntitlementSynced;
use FlutterSdk\MagicStarter\Events\Billing\GrantAdded;
use FlutterSdk\MagicStarter\Events\Billing\GrantExpired;
use FlutterSdk\MagicStarter\Events\Billing\GrantRevoked;
use FlutterSdk\MagicStarter\Events\Billing\InvoiceRefunded;
use FlutterSdk\MagicStarter\Events\Billing\PortalOpened;
use FlutterSdk\MagicStarter\Events\Billing\RequestRefused;
use FlutterSdk\MagicStarter\Events\Billing\SubscriptionCancelled;
use FlutterSdk\MagicStarter\Events\Billing\SubscriptionResumed;
use FlutterSdk\MagicStarter\Events\Billing\SubscriptionSwapped;
use FlutterSdk\MagicStarter\Events\Billing\TrialCancelled;
use FlutterSdk\MagicStarter\Events\Billing\TrialEnded;
use FlutterSdk\MagicStarter\Events\Billing\TrialExtended;
use FlutterSdk\MagicStarter\Events\Billing\TrialRecorded;
use FlutterSdk\MagicStarter\Events\Billing\TrialRefusalWithdrawn;
use FlutterSdk\MagicStarter\Events\Billing\TrialRefused;

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

    /** A refusal was taken back: Stripe no longer reports the subscription as trialing, so nothing was cancelled. */
    case TRIAL_REFUSAL_WITHDRAWN = 'trial_refusal_withdrawn';

    case GRANT_ADDED = 'grant_added';

    case GRANT_REVOKED = 'grant_revoked';

    case GRANT_EXPIRED = 'grant_expired';

    case TRIAL_EXTENDED = 'trial_extended';

    case TRIAL_ENDED = 'trial_ended';

    case SUBSCRIPTION_RESUMED = 'subscription_resumed';

    case INVOICE_REFUNDED = 'invoice_refunded';

    case ENTITLEMENT_SYNCED = 'entitlement_synced';

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
            self::TRIAL_REFUSAL_WITHDRAWN,
            self::GRANT_ADDED,
            self::GRANT_REVOKED,
            self::GRANT_EXPIRED,
            self::TRIAL_EXTENDED,
            self::TRIAL_ENDED,
            self::SUBSCRIPTION_RESUMED,
            self::INVOICE_REFUNDED,
            self::ENTITLEMENT_SYNCED => false,
        };
    }

    /**
     * The event dispatched for this outcome.
     *
     * @return class-string<BillingOutcomeEvent>
     */
    public function eventClass(): string
    {
        return match ($this) {
            self::ENTITLEMENT_APPLIED => EntitlementApplied::class,
            self::ENTITLEMENT_DROPPED => EntitlementDropped::class,
            self::CHECKOUT_STARTED => CheckoutStarted::class,
            self::SUBSCRIPTION_SWAPPED => SubscriptionSwapped::class,
            self::SUBSCRIPTION_CANCELLED => SubscriptionCancelled::class,
            self::PORTAL_OPENED => PortalOpened::class,
            self::REQUEST_REFUSED => RequestRefused::class,
            self::DELIVERY_REFUSED => DeliveryRefused::class,
            self::TRIAL_RECORDED => TrialRecorded::class,
            self::TRIAL_REFUSED => TrialRefused::class,
            self::TRIAL_CANCELLED => TrialCancelled::class,
            self::TRIAL_REFUSAL_WITHDRAWN => TrialRefusalWithdrawn::class,
            self::GRANT_ADDED => GrantAdded::class,
            self::GRANT_REVOKED => GrantRevoked::class,
            self::GRANT_EXPIRED => GrantExpired::class,
            self::TRIAL_EXTENDED => TrialExtended::class,
            self::TRIAL_ENDED => TrialEnded::class,
            self::SUBSCRIPTION_RESUMED => SubscriptionResumed::class,
            self::INVOICE_REFUNDED => InvoiceRefunded::class,
            self::ENTITLEMENT_SYNCED => EntitlementSynced::class,
        };
    }
}
