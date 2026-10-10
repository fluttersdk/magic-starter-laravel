<?php

namespace FlutterSdk\MagicStarter\Events\Billing;

/**
 * Fires when a rail's entitlement write was refused and the stored entitlement
 * stands; the record's `reason` names the rule.
 */
final class EntitlementDropped extends BillingOutcomeEvent {}
