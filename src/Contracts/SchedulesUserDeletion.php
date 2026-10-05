<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Contract for scheduling an account deletion: the one entry point to the
 * deletion pipeline, for a user's own request and for an identity provider
 * reporting the account deleted (an orphan) alike.
 *
 * Scheduling locks the account at once and deletes nothing. The purge command
 * deletes it through {@see DeletesUsers} once `account_deletion.grace_days`
 * have passed, and a sign-in during that window cancels a schedule the user
 * asked for. An immediate deletion is the same schedule followed by a queued
 * purge, never a deletion inside the caller's request.
 */
interface SchedulesUserDeletion
{
    /**
     * Schedule the user's account for deletion.
     *
     * @param  bool  $orphan  true when the identity provider deleted the account; an
     *                        orphan is never refused (there is nobody left to act on
     *                        a refusal) and a sign-in does not cancel its schedule
     * @param  bool  $immediately  true to queue the purge now instead of waiting out the
     *                             grace period; the refusals and the lock are unchanged
     *
     * @throws ValidationException When the user owns a team someone else belongs to,
     *                             or a team a subscription is still billing; it renders
     *                             as a 422 `{message, code, team_ids}`.
     * @throws RuntimeException When the users table lacks `deletion_scheduled_at`.
     */
    public function schedule(Authenticatable $user, bool $orphan = false, bool $immediately = false): void;
}
