<?php

namespace FlutterSdk\MagicStarter\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A scheduled account deletion has been cleared, so the account stays.
 *
 * The counterpart of {@see UserDeletionScheduled}: a host resumes here what it
 * paused there. Fired when a sign-in during the grace period cancels the
 * deletion the user asked for, and when the purge un-schedules an account
 * because a team it owns gained a member.
 */
class UserDeletionCancelled
{
    /**
     * @param  Authenticatable  $user  The account, `deletion_scheduled_at` already cleared.
     */
    public function __construct(
        public readonly Authenticatable $user,
    ) {}
}
