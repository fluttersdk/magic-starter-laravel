<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\ClaimsGuestAccounts;
use FlutterSdk\MagicStarter\Contracts\CreatesGuestUsers;
use FlutterSdk\MagicStarter\Http\Controllers\Concerns\AuthenticatesUsers;
use FlutterSdk\MagicStarter\Http\Requests\GuestLoginRequest;
use FlutterSdk\MagicStarter\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles guest authentication by device ID.
 *
 * Creates or retrieves an existing guest user for the given device,
 * then issues a Sanctum token for the session.
 */
class GuestAuthController
{
    use AuthenticatesUsers;

    /**
     * Authenticate a guest user by device ID.
     *
     * If no guest user exists for the device, one is created (201 Created).
     * Subsequent calls with the same device_id return the same user (200 OK).
     *
     * @param  GuestLoginRequest  $request  The validated guest login request.
     */
    public function login(GuestLoginRequest $request): JsonResponse
    {
        $user = app(CreatesGuestUsers::class)->create($request->validated());

        // Determine if the user was just created by Eloquent's firstOrCreate.
        $wasCreated = property_exists($user, 'wasRecentlyCreated')
            ? $user->wasRecentlyCreated
            : false;

        // 1. Revoke all existing tokens for returning guests to prevent session buildup.
        if (! $wasCreated && method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        // 2. Create a fresh token with device info (ip_address, user_agent).
        return $this->authenticatedResponse(
            $user,
            $request,
            $this->createAuthToken($user, $request, storeDeviceInfo: true),
            (string) __('magic-starter::auth.guest_session_started'),
            $wasCreated ? 201 : 200,
        );
    }

    /**
     * Claim a guest session into the authenticated account.
     *
     * The caller holds the TARGET account's token and presents the guest
     * session's own token in the body, so the endpoint can verify both halves
     * by possession. The rows this package owns move and the guest row is
     * consumed; {@see \FlutterSdk\MagicStarter\Events\GuestClaimed} is what lets
     * a consumer move its own tables inside the same transaction.
     *
     * Answers 200 in both directions, with `data.claimed` saying which one
     * happened. A repeat of a claim that already succeeded finds no live guest
     * session and reports `false`, which keeps a client's retry safe without
     * pretending a second transfer took place. The body carries no sentence of
     * its own: a claim is a merge a client performs on somebody's behalf rather
     * than a screen with a message on it, and the package would have to freeze
     * one language into it to supply one.
     *
     * @param  Request  $request  The authenticated request carrying `guest_token`.
     */
    public function claim(Request $request): JsonResponse
    {
        $target = $request->user();

        $claimed = app(ClaimsGuestAccounts::class)->claim($target, $request->all());

        return response()->json([
            'data' => [
                'user' => new UserResource($target),
                'claimed' => $claimed,
            ],
        ]);
    }
}
