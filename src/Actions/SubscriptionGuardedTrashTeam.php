<?php

namespace FlutterSdk\MagicStarter\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Wraps the default {@see TrashTeam} with the refusal
 * {@see SubscriptionGuardedDeleteTeam} raises while either payment rail is
 * still billing the team.
 *
 * With the restorable delete on, `DELETE teams/{team}` trashes rather than
 * deletes, so without this guard the endpoint that refuses a billed team today
 * would hide it instead, and the refusal would arrive only when the purge
 * tried to delete it, a window later and to nobody. The sentence, the error bag
 * and the rule are the delete guard's own, shared rather than copied.
 */
class SubscriptionGuardedTrashTeam extends TrashTeam
{
    public function __construct(
        protected SubscriptionGuardedDeleteTeam $guard,
    ) {}

    /**
     * Trash the team, unless a store or a valid Cashier subscription is still billing it.
     *
     * @param  Model  $team  The team to trash.
     * @param  int|null  $restoreDays  Length of the window in days; null reads the configured default.
     *
     * @throws ValidationException When either rail still funds the team.
     */
    public function trash(Model $team, ?int $restoreDays = null): void
    {
        $this->guard->refuseWhileBilling($team);

        parent::trash($team, $restoreDays);
    }
}
