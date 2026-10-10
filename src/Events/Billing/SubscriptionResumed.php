<?php

namespace FlutterSdk\MagicStarter\Events\Billing;

/**
 * Fires when an operator resumed a Stripe subscription that was cancelled at period end.
 */
final class SubscriptionResumed extends BillingOutcomeEvent {}
