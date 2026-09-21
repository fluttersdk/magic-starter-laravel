<?php

namespace FlutterSdk\MagicStarter\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A guest session has been claimed by an existing account.
 *
 * This is the whole seam between the claim and a consumer's own data. The
 * package moves only the rows it owns and knows nothing about the tables an
 * application built on top of it: a consumer listens here and reassigns its own
 * rows from `$guest` to `$target` by owner.
 *
 * DISPATCHED SYNCHRONOUSLY, from inside the claim's database transaction, and a
 * QUEUED LISTENER IS NOT SUPPORTED. A listener that implements ShouldQueue runs
 * after the transaction has already committed, so its failure could no longer
 * roll the claim back, and it would read the guest row in its consumed state:
 * tokens deleted and `device_id` cleared. The guarantee this event exists to
 * give is the opposite one, that a listener throwing leaves the guest exactly
 * as it was, so keep the listener synchronous and let it throw.
 *
 * Both sides travel as models rather than as identifiers, because a listener
 * reassigning rows by owner needs the guest's key and the target's key in the
 * same shape its own foreign keys are written in.
 */
class GuestClaimed
{
    /**
     * @param  Authenticatable  $guest  The guest being claimed. Still holds its rows and its device id when a listener runs.
     * @param  Authenticatable  $target  The registered account the rows move to. Authenticated the request.
     */
    public function __construct(
        public readonly Authenticatable $guest,
        public readonly Authenticatable $target,
    ) {}
}
