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
     * If no guest user exists for the device, one is created. Subsequent
     * calls with the same device_id return the same user (idempotent).
     *
     * @param  GuestLoginRequest  $request  The validated guest login request.
     */
    public function login(GuestLoginRequest $request): JsonResponse
    {
        $user = app(CreatesGuestUsers::class)->create($request->validated());

        return $this->authenticatedResponse(
            $user,
            $request,
            $this->createAuthToken($user, $request),
            'Guest session started',
        );
    }
}
