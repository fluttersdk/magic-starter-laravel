<?php

namespace FlutterSdk\MagicStarter\Contracts;

interface UpdatesUserProfiles
{
    /**
     * Validate and update the given user's profile information.
     */
    public function update(mixed $user, array $input): void;
}
