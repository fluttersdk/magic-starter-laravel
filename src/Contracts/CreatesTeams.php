<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for creating a new team.
 */
interface CreatesTeams
{
    /**
     * Validate and create a new team for the given user.
     *
     * @param  Authenticatable  $user  The user creating the team.
     * @param  array<string, mixed>  $input  The validated team data.
     * @return Model The created team instance.
     */
    public function create(Authenticatable $user, array $input): Model;
}
