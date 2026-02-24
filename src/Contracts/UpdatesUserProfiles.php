<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for updating user profile information.
 */
interface UpdatesUserProfiles
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  Authenticatable  $user  The user to update.
     * @param  array<string, mixed>  $input  The validated profile data.
     */
    public function update(Authenticatable $user, array $input): void;
}
