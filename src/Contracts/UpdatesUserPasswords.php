<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for updating user passwords.
 */
interface UpdatesUserPasswords
{
    /**
     * Validate and update the given user's password.
     *
     * @param  Authenticatable  $user  The user whose password to update.
     * @param  array<string, mixed>  $input  The validated password data.
     */
    public function update(Authenticatable $user, array $input): void;
}
