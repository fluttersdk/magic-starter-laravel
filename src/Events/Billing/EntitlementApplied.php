<?php

namespace FlutterSdk\MagicStarter\Events\Billing;

/**
 * Fires when a subscriber's entitlement changed in a way a reader would notice, from a webhook or the reconciler.
 */
final class EntitlementApplied extends BillingOutcomeEvent {}
