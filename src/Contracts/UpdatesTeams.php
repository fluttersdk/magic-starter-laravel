<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for updating a team.
 */
interface UpdatesTeams
{
    /**
     * Validate and update the given team.
     *
     * @param  Authenticatable  $user  The user performing the update.
     * @param  Model  $team  The team to update.
     * @param  array<string, mixed>  $input  The validated update data.
     */
    public function update(Authenticatable $user, Model $team, array $input): void;
}
