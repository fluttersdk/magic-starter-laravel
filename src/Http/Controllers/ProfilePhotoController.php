<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Requests\UpdateProfilePhotoRequest;
use FlutterSdk\MagicStarter\Http\Resources\UserResource;

/**
 * Handles profile photo upload and deletion.
 */
class ProfilePhotoController
{
    /**
     * Update the authenticated user's profile photo.
     */
    public function update(UpdateProfilePhotoRequest $request): UserResource
    {
        $user = $request->user();
        $disk = (string) (config('magic-starter.profile_photo_disk')
            ?? config('filesystems.default', 'public'));
        $filesystem = app('filesystem')->disk($disk);

        if (! empty($user->profile_photo_path)) {
            $filesystem->delete((string) $user->profile_photo_path);
        }

        $path = $request->file('photo')->storePublicly(
            config('magic-starter.profile_photo_path', 'profile-photos'),
            ['disk' => $disk],
        );

        $user->forceFill([
            'profile_photo_path' => $path,
        ])->save();

        return new UserResource($user->fresh());
    }

    /**
     * Delete the authenticated user's profile photo.
     *
     * @param  mixed|null  $request
     */
    public function delete($request = null): UserResource
    {
        $request ??= request();

        $user = $request->user();
        $disk = (string) (config('magic-starter.profile_photo_disk')
            ?? config('filesystems.default', 'public'));
        $filesystem = app('filesystem')->disk($disk);

        if (! empty($user->profile_photo_path)) {
            $filesystem->delete((string) $user->profile_photo_path);

            $user->forceFill([
                'profile_photo_path' => null,
            ])->save();
        }

        return new UserResource($user->fresh());
    }
}
