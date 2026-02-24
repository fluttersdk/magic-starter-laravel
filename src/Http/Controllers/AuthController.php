<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use FlutterSdk\MagicStarter\Http\Requests\LoginRequest;
use FlutterSdk\MagicStarter\Http\Requests\RegisterRequest;
use FlutterSdk\MagicStarter\Http\Requests\SocialLoginRequest;
use FlutterSdk\MagicStarter\Http\Requests\SwitchTeamRequest;
use FlutterSdk\MagicStarter\Http\Resources\UserResource;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * Handles authentication, registration, social login, and team switching.
 */
class AuthController
{
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
        } catch (\Throwable $exception) {
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
                'locale' => 'en',
                'timezone' => 'UTC',
                'email_verified_at' => now(),
            ]);

            event(new Registered($user));
        }

        return $this->issueTokenResponse($user, $request);
    }

    /**
     * Handle a registration request.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = app(CreatesUsers::class)->create($request->validated());

        event(new Registered($user));

        $token = $this->createAuthToken($user, $request);

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
            ],
            'message' => 'Registration successful',
        ], 201);
    }

    /**
     * Handle a login request.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $userModel = MagicStarter::userModel();
        $user = $userModel::query()
            ->where('email', $request->validated('email'))
            ->first();

        if (! $user || ! Hash::check((string) $request->validated('password'), (string) $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        $token = $this->createAuthToken($user, $request, true);

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
            ],
            'message' => 'Login successful',
        ]);
    }

    /**
     * Handle a logout request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully',
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
        $request->user()->update([
            'current_team_id' => $request->validated('team_id'),
        ]);

        return response()->json([
            'data' => new UserResource($request->user()->fresh()),
            'message' => 'Team switched successfully',
        ]);
    }

    /**
     * Issue a token response for the given user.
     */
    protected function issueTokenResponse(mixed $user, Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $this->createAuthToken($user, $request),
            ],
            'message' => 'Login successful',
        ]);
    }

    /**
     * Create an authentication token for the given user.
     */
    protected function createAuthToken(mixed $user, Request $request, bool $storeDeviceInfo = false): string
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
