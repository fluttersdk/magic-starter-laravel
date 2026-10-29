<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Contracts\ReleasesUserBilling;
use RuntimeException;

/**
 * A rail refused or could not be reached while {@see ReleasesUserBilling} ended
 * a user's subscription.
 *
 * Not a refusal the customer can act on, so it is not a validation error: the
 * scheduler turns it into the 422 `billing_release_failed` and leaves the
 * account unlocked, and the rail's own exception rides along as the previous
 * one for the log. Some of the user's subscriptions may already have ended when
 * it is thrown; a retry finds those ended and asks about the rest.
 */
class BillingReleaseFailed extends RuntimeException {}
