<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserPasswords;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles;
use FlutterSdk\MagicStarter\Http\Requests\DeleteAccountRequest;
use FlutterSdk\MagicStarter\Http\Requests\UpdatePasswordRequest;
use FlutterSdk\MagicStarter\Http\Requests\UpdateProfileRequest;
use FlutterSdk\MagicStarter\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Handles user profile updates, password changes, and account deletion.
 */
class ProfileController
{
    /**
     * Update the authenticated user's profile.
     */
    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();

        app(UpdatesUserProfiles::class)
            ->update($user, $request->validated());

        return new UserResource($user->fresh());
    }

    /**
     * Update the authenticated user's password.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        app(UpdatesUserPasswords::class)
            ->update(
                $request->user(),
                $request->validated(),
            );

        return response()->json([
            'data' => null, 'message' => 'Password updated successfully.',
        ]);
    }

    /**
     * Delete the authenticated user's account.
     */
    public function destroy(DeleteAccountRequest $request): Response
    {
        app(DeletesUsers::class)
            ->delete($request->user());

        return response()->noContent();
    }
}
