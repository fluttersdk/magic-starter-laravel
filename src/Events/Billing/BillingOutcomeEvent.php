<?php

namespace FlutterSdk\MagicStarter\Events\Billing;

use FlutterSdk\MagicStarter\Models\BillingEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The shape every billing outcome event shares: the recorded row, handed over
 * once the surrounding transaction commits. An outcome whose transaction rolls
 * back never happened, so its event is discarded with it.
 */
abstract class BillingOutcomeEvent implements BillingOutcome, ShouldDispatchAfterCommit
{
    public function __construct(public readonly BillingEvent $billingEvent) {}

    public function record(): BillingEvent
    {
        return $this->billingEvent;
    }
}
