<?php

namespace FlutterSdk\MagicStarter\Contracts;

interface InvitesTeamMembers
{
    /**
     * Invite a new team member to the given team.
     */
    public function invite(mixed $user, mixed $team, string $email, string $role): mixed;
}
