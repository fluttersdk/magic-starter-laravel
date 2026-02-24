<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for deleting a user account.
 */
interface DeletesUsers
{
    /**
     * Delete the given user.
     *
     * @param  Authenticatable  $user  The user to delete.
     */
    public function delete(Authenticatable $user): void;
}
