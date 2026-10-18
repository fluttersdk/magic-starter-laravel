<?php

namespace FlutterSdk\MagicStarter\Events\Billing;

/**
 * Fires when Stripe refused the active campaign's promotion code at checkout,
 * so the session was opened again without it.
 */
final class CampaignCodeRefused extends BillingOutcomeEvent {}
