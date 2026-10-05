<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Events\UserDeletionCancelled;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for taking back an account deletion scheduled through
 * {@see SchedulesUserDeletion}.
 *
 * An orphan's schedule is never cancelled: the identity provider deleted the
 * account, so nobody on this side can take the request back.
 */
interface CancelsUserDeletion
{
    /**
     * Clear the user's deletion schedule and dispatch {@see UserDeletionCancelled}.
     *
     * Refuses nothing: an account with no schedule, or an orphan, is left as it
     * is and answers false.
     *
     * @return bool Whether a schedule was cleared.
     */
    public function cancel(Authenticatable $user): bool;
}
