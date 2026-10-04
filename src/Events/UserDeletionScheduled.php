<?php

namespace FlutterSdk\MagicStarter\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * An account has been scheduled for deletion and locked.
 *
 * The seam for a host's own resources: the package locks only what it owns
 * (tokens, push devices), so a host that runs work on the user's behalf pauses
 * it here and resumes it on {@see UserDeletionCancelled}.
 *
 * Dispatched after the schedule has committed, so a queued listener reads the
 * stamped row. Fired again when an already scheduled account is scheduled
 * once more (a provider notification delivered twice), so a listener must be
 * idempotent. An orphan's schedule fires it too.
 */
class UserDeletionScheduled
{
    /**
     * @param  Authenticatable  $user  The locked account, `deletion_scheduled_at` already stamped.
     * @param  bool  $immediate  true when the purge was queued at once instead of after the grace period
     */
    public function __construct(
        public readonly Authenticatable $user,
        public readonly bool $immediate,
    ) {}
}
