<?php

namespace FlutterSdk\MagicStarter\Events\Billing;

/**
 * Fires when a manual plan grant reached its expiry and the plan was released.
 */
final class GrantExpired extends BillingOutcomeEvent {}
