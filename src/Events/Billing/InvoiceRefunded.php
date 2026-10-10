<?php

namespace FlutterSdk\MagicStarter\Events\Billing;

/**
 * Fires when an operator refunded a paid Stripe invoice.
 */
final class InvoiceRefunded extends BillingOutcomeEvent {}
