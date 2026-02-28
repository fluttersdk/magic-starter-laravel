<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Requests\ForgotPasswordRequest;
use FlutterSdk\MagicStarter\Http\Requests\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Handles password reset link delivery and password reset.
 */
class PasswordResetController
{
    /**
     * Send a password reset link to the given user.
     *
     * Always returns 200 OK regardless of whether the email exists,
     * to prevent user enumeration attacks.
     */
    public function sendResetLinkEmail(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->validated());

        // Always return 200 with a generic message to prevent email enumeration.
        return response()->json([
            'data' => null,
            'message' => __('If an account with that email exists, a password reset link has been sent.'),
        ]);
    }

    /**
     * Reset the user's password.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->validated(),
            function (mixed $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            },
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['data' => null, 'message' => __($status)])
            : response()->json(['data' => null, 'message' => __($status)], 422);
    }
}
