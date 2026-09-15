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
                'message' => __('magic-starter::auth.two_factor.not_enabled'),
            ], 403);
        }

        return response()->json([
            'data' => $user->recoveryCodes(),
            'message' => __('magic-starter::auth.two_factor.recovery_codes_retrieved'),
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
                'message' => __('magic-starter::auth.two_factor.not_enabled'),
            ], 403);
        }

        $codes = app(GeneratesNewRecoveryCodes::class)->generate($user);

        return response()->json([
            'data' => $codes,
            'message' => __('magic-starter::auth.two_factor.recovery_codes_regenerated'),
        ], 200);
    }
}
