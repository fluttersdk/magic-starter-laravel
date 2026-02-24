<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for removing a member from a team.
 */
interface RemovesTeamMembers
{
    /**
     * Remove the given user from the given team.
     *
     * @param  Authenticatable  $user  The user performing the removal.
     * @param  Model  $team  The team to remove from.
     * @param  Model  $teamMember  The member being removed.
     */
    public function remove(Authenticatable $user, Model $team, Model $teamMember): void;
}
