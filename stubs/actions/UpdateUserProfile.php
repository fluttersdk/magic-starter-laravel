<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Handle user profile updates.
 */
class UpdateUserProfile implements UpdatesUserProfiles
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  Authenticatable  $user  The user to update.
     * @param  array<string, mixed>  $input  The validated profile data.
     */
    public function update(Authenticatable $user, array $input): void
    {
        // TODO: Implement profile update logic.
        // Example: handle unique email check, update name/email, save.
        throw new \RuntimeException('UpdateUserProfile action not implemented. Publish and implement this stub.');
    }
}
