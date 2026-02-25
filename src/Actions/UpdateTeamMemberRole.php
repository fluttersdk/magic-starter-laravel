<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\UpdatesTeamMemberRoles;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Default action for updating a team member's role.
 */
class UpdateTeamMemberRole implements UpdatesTeamMemberRoles
{
    /**
     * Update the role of the given team member.
     *
     * @param  Authenticatable  $user  The user performing the action.
     * @param  Model  $team  The team the member belongs to.
     * @param  Model  $teamMember  The member whose role is being updated.
     * @param  string  $role  The new role to assign.
     */
    public function update(Authenticatable $user, Model $team, Model $teamMember, string $role): void
    {
        $team->users()->updateExistingPivot($teamMember->id, [
            'role' => $role,
        ]);
    }
}
