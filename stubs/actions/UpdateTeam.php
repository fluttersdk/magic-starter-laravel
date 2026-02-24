<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\UpdatesTeams;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Handle team settings updates.
 */
class UpdateTeam implements UpdatesTeams
{
    /**
     * Validate and update the given team.
     *
     * @param  Authenticatable  $user  The user performing the update.
     * @param  Model  $team  The team to update.
     * @param  array<string, mixed>  $input  The validated update data.
     */
    public function update(Authenticatable $user, Model $team, array $input): void
    {
        // TODO: Implement team update logic.
        // Example: authorize, update model attributes.
        throw new \RuntimeException('UpdateTeam action not implemented. Publish and implement this stub.');
    }
}
