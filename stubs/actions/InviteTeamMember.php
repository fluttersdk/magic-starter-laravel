<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\InvitesTeamMembers;

/**
 * Handle sending a team membership invitation.
 */
class InviteTeamMember implements InvitesTeamMembers
{
    /**
     * Invite a new member to the given team.
     */
    public function invite(mixed $user, mixed $team, string $email, string $role): mixed
    {
        // TODO: Implement team member invitation logic.
        // Example: create invitation record, send invitation email, return invitation.
        throw new \RuntimeException('InviteTeamMember action not implemented. Publish and implement this stub.');
    }
}
