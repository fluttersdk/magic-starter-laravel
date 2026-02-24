<?php

namespace App\Actions\MagicStarter;

use FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles;

/**
 * Handle updating a user's profile information.
 */
class UpdateUserProfile implements UpdatesUserProfiles
{
    /**
     * Update the given user's profile.
     */
    public function update(mixed $user, array $input): void
    {
        // TODO: Implement user profile update logic.
        // Example: validate input, update user attributes, save.
        throw new \RuntimeException('UpdateUserProfile action not implemented. Publish and implement this stub.');
    }
}
