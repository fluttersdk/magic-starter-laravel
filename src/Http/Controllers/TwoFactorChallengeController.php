<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Controllers\Concerns\AuthenticatesUsers;
use FlutterSdk\MagicStarter\Http\Requests\TwoFactorChallengeRequest;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\TwoFactorAuthenticationProvider;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class TwoFactorChallengeController
{
    use AuthenticatesUsers;

    /**
     * Process the two factor challenge.
     */
    public function store(TwoFactorChallengeRequest $request): JsonResponse
    {
        // 1. Decrypt token and handle tampered token payload.
        try {
            $raw = decrypt((string) $request->input('two_factor_token', ''));
        } catch (DecryptException) {
            throw ValidationException::withMessages([
                'two_factor_token' => ['Invalid two-factor authentication token.'],
            ]);
        }

        $payload = (array) json_decode($raw, true);

        // 2. Check token expiration.
        if (now()->timestamp > (int) ($payload['expires_at'] ?? 0)) {
            throw ValidationException::withMessages([
                'two_factor_token' => ['Two-factor authentication token has expired.'],
            ]);
        }

        // 3. Find the user.
        $userModel = MagicStarter::userModel();
        $user = $userModel::find($payload['user_id'] ?? null);

        if ($user === null) {
            throw ValidationException::withMessages([
                'two_factor_token' => ['Invalid two-factor authentication token.'],
            ]);
        }

        // 4. Verify code (recovery or TOTP).
        if ($request->has('recovery_code')) {
            $this->verifyRecoveryCode($request, $user);
        } else {
            $this->verifyTotpCode($request, $user);
        }

        // 5. Generate authenticated response via AuthController parent method.
        $token = $this->createAuthToken($user, $request, storeDeviceInfo: true);

        return $this->authenticatedResponse($user, $request, $token);
    }

    /**
     * Verify the recovery code.
     */
    protected function verifyRecoveryCode(TwoFactorChallengeRequest $request, mixed $user): void
    {
        $code = (string) $request->input('recovery_code');

        if (! in_array($code, $user->recoveryCodes(), true)) {
            throw ValidationException::withMessages([
                'recovery_code' => ['The provided two-factor authentication recovery code was invalid.'],
            ]);
        }

        $user->replaceRecoveryCode($code);
    }

    /**
     * Verify the TOTP code.
     */
    protected function verifyTotpCode(TwoFactorChallengeRequest $request, mixed $user): void
    {
        $code = (string) $request->input('code');

        $isValid = app(TwoFactorAuthenticationProvider::class)->verify(
            $user->twoFactorSecret() ?? '',
            $code,
        );

        if (! $isValid) {
            throw ValidationException::withMessages([
                'code' => ['The provided two-factor authentication code was invalid.'],
            ]);
        }
    }
}
