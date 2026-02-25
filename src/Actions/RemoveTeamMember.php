<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\RemovesTeamMembers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Default action for removing a member from a team.
 */
class RemoveTeamMember implements RemovesTeamMembers
{
    /**
     * Remove the given user from the given team.
     *
     * @param  Authenticatable  $user  The user performing the removal.
     * @param  Model  $team  The team to remove from.
     * @param  Model  $teamMember  The member being removed.
     */
    public function remove(Authenticatable $user, Model $team, Model $teamMember): void
    {
        $team->users()->detach($teamMember->id);
    }
}
