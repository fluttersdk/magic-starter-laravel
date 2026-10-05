<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use FlutterSdk\MagicStarter\Http\Controllers\Concerns\AuthenticatesUsers;
use FlutterSdk\MagicStarter\Http\Requests\LoginRequest;
use FlutterSdk\MagicStarter\Http\Requests\RegisterRequest;
use FlutterSdk\MagicStarter\Http\Requests\SwitchTeamRequest;
use FlutterSdk\MagicStarter\Http\Resources\UserResource;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Handles password authentication, registration, and team switching.
 */
class AuthController
{
    use AuthenticatesUsers;

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
            $this->createAuthToken($user, $request, storeDeviceInfo: true),
            (string) __('magic-starter::auth.registration_successful'),
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
                'message' => __('magic-starter::auth.invalid_credentials'),
            ], 401);
        }

        // 3. Challenge a confirmed second factor, else issue a full auth token.
        return $this->signInResponse(
            $user,
            $request,
            (string) __('magic-starter::auth.login_successful'),
        );
    }

    /**
     * Handle a logout request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'data' => null, 'message' => __('magic-starter::auth.logged_out'),
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
                'message' => __('magic-starter::teams.not_a_member'),
            ], 403);
        }

        // current_team_id is a system-managed field deliberately kept out of
        // the User model's $fillable; forceFill persists it regardless of the
        // consumer model's mass-assignment guard (mirrors Jetstream switchTeam).
        $user->forceFill([
            'current_team_id' => $teamId,
        ])->save();

        return response()->json([
            'data' => new UserResource($request->user()->fresh()),
            'message' => __('magic-starter::teams.switched'),
        ]);
    }
}
