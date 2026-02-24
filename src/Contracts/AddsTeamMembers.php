<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for adding a member to a team.
 */
interface AddsTeamMembers
{
    /**
     * Add a new team member to the given team.
     *
     * @param  Authenticatable  $user  The user performing the action.
     * @param  Model  $team  The team to add the member to.
     * @param  string  $email  The email of the user to add.
     * @param  string  $role  The role to assign.
     */
    public function add(Authenticatable $user, Model $team, string $email, string $role): void;
}
