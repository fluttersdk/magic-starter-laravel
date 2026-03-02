<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\GeneratesNewRecoveryCodes;
use FlutterSdk\MagicStarter\Http\Requests\ConfirmPasswordRequest;
use Illuminate\Http\JsonResponse;

/**
 * Handles listing and regenerating two-factor recovery codes.
 *
 * Both endpoints require password confirmation (sudo mode) to prevent
 * unauthorized access to sensitive recovery codes.
 */
class TwoFactorRecoveryCodeController
{
    /**
     * Get the user's two factor authentication recovery codes.
     *
     * Requires password confirmation via POST body.
     */
    public function index(ConfirmPasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! method_exists($user, 'hasEnabledTwoFactorAuthentication') || ! $user->hasEnabledTwoFactorAuthentication()) {
            return response()->json([
                'message' => 'Two-factor authentication is not enabled.',
            ], 403);
        }

        return response()->json([
            'data' => $user->recoveryCodes(),
            'message' => 'Recovery codes retrieved successfully.',
        ], 200);
    }

    /**
     * Generate a fresh set of two factor authentication recovery codes.
     *
     * Requires password confirmation via POST body.
     */
    public function store(ConfirmPasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! method_exists($user, 'hasEnabledTwoFactorAuthentication') || ! $user->hasEnabledTwoFactorAuthentication()) {
            return response()->json([
                'message' => 'Two-factor authentication is not enabled.',
            ], 403);
        }

        $codes = app(GeneratesNewRecoveryCodes::class)->generate($user);

        return response()->json([
            'data' => $codes,
            'message' => 'Recovery codes regenerated successfully.',
        ], 200);
    }
}
