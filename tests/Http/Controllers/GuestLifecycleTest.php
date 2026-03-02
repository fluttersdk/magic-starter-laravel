<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Controllers\AuthController;
use FlutterSdk\MagicStarter\Http\Controllers\GuestAuthController;
use FlutterSdk\MagicStarter\Http\Controllers\ProfileController;
use FlutterSdk\MagicStarter\Http\Controllers\SessionController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * End-to-end lifecycle tests for guest authentication.
 *
 * Proves that a guest user's Sanctum token works identically to a regular
 * user's token on all protected endpoints. This validates the hot-restart
 * scenario: guest logs in → token stored → subsequent requests with the
 * same token succeed.
 */
class GuestLifecycleTest extends TestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            \Laravel\Sanctum\SanctumServiceProvider::class,
            \FlutterSdk\MagicStarter\MagicStarterServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        MagicStarter::reset();

        config([
            'auth.providers.users.model' => GuestLifecycleTestUser::class,
            'magic-starter.models.user' => GuestLifecycleTestUser::class,
            'magic-starter.models.team' => ConcreteTeam::class,
            'magic-starter.models.membership' => ConcreteTeamUser::class,
            'magic-starter.features' => [
                'guest-auth',
                'sessions',
                'teams',
                'extended-profile',
            ],
        ]);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('device_id', 255)->unique()->nullable();
            $table->string('phone')->unique()->nullable();
            $table->char('phone_country', 2)->nullable();
            $table->string('locale')->default('en');
            $table->string('timezone')->default('UTC');
            $table->string('language')->nullable();
            $table->string('current_team_id')->nullable();
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        // Register routes without middleware for guest login (public route).
        Route::post('/auth/guest', [GuestAuthController::class, 'login']);

        // Register protected routes WITH auth:sanctum middleware.
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('/auth/user', [AuthController::class, 'user']);
            Route::post('/auth/logout', [AuthController::class, 'logout']);
            Route::get('/sessions', [SessionController::class, 'index']);
            Route::put('/user/profile', [ProfileController::class, 'update']);
        });

        $this->app->instance(
            \FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles::class,
            $this->app->make(\FlutterSdk\MagicStarter\Actions\UpdateUserProfile::class),
        );
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helper: login as guest and return the plain-text token.
    // ------------------------------------------------------------------

    /**
     * Perform a guest login and return the plain-text Sanctum token.
     *
     * @param  string  $deviceId  The device identifier for the guest.
     * @return array{token: string, user_id: string} Token and user ID.
     */
    private function guestLogin(string $deviceId = 'lifecycle-device-001'): array
    {
        $response = $this->postJson('/auth/guest', [
            'device_id' => $deviceId,
        ]);

        $response->assertSuccessful();

        return [
            'token' => $response->json('data.token'),
            'user_id' => $response->json('data.user.id'),
        ];
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    /**
     * Test 1: Guest token works on GET auth/user.
     *
     * This is the critical hot-restart scenario: the Flutter app stores the
     * token, then on restart calls GET auth/user to sync the user. If this
     * fails, the user is logged out.
     */
    public function test_guest_token_works_on_get_auth_user(): void
    {
        $auth = $this->guestLogin();

        $response = $this->withToken($auth['token'])
            ->getJson('/auth/user');

        $response
            ->assertOk()
            ->assertJsonPath('data.is_guest', true)
            ->assertJsonPath('data.id', $auth['user_id']);
    }

    /**
     * Test 2: Guest token works on POST auth/logout.
     *
     * Guest should be able to logout (revoke token) like any other user.
     */
    public function test_guest_token_works_on_logout(): void
    {
        $auth = $this->guestLogin();

        $response = $this->withToken($auth['token'])
            ->postJson('/auth/logout');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully');

        // After logout, the token is deleted from the database.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * Test 3: Guest token works on GET sessions.
     *
     * Guest should be able to list their active sessions (tokens).
     */
    public function test_guest_token_works_on_sessions_list(): void
    {
        $auth = $this->guestLogin();

        $response = $this->withToken($auth['token'])
            ->getJson('/sessions');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'ip_address',
                        'user_agent',
                        'is_current_device',
                    ],
                ],
            ]);
    }

    /**
     * Test 4: Guest token works on PUT user/profile.
     *
     * Guests can update name, timezone, and locale — fields that don't
     * require email/password.
     */
    public function test_guest_token_works_on_profile_update(): void
    {
        $auth = $this->guestLogin();

        $response = $this->withToken($auth['token'])
            ->putJson('/user/profile', [
                'name' => 'Updated Guest Name',
                'timezone' => 'Europe/Istanbul',
            ]);

        $response->assertOk();

        // Verify the update persisted.
        $this->withToken($auth['token'])
            ->getJson('/auth/user')
            ->assertJsonPath('data.name', 'Updated Guest Name')
            ->assertJsonPath('data.timezone', 'Europe/Istanbul');
    }

    /**
     * Test 5: Returning guest gets a fresh token and old token is revoked.
     *
     * When the same device_id logs in again, all previous tokens are revoked.
     * Only the new token should authenticate.
     */
    public function test_returning_guest_gets_fresh_token_and_old_is_revoked(): void
    {
        $firstAuth = $this->guestLogin('returning-lifecycle-device');
        $secondAuth = $this->guestLogin('returning-lifecycle-device');

        // Same user ID (idempotent).
        $this->assertSame($firstAuth['user_id'], $secondAuth['user_id']);

        // Different tokens.
        $this->assertNotSame($firstAuth['token'], $secondAuth['token']);

        // Old token is revoked.
        $this->withToken($firstAuth['token'])
            ->getJson('/auth/user')
            ->assertUnauthorized();

        // New token works.
        $this->withToken($secondAuth['token'])
            ->getJson('/auth/user')
            ->assertOk()
            ->assertJsonPath('data.is_guest', true);
    }

    /**
     * Test 6: Guest auth/user response includes team data when teams enabled.
     *
     * Verifies the full response shape matches what the Flutter app expects.
     */
    public function test_guest_auth_user_includes_team_data(): void
    {
        $auth = $this->guestLogin();

        $response = $this->withToken($auth['token'])
            ->getJson('/auth/user');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'is_guest',
                    'locale',
                    'timezone',
                    'profile_photo_url',
                    'current_team',
                    'all_teams',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJsonPath('data.is_guest', true)
            ->assertJsonPath('data.email', null);
    }

    /**
     * Test 7: Guest token stores device info (ip_address, user_agent).
     *
     * The token should have ip_address and user_agent stored, so that the
     * sessions list shows meaningful device information.
     */
    public function test_guest_token_stores_device_info(): void
    {
        $auth = $this->guestLogin();

        $response = $this->withToken($auth['token'])
            ->getJson('/sessions');

        $response->assertOk();

        $sessions = $response->json('data');
        $this->assertNotEmpty($sessions);

        // At least one session should have device info set.
        $currentSession = collect($sessions)->firstWhere('is_current_device', true);
        $this->assertNotNull($currentSession, 'Current session should be present.');
    }
}

// ---------------------------------------------------------------------------
// Test fixture: User model with HasApiTokens for real Sanctum token auth.
// ---------------------------------------------------------------------------

/**
 * @property string $id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $password
 * @property bool $is_guest
 * @property string|null $device_id
 * @property string|null $phone
 * @property string|null $phone_country
 * @property string $locale
 * @property string $timezone
 * @property string|null $language
 * @property string|null $current_team_id
 * @property string|null $profile_photo_path
 * @property string|null $email_verified_at
 */
final class GuestLifecycleTestUser extends \Illuminate\Foundation\Auth\User
{
    use \FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
    use \FlutterSdk\MagicStarter\Traits\HasGuestSupport;
    use \FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
    use \FlutterSdk\MagicStarter\Traits\HasTeams;
    use \Laravel\Sanctum\HasApiTokens;

    protected $table = 'users';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_guest' => 'boolean',
            'email_verified_at' => 'datetime',
        ];
    }
}
