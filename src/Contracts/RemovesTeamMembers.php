<?php

namespace FlutterSdk\MagicStarter\Contracts;

interface RemovesTeamMembers
{
    /**
     * Remove the given user from the given team.
     */
    public function remove(mixed $user, mixed $team, mixed $teamMember): void;
}
