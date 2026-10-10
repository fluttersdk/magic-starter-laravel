<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Which path of the package reached a billing outcome, as
 * `billing_events.source` stores it.
 *
 * The same outcome can arrive by more than one path (a plan is dropped by a
 * webhook and again by the nightly reconciler), and a reader debugging "why did
 * this subscriber lose access" needs to know which one acted.
 */
enum BillingSource: string
{
    /** A payment rail's webhook delivery (Stripe or RevenueCat). */
    case WEBHOOK = 'webhook';

    /** The scheduled reconciler correcting a stored entitlement against the rail. */
    case RECONCILE = 'reconcile';

    /** An authenticated API request from the app (checkout, swap, cancel, portal). */
    case REQUEST = 'request';

    /** The queued trial card check. */
    case TRIAL_CHECK = 'trial_check';

    /** An operator action in the admin panel. */
    case ADMIN = 'admin';
}
