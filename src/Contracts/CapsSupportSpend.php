<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Throwable;

/**
 * A host's own ceiling on what the AI desk may spend in a day, across every customer.
 *
 * OPTIONAL, and shipped with no binding: the package's quota counts tokens per
 * user, team and pool, but only the host knows what a token costs and what the
 * day's budget is. With nothing bound no cap applies, and the package never
 * falls back to a stub that decides.
 *
 * The job asks once per turn, right before it reserves quota, so a spent cap
 * leaves no reservation to settle and calls no model: the turn is handed to
 * staff as `quota`, like a spent pool. It must be cheap and must not call a
 * model.
 *
 * Failure mode: a throw is not swallowed; it fails the turn like a responder
 * that throws, retried by the queue and handed off as `unavailable` once the
 * retries run out.
 */
interface CapsSupportSpend
{
    /**
     * Whether today's spend has reached the host's ceiling.
     *
     * @throws Throwable when the spend cannot be read
     */
    public function exceeded(): bool;
}
