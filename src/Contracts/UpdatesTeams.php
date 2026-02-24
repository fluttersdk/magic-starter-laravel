<?php

namespace FlutterSdk\MagicStarter\Contracts;

interface UpdatesTeams
{
    /**
     * Validate and update the given team.
     */
    public function update(mixed $user, mixed $team, array $input): void;
}
