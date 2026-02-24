<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\RemovesTeamMembers;

/**
 * Handle removing a member from a team.
 */
class RemoveTeamMember implements RemovesTeamMembers
{
    /**
     * Remove the given member from the team.
     */
    public function remove(mixed $user, mixed $team, mixed $teamMember): void
    {
        // TODO: Implement team member removal logic.
        // Example: authorize, detach member from team.
        throw new \RuntimeException('RemoveTeamMember action not implemented. Publish and implement this stub.');
    }
}
