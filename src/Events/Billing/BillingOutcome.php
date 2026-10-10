<?php

namespace FlutterSdk\MagicStarter\Events\Billing;

use FlutterSdk\MagicStarter\Models\BillingEvent;

/**
 * Every billing outcome event, so a listener can subscribe to all of them at
 * once: `Event::listen(BillingOutcome::class, ...)` receives each one, because
 * Laravel's dispatcher also calls the listeners of every interface an event
 * implements. Subscribe to one concrete class for a single outcome.
 *
 * The package registers no listener of its own; this is the seam an
 * application hooks alerts, metrics or notifications into.
 *
 * A synchronous listener runs in the billing path, after the outcome's
 * transaction commits: inside the webhook or request that produced it. Its
 * failure is reported through `report()` and never propagated, so billing
 * carries on, but the listener's own work is lost. Prefer a `ShouldQueue`
 * listener for anything that can fail or take time.
 */
interface BillingOutcome
{
    /**
     * The outcome as recorded. It is unsaved (`exists` false) when the row
     * could not be written, so read its attributes, not its key.
     */
    public function record(): BillingEvent;
}
