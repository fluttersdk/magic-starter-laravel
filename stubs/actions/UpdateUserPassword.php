<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\UpdatesUserPasswords;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Handle password updates.
 */
class UpdateUserPassword implements UpdatesUserPasswords
{
    /**
     * Validate and update the given user's password.
     *
     * @param  Authenticatable  $user  The user whose password to update.
     * @param  array<string, mixed>  $input  The validated password data.
     */
    public function update(Authenticatable $user, array $input): void
    {
        // TODO: Implement password update logic.
        // Example: check current_password, hash new password, save.
        throw new \RuntimeException('UpdateUserPassword action not implemented. Publish and implement this stub.');
    }
}
