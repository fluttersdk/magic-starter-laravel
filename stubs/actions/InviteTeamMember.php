<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\InvitesTeamMembers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Handle inviting a new user to a team.
 */
class InviteTeamMember implements InvitesTeamMembers
{
    /**
     * Invite a new team member to the given team.
     *
     * @param  Authenticatable  $user  The user performing the invitation.
     * @param  Model  $team  The team to invite the member to.
     * @param  string  $email  The email address to invite.
     * @param  string  $role  The role to assign on acceptance.
     * @return Model The created invitation.
     */
    public function invite(Authenticatable $user, Model $team, string $email, string $role): Model
    {
        // TODO: Implement team invitation logic.
        // Example: Create invitation model, trigger event/email.
        throw new \RuntimeException('InviteTeamMember action not implemented. Publish and implement this stub.');
    }
}
