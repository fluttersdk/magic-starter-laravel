<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\SchedulesUserDeletion;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserPasswords;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles;
use FlutterSdk\MagicStarter\Http\Requests\DeleteAccountRequest;
use FlutterSdk\MagicStarter\Http\Requests\UpdatePasswordRequest;
use FlutterSdk\MagicStarter\Http\Requests\UpdateProfileRequest;
use FlutterSdk\MagicStarter\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

/**
 * Handles user profile updates, password changes, and account deletion requests.
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
            'data' => null, 'message' => __('magic-starter::profile.password_updated'),
        ]);
    }

    /**
     * Schedule the authenticated user's account for deletion.
     *
     * 202 rather than 204 because nothing is deleted yet: the account is locked
     * now (every token revoked) and purged after `account_deletion.grace_days`,
     * and signing in again before then cancels it. A shared or billing owned
     * team answers the scheduler's 422 `{message, code, team_ids}` instead.
     */
    public function destroy(DeleteAccountRequest $request): JsonResponse
    {
        $user = $request->user();

        app(SchedulesUserDeletion::class)->schedule($user);

        return response()->json([
            'data' => [
                'deletion_scheduled_at' => $user->deletion_scheduled_at,
            ],
            'message' => __('magic-starter::social.deletion_scheduled', [
                'days' => (int) config('magic-starter.account_deletion.grace_days', 30),
            ]),
        ], 202);
    }
}
