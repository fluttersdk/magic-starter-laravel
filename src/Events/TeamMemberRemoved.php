<?php

namespace FlutterSdk\MagicStarter\Events;

use FlutterSdk\MagicStarter\Actions\RemoveTeamMember;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A member is no longer on a team, removed or having left.
 *
 * Dispatched by the default {@see RemoveTeamMember}, once, after the detach has
 * committed. `$actorId` equals `$userId` when the member left. Nothing is fired
 * when the action refuses (the owner is never removed) or when the user was not
 * a member to begin with.
 *
 * Deleting a team detaches its members without this event, and so does
 * deleting an account that belonged to other people's teams: see
 * {@see TeamDeleted}.
 *
 * Keys only, as strings whatever the key type.
 */
class TeamMemberRemoved implements ShouldDispatchAfterCommit
{
    /**
     * @param  string  $teamId  Key of the team the member left.
     * @param  string  $userId  Key of the user who is no longer a member.
     * @param  string  $actorId  Key of the user who performed the removal.
     */
    public function __construct(
        public readonly string $teamId,
        public readonly string $userId,
        public readonly string $actorId,
    ) {}
}
