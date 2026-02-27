<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\Concerns\AuthenticatesUsers;
use FlutterSdk\MagicStarter\Http\Requests\PhoneLoginRequest;
use FlutterSdk\MagicStarter\Http\Requests\PhoneRegisterRequest;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/**
 * Handles phone-based user registration and authentication.
 *
 * Phone is the sole identifier — no email required. The controller
 * delegates user creation to the `CreatesUsers` contract so that
 * application-level logic remains in the published action class.
 */
class PhoneAuthController
{
    use AuthenticatesUsers;

    /**
     * Handle a phone-based registration request.
     *
     * @param  PhoneRegisterRequest  $request  The validated registration payload.
     * @return JsonResponse 201 with user and token on success.
     */
    public function register(PhoneRegisterRequest $request): JsonResponse
    {
        $user = app(CreatesUsers::class)->create($request->validated());

        event(new Registered($user));

        return $this->authenticatedResponse(
            $user,
            $request,
            $this->createAuthToken($user, $request),
            'Registration successful',
            201,
        );
    }

    /**
     * Handle a phone-based login request.
     *
     * Looks up the user by phone number and verifies the password.
     * Returns a 2FA challenge token when the user has two-factor
     * authentication enabled. Otherwise returns the full auth response.
     *
     * @param  PhoneLoginRequest  $request  The validated login payload.
     * @return JsonResponse 200 with user and token, or 401 on invalid credentials.
     */
    public function login(PhoneLoginRequest $request): JsonResponse
    {
        // 1. Resolve the user by phone number.
        $userModel = MagicStarter::userModel();
        $user = $userModel::query()
            ->where('phone', $request->validated('phone'))
            ->first();

        // 2. Reject early when credentials are invalid.
        if (! $user || ! Hash::check((string) $request->validated('password'), (string) $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        // 3. Issue a 2FA challenge when the user has it enabled.
        /** @var \Illuminate\Database\Eloquent\Model $user */
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

        // 4. Issue a full auth token and return the authenticated response.
        return $this->authenticatedResponse(
            $user,
            $request,
            $this->createAuthToken($user, $request, true),
            'Login successful',
        );
    }
}
