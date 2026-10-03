<?php

namespace FlutterSdk\MagicStarter\Http\Controllers\Concerns;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Resources\UserResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Provides shared authentication helpers for controllers.
 *
 * Extracted from AuthController so that GuestAuthController
 * and any future auth controllers can reuse the same
 * token generation and response logic.
 */
trait AuthenticatesUsers
{
    /**
     * Build an authenticated JSON response with user and token.
     *
     * Sets the user resolver on the request so that downstream resources
     * (e.g. TeamResource) can access the authenticated user via
     * `$request->user()` — even before Sanctum middleware runs.
     *
     * @param  mixed  $user  The authenticated user model.
     * @param  Request  $request  The current HTTP request.
     * @param  string  $token  The plain-text Sanctum token.
     * @param  string|null  $message  Response message, or null for the sign-in default.
     * @param  int  $status  HTTP status code.
     * @return JsonResponse The JSON response containing user data and token.
     */
    protected function authenticatedResponse(
        mixed $user,
        Request $request,
        string $token,
        ?string $message = null,
        int $status = 200,
    ): JsonResponse {
        // The default is resolved here rather than in the signature because a
        // parameter default must be a constant expression, and the sentence is
        // a translation line now. Null means "whatever a plain sign-in says",
        // which is what social login, the OTP verify and the 2FA challenge all
        // want; every other caller names its own.
        $message ??= (string) __('magic-starter::auth.login_successful');

        // Make $request->user() available for nested resources.
        // Resource serialization resolves request from the container,
        // which may differ from the controller-injected $request instance.
        $resolver = fn () => $user;
        $request->setUserResolver($resolver);
        app('request')->setUserResolver($resolver);

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
            ],
            'message' => $message,
        ], $status);
    }

    /**
     * Finish a sign-in: a 2FA challenge when the user confirmed 2FA, else a token.
     *
     * The one decision every sign-in path shares (password login, the social
     * exchange), so a confirmed second factor cannot be skipped by choosing
     * another way in. The challenge token is encrypted and expires after
     * `magic-starter.two_factor.challenge_token_ttl` minutes; it is redeemed at
     * `POST auth/two-factor-challenge`.
     *
     * @param  Model  $user  The user who proved the first factor.
     * @param  string|null  $message  Response message, or null for the sign-in default.
     */
    protected function signInResponse(Model $user, Request $request, ?string $message = null): JsonResponse
    {
        if (
            Features::hasTwoFactorAuthenticationFeatures() &&
            method_exists($user, 'hasEnabledTwoFactorAuthentication') &&
            $user->hasEnabledTwoFactorAuthentication()
        ) {
            $challengeToken = encrypt(json_encode([
                'user_id' => $user->getKey(),
                'expires_at' => now()->addMinutes(
                    (int) config('magic-starter.two_factor.challenge_token_ttl', 5),
                )->timestamp,
            ]));

            return response()->json([
                'two_factor' => true,
                'two_factor_token' => $challengeToken,
            ]);
        }

        return $this->authenticatedResponse(
            $user,
            $request,
            $this->createAuthToken($user, $request, true),
            $message,
        );
    }

    /**
     * Refuse a social flow step with its translated sentence and stable code.
     *
     * @param  string  $code  a key of `lang/<locale>/social.php`, such as `flow_expired`
     */
    protected function socialRefusal(string $code, int $status): JsonResponse
    {
        return response()->json([
            'message' => __('magic-starter::social.' . $code),
            'code' => $code,
        ], $status);
    }

    /**
     * Create an authentication token for the given user.
     *
     * @param  mixed  $user  The user model instance.
     * @param  Request  $request  The current HTTP request (used for device info).
     * @param  bool  $storeDeviceInfo  Whether to persist ip/user_agent on the token.
     * @return string The plain-text token string.
     */
    protected function createAuthToken(mixed $user, Request $request, bool $storeDeviceInfo = true): string
    {
        if (! method_exists($user, 'createToken')) {
            return Str::random(80);
        }

        $tokenResult = $user->createToken('auth_token');
        $plainTextToken = $tokenResult->plainTextToken ?? Str::random(80);

        $accessToken = $tokenResult->accessToken ?? null;

        if ($storeDeviceInfo && $accessToken && method_exists($accessToken, 'forceFill')) {
            $accessToken->forceFill([
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            if (method_exists($accessToken, 'save')) {
                $accessToken->save();
            }
        }

        return (string) $plainTextToken;
    }
}
