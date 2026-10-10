<?php

namespace FlutterSdk\MagicStarter\Events\Billing;

use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Support\BillingEventRecorder;

/**
 * The shape every billing outcome event shares: the recorded row, handed over
 * once the surrounding transaction commits. An outcome whose transaction rolls
 * back never happened, so its event is discarded with it.
 *
 * The after-commit timing is the recorder's ({@see BillingEventRecorder}),
 * which dispatches through `DB::afterCommit()` and reports a listener's
 * failure instead of propagating it. The class deliberately does not implement
 * `ShouldDispatchAfterCommit`: that would run listeners unguarded inside the
 * commit callbacks, where a throw breaks the billing that just succeeded.
 */
abstract class BillingOutcomeEvent implements BillingOutcome
{
    public function __construct(public readonly BillingEvent $billingEvent) {}

    public function record(): BillingEvent
    {
        return $this->billingEvent;
    }
}
