<?php

namespace FlutterSdk\MagicStarter\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Default authorization policy for team operations.
 *
 * Registered automatically by the service provider when the teams feature
 * is enabled. Consumers can override this policy by calling
 * `Gate::policy(Team::class, CustomTeamPolicy::class)` in their
 * AuthServiceProvider — app providers boot after package providers.
 */
class TeamPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view the team.
     *
     * @param  mixed  $user  The authenticated user.
     * @param  mixed  $team  The team being viewed.
     */
    public function view(mixed $user, mixed $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can update the team.
     *
     * @param  mixed  $user  The authenticated user.
     * @param  mixed  $team  The team being updated.
     */
    public function update(mixed $user, mixed $team): bool
    {
        return $user->ownsTeam($team);
    }

    /**
     * Determine whether the user can delete the team.
     *
     * @param  mixed  $user  The authenticated user.
     * @param  mixed  $team  The team being deleted.
     */
    public function delete(mixed $user, mixed $team): bool
    {
        return $user->ownsTeam($team);
    }

    /**
     * Determine whether the user can manage team members.
     *
     * Allowed for team owners and users with the 'admin' role.
     *
     * @param  mixed  $user  The authenticated user.
     * @param  mixed  $team  The team whose members are being managed.
     */
    public function manageMembers(mixed $user, mixed $team): bool
    {
        return $user->ownsTeam($team)
            || $user->hasTeamRole($team, 'admin');
    }

    /**
     * Determine whether the user can manage team invitations.
     *
     * Allowed for team owners and users with the 'admin' role.
     *
     * @param  mixed  $user  The authenticated user.
     * @param  mixed  $team  The team whose invitations are being managed.
     */
    public function manageInvitations(mixed $user, mixed $team): bool
    {
        return $user->ownsTeam($team)
            || $user->hasTeamRole($team, 'admin');
    }

    /**
     * Determine whether the user can switch to the team.
     *
     * @param  mixed  $user  The authenticated user.
     * @param  mixed  $team  The team to switch to.
     */
    public function switchTo(mixed $user, mixed $team): bool
    {
        return $user->belongsToTeam($team);
    }
}
