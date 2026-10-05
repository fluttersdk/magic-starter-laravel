<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\CancelsUserDeletion;
use FlutterSdk\MagicStarter\Events\UserDeletionCancelled;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Default action for taking back a scheduled account deletion.
 *
 * Reads the schedule through attribute presence rather than a schema query,
 * so an older users table without the deletion columns reads null and pays
 * nothing on the sign-in path that calls this.
 */
class CancelUserDeletion implements CancelsUserDeletion
{
    /**
     * Clear the user's deletion schedule and dispatch {@see UserDeletionCancelled}.
     *
     * @return bool Whether a schedule was cleared; false with no schedule or for an orphan.
     */
    public function cancel(Authenticatable $user): bool
    {
        if (! $user instanceof Model
            || $user->getAttribute('deletion_scheduled_at') === null
            || $user->getAttribute('orphaned_at') !== null
        ) {
            return false;
        }

        $user->forceFill([
            'deletion_scheduled_at' => null,
        ])->save();

        // The account stays, so a host resumes what it paused on the schedule.
        Event::dispatch(new UserDeletionCancelled($user));

        return true;
    }
}
