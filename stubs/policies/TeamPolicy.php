<?php

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Publishable stub for TeamPolicy.
 * Consumers can publish this and customize authorization logic.
 */
class TeamPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view the team.
     */
    public function view(mixed $user, mixed $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can update the team.
     */
    public function update(mixed $user, mixed $team): bool
    {
        return $user->ownsTeam($team);
    }

    /**
     * Determine whether the user can delete the team.
     */
    public function delete(mixed $user, mixed $team): bool
    {
        return $user->ownsTeam($team);
    }

    /**
     * Determine whether the user can manage team members.
     */
    public function manageMembers(mixed $user, mixed $team): bool
    {
        return $user->ownsTeam($team) || $user->hasTeamRole($team, 'admin');
    }

    /**
     * Determine whether the user can manage team invitations.
     */
    public function manageInvitations(mixed $user, mixed $team): bool
    {
        return $user->ownsTeam($team) || $user->hasTeamRole($team, 'admin');
    }

    /**
     * Determine whether the user can switch to the team.
     */
    public function switchTo(mixed $user, mixed $team): bool
    {
        return $user->belongsToTeam($team);
    }
}
