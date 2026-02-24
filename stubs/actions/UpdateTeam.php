<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\UpdatesTeams;

/**
 * Handle updating a team's attributes.
 */
class UpdateTeam implements UpdatesTeams
{
    /**
     * Update the given team with the provided data.
     */
    public function update(mixed $user, mixed $team, array $input): void
    {
        // TODO: Implement team update logic.
        // Example: authorize, validate input, update team attributes, save.
        throw new \RuntimeException('UpdateTeam action not implemented. Publish and implement this stub.');
    }
}
