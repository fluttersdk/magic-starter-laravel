<?php

namespace FlutterSdk\MagicStarter\Contracts;

interface DeletesTeams
{
    /**
     * Delete the given team.
     */
    public function delete(mixed $team): void;
}
