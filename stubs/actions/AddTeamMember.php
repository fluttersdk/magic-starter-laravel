<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\AddsTeamMembers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Handle adding an existing user to a team.
 */
class AddTeamMember implements AddsTeamMembers
{
    /**
     * Add a new member to the given team.
     *
     * @param  Authenticatable  $user  The user performing the action.
     * @param  Model  $team  The team to add the member to.
     * @param  string  $email  The email of the user to add.
     * @param  string  $role  The role to assign.
     */
    public function add(Authenticatable $user, Model $team, string $email, string $role): void
    {
        // TODO: Implement team member addition logic.
        // Example: find user by email, authorize, attach to team with role.
        throw new \RuntimeException('AddTeamMember action not implemented. Publish and implement this stub.');
    }
}
