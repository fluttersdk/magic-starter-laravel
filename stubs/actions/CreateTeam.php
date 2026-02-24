<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\CreatesTeams;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Handle creation of a new team.
 */
class CreateTeam implements CreatesTeams
{
    /**
     * Validate and create a new team for the given user.
     *
     * @param  Authenticatable  $user  The user creating the team.
     * @param  array<string, mixed>  $input  The validated team data.
     * @return Model The created team instance.
     */
    public function create(Authenticatable $user, array $input): Model
    {
        // TODO: Implement team creation logic.
        throw new \RuntimeException('CreateTeam action not implemented. Publish and implement this stub.');
    }
}
