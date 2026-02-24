<?php

namespace FlutterSdk\MagicStarter\Contracts;

interface UpdatesUserPasswords
{
    /**
     * Validate and update the given user's password.
     */
    public function update(mixed $user, array $input): void;
}
