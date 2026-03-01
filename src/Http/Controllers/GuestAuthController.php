<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\CreatesGuestUsers;
use FlutterSdk\MagicStarter\Http\Controllers\Concerns\AuthenticatesUsers;
use FlutterSdk\MagicStarter\Http\Requests\GuestLoginRequest;
use Illuminate\Http\JsonResponse;

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
            'Guest session started',
            $wasCreated ? 201 : 200,
        );
    }
}
