<?php

namespace FlutterSdk\MagicStarter\Events;

use FlutterSdk\MagicStarter\Actions\DeleteTeam;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A team has been deleted for good, with its memberships.
 *
 * Dispatched by the default {@see DeleteTeam}, the purge, once, after the delete
 * has committed: from the delete endpoint while the restorable delete is off,
 * from `magic-starter:purge-trashed-teams` when a trashed team's window closes,
 * and from an account purge for each team the user owned. A refused delete (the
 * billing guard) fires nothing. Hiding a team for a restore window is
 * {@see TeamTrashed}.
 *
 * The team and its memberships are gone by the time a listener runs, so the
 * members are captured before the detach: a listener can still tell every
 * former member. Keys only, as strings whatever the key type.
 */
class TeamDeleted implements ShouldDispatchAfterCommit
{
    /**
     * @param  string  $teamId  Key of the team that no longer exists.
     * @param  string  $ownerId  Key of the team's owner when it was deleted.
     * @param  list<string>  $memberIds  Keys of everyone who belonged to the team, the owner included
     *                                   even when the owner has no membership row; in no particular order.
     */
    public function __construct(
        public readonly string $teamId,
        public readonly string $ownerId,
        public readonly array $memberIds,
    ) {}
}
