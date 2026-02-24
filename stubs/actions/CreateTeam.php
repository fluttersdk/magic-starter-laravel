<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\CreatesTeams;

/**
 * Handle creating a new team for a user.
 */
class CreateTeam implements CreatesTeams
{
    /**
     * Create a new team for the given user.
     */
    public function create(mixed $user, array $input): mixed
    {
        // TODO: Implement team creation logic.
        // Example: validate input, create team, attach owner, return team model.
        throw new \RuntimeException('CreateTeam action not implemented. Publish and implement this stub.');
    }
}
