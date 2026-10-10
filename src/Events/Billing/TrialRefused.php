<?php

namespace FlutterSdk\MagicStarter\Events\Billing;

/**
 * Fires when a trial was refused because the card or the subject had already taken one.
 */
final class TrialRefused extends BillingOutcomeEvent {}
