<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract for deleting a team.
 */
interface DeletesTeams
{
    /**
     * Delete the given team.
     *
     * @param  Model  $team  The team to delete.
     */
    public function delete(Model $team): void;
}
