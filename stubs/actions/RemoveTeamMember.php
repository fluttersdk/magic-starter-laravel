<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\RemovesTeamMembers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Handle removing a member from a team.
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
        // TODO: Implement member removal logic.
        // Example: check authorization, cannot remove owner, detach from team pivot.
        throw new \RuntimeException('RemoveTeamMember action not implemented. Publish and implement this stub.');
    }
}
