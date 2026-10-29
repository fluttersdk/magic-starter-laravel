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
 *
 * With `account_deletion.billing` set to `release`, scheduling also ends what
 * bills the user through {@see ReleasesUserBilling} and returns what it did, so
 * the caller can tell the person which subscription they still have to cancel.
 *
 * @phpstan-import-type BillingRelease from ReleasesUserBilling
 */
interface SchedulesUserDeletion
{
    /**
     * Schedule the user's account for deletion.
     *
     * @param  bool  $orphan  true when the identity provider deleted the account; an
     *                        orphan is never refused (there is nobody left to act on
     *                        a refusal) and a sign-in does not cancel its schedule. Its
     *                        billing is released like anyone's, and a rail that fails
     *                        stops the schedule
     * @param  bool  $immediately  true to queue the purge now instead of waiting out the
     *                             grace period; the refusals and the lock are unchanged
     * @return BillingRelease what was done about the user's subscriptions: both lists are
     *                        empty unless billing is released and something bills the user
     *
     * @throws ValidationException When the user owns a team someone else belongs to,
     *                             or a team a subscription is still billing, or (unless
     *                             billing is released) a subscription bills the user, or
     *                             a billing rail could not release them; it renders as
     *                             a 422 `{message, code, team_ids}`.
     * @throws RuntimeException When the users table lacks `deletion_scheduled_at`, or
     *                          `account_deletion.billing` is not a mode.
     */
    public function schedule(Authenticatable $user, bool $orphan = false, bool $immediately = false): array;
}
