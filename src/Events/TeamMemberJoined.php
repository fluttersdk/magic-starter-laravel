<?php

namespace FlutterSdk\MagicStarter\Events;

use FlutterSdk\MagicStarter\Actions\AcceptTeamInvitation;
use FlutterSdk\MagicStarter\Actions\AddTeamMember;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A user has become a member of a team.
 *
 * Dispatched by the default {@see AcceptTeamInvitation} and {@see AddTeamMember},
 * once, after the attach has committed, so a queued listener finds the
 * membership. Neither action fires it for a join it refuses. A team's creator
 * is its owner from the start and never "joins", so creating a team fires
 * nothing.
 *
 * Keys only, as strings whatever the key type: a queued listener holds no
 * model and no name or address.
 */
class TeamMemberJoined implements ShouldDispatchAfterCommit
{
    /**
     * @param  string  $teamId  Key of the team that was joined.
     * @param  string  $userId  Key of the user who joined.
     * @param  string  $actorId  Key of the user who caused the join: the joiner themselves when they
     *                           accepted an invitation, the user who added them otherwise.
     */
    public function __construct(
        public readonly string $teamId,
        public readonly string $userId,
        public readonly string $actorId,
    ) {}
}
