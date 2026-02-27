<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;

/**
 * Default user deletion action with token and photo cleanup.
 */
class DeleteUser implements DeletesUsers
{
    /**
     * Delete the given user and clean up associated resources.
     *
     * @param  Authenticatable  $user  The user to delete.
     */
    public function delete(Authenticatable $user): void
    {
        // 1. Revoke all API tokens.
        $user->tokens()->delete();

        // 2. Delete profile photo from storage if present.
        if (! empty($user->profile_photo_path)) {
            Storage::disk(config('magic-starter.profile_photo_disk', 'public'))
                ->delete((string) $user->profile_photo_path);
        }

        // 3. Delete the user.
        $user->delete();
    }
}
