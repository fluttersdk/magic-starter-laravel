<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\GeneratesNewRecoveryCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorRecoveryCodeController
{
    /**
     * Get the user's two factor authentication recovery codes.
     */
    public function index(Request $request): JsonResponse
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
     */
    public function store(Request $request): JsonResponse
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
