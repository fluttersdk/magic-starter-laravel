<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\AddsTeamMembers;

/**
 * Handle adding an existing user to a team.
 */
class AddTeamMember implements AddsTeamMembers
{
    /**
     * Add a new member to the given team.
     */
    public function add(mixed $user, mixed $team, string $email, string $role): void
    {
        // TODO: Implement team member addition logic.
        // Example: find user by email, authorize, attach to team with role.
        throw new \RuntimeException('AddTeamMember action not implemented. Publish and implement this stub.');
    }
}
