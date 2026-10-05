<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Contract for handing a team to another of its members.
 *
 * Ownership is `teams.user_id` plus the `owner` pivot role, and the two move
 * together: the new owner takes both, the outgoing owner stays on the team as
 * an admin, and a personal team stops being personal, since it is nobody's own
 * any more.
 */
interface TransfersTeamOwnership
{
    /**
     * Make `$newOwner` the owner of `$team`.
     *
     * @param  Authenticatable  $actor  The user performing the transfer.
     *
     * @throws ValidationException When `$newOwner` is not a member of the team
     *                             (code `new_owner_not_a_member`); nothing changes.
     */
    public function transfer(Authenticatable $actor, Model $team, Model $newOwner): void;
}
