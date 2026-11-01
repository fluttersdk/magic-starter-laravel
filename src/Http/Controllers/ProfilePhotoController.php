<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Requests\UpdateProfilePhotoRequest;
use FlutterSdk\MagicStarter\Http\Resources\UserResource;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Http\Request;

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
        $disk = MagicStarter::profilePhotoDisk();
        $filesystem = app('filesystem')->disk($disk);

        if (! empty($user->profile_photo_path)) {
            $filesystem->delete((string) $user->profile_photo_path);
        }

        // No visibility option: on an S3-compatible CDN it rides on the write as an ACL.
        // The disk itself decides whether the file is public.
        $path = $request->file('photo')->store(
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
     */
    public function delete(Request $request): UserResource
    {
        $user = $request->user();
        $filesystem = app('filesystem')->disk(MagicStarter::profilePhotoDisk());

        if (! empty($user->profile_photo_path)) {
            $filesystem->delete((string) $user->profile_photo_path);

            $user->forceFill([
                'profile_photo_path' => null,
            ])->save();
        }

        return new UserResource($user->fresh());
    }
}
