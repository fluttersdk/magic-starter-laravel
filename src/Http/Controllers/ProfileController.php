<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\SchedulesUserDeletion;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserPasswords;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles;
use FlutterSdk\MagicStarter\Http\Requests\DeleteAccountRequest;
use FlutterSdk\MagicStarter\Http\Requests\UpdatePasswordRequest;
use FlutterSdk\MagicStarter\Http\Requests\UpdateProfileRequest;
use FlutterSdk\MagicStarter\Http\Resources\UserResource;
use FlutterSdk\MagicStarter\Support\OwnedTeams;
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
     *
     * With `immediately` the same schedule runs and the purge is queued at once
     * rather than after the grace period; still 202, since the deletion happens
     * on the queue, and `data.immediate` is true. Without it the body is the
     * scheduled one, unchanged.
     *
     * With `account_deletion.billing` set to `release` the body also carries
     * `data.billing`: the subscriptions ended now (`cancelled`) and the store
     * subscriptions the user still has to cancel in the store (`store`), so a
     * client can send them to the store's cancel screen. In the default `refuse`
     * mode the key is absent, and a subscription refuses the request instead.
     */
    public function destroy(DeleteAccountRequest $request): JsonResponse
    {
        $user = $request->user();
        $immediately = $request->boolean('immediately');

        $billing = app(SchedulesUserDeletion::class)->schedule($user, immediately: $immediately);

        $data = [
            'deletion_scheduled_at' => $user->deletion_scheduled_at,
        ];

        if ($immediately) {
            $data['immediate'] = true;
        }

        if (OwnedTeams::releasesBilling()) {
            $data['billing'] = $billing;
        }

        return response()->json([
            'data' => $data,
            'message' => $immediately
                ? __('magic-starter::social.deletion_immediate')
                : __('magic-starter::social.deletion_scheduled', [
                    'days' => (int) config('magic-starter.account_deletion.grace_days', 30),
                ]),
        ], 202);
    }
}
