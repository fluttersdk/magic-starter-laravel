<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\AddsTeamMembers;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Default action for adding a member to a team by email.
 */
class AddTeamMember implements AddsTeamMembers
{
    /**
     * Add a new team member to the given team.
     *
     * @param  Authenticatable  $user  The user performing the action.
     * @param  Model  $team  The team to add the member to.
     * @param  string  $email  The email of the user to add.
     * @param  string  $role  The role to assign.
     *
     * @throws ValidationException
     */
    public function add(Authenticatable $user, Model $team, string $email, string $role): void
    {
        // 1. Find the user by email using dynamic model resolution.
        $userModel = MagicStarter::userModel();
        $teamMember = $userModel::query()->where('email', $email)->first();

        if (! $teamMember) {
            throw ValidationException::withMessages([
                'email' => ['The selected user could not be found.'],
            ]);
        }

        // 2. Check for duplicate membership (owner or existing member).
        if ((string) $team->user_id === (string) $teamMember->id
            || $team->users()->where('user_id', $teamMember->id)->exists()
        ) {
            throw ValidationException::withMessages([
                'email' => ['This user is already a member of the team.'],
            ]);
        }

        // 3. Attach member with role.
        $team->users()->attach($teamMember->id, ['role' => $role]);
    }
}
