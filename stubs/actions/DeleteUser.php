<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\DeletesUsers;

/**
 * Handle deleting a user account and cleaning up related data.
 */
class DeleteUser implements DeletesUsers
{
    /**
     * Delete the given user.
     */
    public function delete(mixed $user): void
    {
        // TODO: Implement user deletion logic.
        // Example: revoke tokens, delete profile photo, delete user record.
        throw new \RuntimeException('DeleteUser action not implemented. Publish and implement this stub.');
    }
}
