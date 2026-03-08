<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\ConfirmsTwoFactorAuthentication;
use FlutterSdk\MagicStarter\Contracts\DisablesTwoFactorAuthentication;
use FlutterSdk\MagicStarter\Contracts\EnablesTwoFactorAuthentication;
use FlutterSdk\MagicStarter\Http\Requests\ConfirmTwoFactorRequest;
use FlutterSdk\MagicStarter\Http\Requests\DisableTwoFactorRequest;
use FlutterSdk\MagicStarter\Http\Requests\EnableTwoFactorRequest;
use Illuminate\Http\JsonResponse;

class TwoFactorAuthenticationController
{
    /**
     * Enable two factor authentication for the user.
     */
    public function store(EnableTwoFactorRequest $request): JsonResponse
    {
        $user = $request->user();

        $data = app(EnablesTwoFactorAuthentication::class)->enable($user);

        return response()->json([
            'data' => [
                'secret' => $data['secret'],
                'qr_url' => $data['qr_url'],
                'qr_svg' => $user->twoFactorQrCodeSvg(),
                'recovery_codes' => $data['recovery_codes'],
            ],
            'message' => 'Two-factor authentication enabled. Please confirm with your authenticator app.',
        ], 200);
    }

    /**
     * Confirm two factor authentication for the user.
     */
    public function confirm(ConfirmTwoFactorRequest $request): JsonResponse
    {
        $user = $request->user();

        app(ConfirmsTwoFactorAuthentication::class)->confirm(
            $user,
            (string) $request->validated('code'),
        );

        return response()->json([
            'data' => null,
            'message' => 'Two-factor authentication confirmed successfully.',
        ], 200);
    }

    /**
     * Disable two factor authentication for the user.
     */
    public function destroy(DisableTwoFactorRequest $request): JsonResponse
    {
        app(DisablesTwoFactorAuthentication::class)->disable($request->user());

        return response()->json([
            'data' => null,
            'message' => 'Two-factor authentication has been disabled.',
        ], 200);
    }
}
