<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Handle user account deletion.
 */
class DeleteUser implements DeletesUsers
{
    /**
     * Delete the given user and associated data.
     *
     * @param  Authenticatable  $user  The user to delete.
     */
    public function delete(Authenticatable $user): void
    {
        // TODO: Implement user deletion logic.
        // Example: authorize, detach from teams, delete personal teams, delete user.
        throw new \RuntimeException('DeleteUser action not implemented. Publish and implement this stub.');
    }
}
