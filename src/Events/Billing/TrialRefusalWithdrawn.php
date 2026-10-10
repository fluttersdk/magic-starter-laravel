<?php

namespace FlutterSdk\MagicStarter\Events\Billing;

/**
 * Fires when a trial refusal was taken back because the subscription no longer trials; nothing was cancelled.
 */
final class TrialRefusalWithdrawn extends BillingOutcomeEvent {}
