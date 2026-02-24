<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\UpdatesUserPasswords;

/**
 * Handle updating a user's password.
 */
class UpdateUserPassword implements UpdatesUserPasswords
{
    /**
     * Update the given user's password.
     */
    public function update(mixed $user, array $input): void
    {
        // TODO: Implement user password update logic.
        // Example: validate current password, hash and update new password.
        throw new \RuntimeException('UpdateUserPassword action not implemented. Publish and implement this stub.');
    }
}
