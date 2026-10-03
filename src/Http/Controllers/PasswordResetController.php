<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Requests\ForgotPasswordRequest;
use FlutterSdk\MagicStarter\Http\Requests\ResetPasswordRequest;
use FlutterSdk\MagicStarter\Social\UnconfirmedLinks;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
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
            'message' => __('magic-starter::auth.password.reset_link_sent'),
        ]);
    }

    /**
     * Reset the user's password.
     *
     * A completed reset proves control of the mailbox, so it also severs the
     * provider links that mailbox's owner never confirmed.
     */
    public function reset(ResetPasswordRequest $request, UnconfirmedLinks $unconfirmedLinks): JsonResponse
    {
        $status = Password::reset(
            $request->validated(),
            function (mixed $user, string $password) use ($unconfirmedLinks): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));

                if ($user instanceof Authenticatable) {
                    $unconfirmedLinks->sever($user);
                }
            },
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['data' => null, 'message' => __($status)])
            : response()->json(['data' => null, 'message' => __($status)], 422);
    }
}
