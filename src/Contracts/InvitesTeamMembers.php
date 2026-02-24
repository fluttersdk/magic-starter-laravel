<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for inviting a member to a team.
 */
interface InvitesTeamMembers
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
    public function invite(Authenticatable $user, Model $team, string $email, string $role): Model;
}
