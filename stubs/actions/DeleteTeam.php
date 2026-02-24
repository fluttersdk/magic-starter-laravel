<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use Illuminate\Database\Eloquent\Model;

/**
 * Handle team deletion.
 */
class DeleteTeam implements DeletesTeams
{
    /**
     * Delete the given team.
     *
     * @param  Model  $team  The team to delete.
     */
    public function delete(Model $team): void
    {
        // TODO: Implement team deletion logic.
        // Example: authorize, detach members, delete related models, delete team.
        throw new \RuntimeException('DeleteTeam action not implemented. Publish and implement this stub.');
    }
}
