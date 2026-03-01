<?php

namespace FlutterSdk\MagicStarter\Http\Controllers\Concerns;

use FlutterSdk\MagicStarter\Http\Resources\UserResource;
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
     * @param  string  $message  Response message.
     * @param  int  $status  HTTP status code.
     * @return JsonResponse The JSON response containing user data and token.
     */
    protected function authenticatedResponse(
        mixed $user,
        Request $request,
        string $token,
        string $message = 'Login successful',
        int $status = 200,
    ): JsonResponse {
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
