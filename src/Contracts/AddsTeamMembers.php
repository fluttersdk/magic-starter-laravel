<?php

namespace FlutterSdk\MagicStarter\Contracts;

interface AddsTeamMembers
{
    /**
     * Add a new team member to the given team.
     */
    public function add(mixed $user, mixed $team, string $email, string $role): void;
}
