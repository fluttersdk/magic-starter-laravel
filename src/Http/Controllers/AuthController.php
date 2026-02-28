<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\Concerns\AuthenticatesUsers;
use FlutterSdk\MagicStarter\Http\Requests\LoginRequest;
use FlutterSdk\MagicStarter\Http\Requests\RegisterRequest;
use FlutterSdk\MagicStarter\Http\Requests\SocialLoginRequest;
use FlutterSdk\MagicStarter\Http\Requests\SwitchTeamRequest;
use FlutterSdk\MagicStarter\Http\Resources\UserResource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\RequestLocaleDetector;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Handles authentication, registration, social login, and team switching.
 */
class AuthController
{
    use AuthenticatesUsers;

    /**
     * Handle a social login request.
     */
    public function socialLogin(SocialLoginRequest $request, string $provider): JsonResponse
    {
        try {
            $driver = Socialite::driver($provider);

            if ($request->has('authorization_code')) {
                $code = $request->input('authorization_code');
                $originalCode = $request->input('code');
                $request->merge(['code' => $code]);
                $socialUser = $driver->user();

                if ($originalCode === null) {
                    $request->request->remove('code');
                } else {
                    $request->merge(['code' => $originalCode]);
                }
            } else {
                $accessToken = (string) $request->input('access_token');
                $socialUser = $driver->userFromToken($accessToken);
            }
        } catch (Throwable $exception) {
            report($exception);

            $payload = ['message' => 'Invalid token or provider'];

            if (config('app.debug')) {
                $payload['error'] = $exception->getMessage();
            }

            return response()->json($payload, 401);
        }

        $userModel = MagicStarter::userModel();
        $user = $userModel::query()->where('email', $socialUser->getEmail())->first();

        if (! $user) {
            $password = Str::random(32);
            $user = app(CreatesUsers::class)->create([
                'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
                'email' => $socialUser->getEmail(),
                'password' => $password,
                'password_confirmation' => $password,
                'locale' => RequestLocaleDetector::detectLocale($request)
                    ?? config('magic-starter.defaults.locale', 'en'),
                'timezone' => RequestLocaleDetector::detectTimezone($request)
                    ?? config('magic-starter.defaults.timezone', 'UTC'),
                'email_verified_at' => now(),
            ]);

            event(new Registered($user));
        }

        return $this->authenticatedResponse($user, $request, $this->createAuthToken($user, $request));
    }

    /**
     * Handle a registration request.
     */
    public function register(RegisterRequest $request): JsonResponse
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
     * Handle a login request.
     *
     * Resolves the user by whichever identifier (email or phone) is
     * provided in the request. When 2FA is enabled for the user,
     * returns a challenge token instead of the auth response.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $userModel = MagicStarter::userModel();

        // 1. Resolve user by the provided identifier (email or phone).
        $user = null;

        if ($request->filled('email')) {
            $user = $userModel::query()
                ->where('email', $request->validated('email'))
                ->first();
        } elseif ($request->filled('phone')) {
            $user = $userModel::query()
                ->where('phone', $request->validated('phone'))
                ->first();
        }

        // 2. Verify password.
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
        $token = $this->createAuthToken($user, $request, true);

        return $this->authenticatedResponse($user, $request, $token, 'Login successful');
    }

    /**
     * Handle a logout request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'data' => null, 'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get the authenticated user.
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()),
        ]);
    }

    /**
     * Switch the user's current team.
     */
    public function switchTeam(SwitchTeamRequest $request): JsonResponse
    {
        $teamId = $request->validated('team_id');
        $user = $request->user();

        if (! $user->allTeams()->contains('id', $teamId)) {
            return response()->json([
                'message' => 'You are not a member of this team.',
            ], 403);
        }

        $user->update([
            'current_team_id' => $teamId,
        ]);

        return response()->json([
            'data' => new UserResource($request->user()->fresh()),
            'message' => 'Team switched successfully',
        ]);
    }
}
