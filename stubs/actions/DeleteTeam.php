<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\DeletesTeams;

/**
 * Handle deleting a team and its associated data.
 */
class DeleteTeam implements DeletesTeams
{
    /**
     * Delete the given team.
     */
    public function delete(mixed $team): void
    {
        // TODO: Implement team deletion logic.
        // Example: detach members, delete invitations, delete team record.
        throw new \RuntimeException('DeleteTeam action not implemented. Publish and implement this stub.');
    }
}
