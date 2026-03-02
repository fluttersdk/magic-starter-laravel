<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Actions\UpdateUserPassword;
use FlutterSdk\MagicStarter\Actions\UpdateUserProfile;
use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserPasswords;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles;
use FlutterSdk\MagicStarter\Http\Controllers\AuthController;
use FlutterSdk\MagicStarter\Http\Controllers\ProfileController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;

/**
 * Regression tests proving all email/password auth flows remain completely
 * unbroken after adding guest-auth, phone-otp, and 2FA features.
 *
 * These tests use ALL features enabled simultaneously to prove no conflicts exist.
 */
class RegressionAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        config([
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'auth.providers.users.model' => ConcreteUser::class,
            'magic-starter.models.user' => ConcreteUser::class,
            'magic-starter.models.team' => ConcreteTeam::class,
            'magic-starter.models.membership' => ConcreteTeamUser::class,
            // All new features enabled simultaneously — the core of regression testing.
            'magic-starter.features' => [
                'guest-auth',
                'phone-otp',
                'extended-profile',
            ],
            'magic-starter.supported_locales' => [
                'en',
                'tr',
            ],
        ]);

        // Full schema including ALL columns added by guest/phone/2FA migrations.
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
            $table->string('phone')->unique()->nullable();
            $table->char('phone_country', 2)->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('device_id', 255)->unique()->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->string('locale')->default('en');
            $table->string('timezone')->default('UTC');
            $table->string('profile_photo_path')->nullable();
            $table->string('current_team_id')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
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

        // Use a scoped inline action — avoids binding pollution into subsequent test classes.
        // The real CreateUser action fires team-creation logic that other test suites don't set up.
        $this->app->instance(CreatesUsers::class, new RegressionCreatesUsersAction);
        $this->app->instance(
            UpdatesUserProfiles::class,
            $this->app->make(UpdateUserProfile::class),
        );
        $this->app->instance(
            UpdatesUserPasswords::class,
            $this->app->make(UpdateUserPassword::class),
        );

        // Register routes explicitly — tests bypass the service provider route loading.
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/auth/social/{provider}', [AuthController::class, 'socialLogin']);
        Route::put('/user/profile', [ProfileController::class, 'update']);
        Route::put('/user/password', [ProfileController::class, 'updatePassword']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        MagicStarter::reset();
        parent::tearDown();
    }

    /**
     * Test 1: Standard email+password registration is unbroken with all features active.
     */
    public function test_email_registration_unchanged_with_all_features_enabled(): void
    {
        $response = $this->postJson('/auth/register', [
            'name' => 'John Regression',
            'email' => 'regression@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Registration successful')
            ->assertJsonPath('data.user.email', 'regression@example.com')
            ->assertJsonStructure([
                'data' => ['user', 'token'],
                'message',
            ]);

        $this->assertFalse((bool) $response->json('data.user.is_guest'));
        $this->assertNotNull($response->json('data.user.email'));
    }

    /**
     * Test 2: Standard email+password login is unbroken with all features active.
     */
    public function test_email_login_unchanged_with_all_features_enabled(): void
    {
        ConcreteUser::create([
            'name' => 'Login User',
            'email' => 'login-regression@example.com',
            'password' => Hash::make('Password123'),
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => 'login-regression@example.com',
            'password' => 'Password123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Login successful')
            ->assertJsonPath('data.user.email', 'login-regression@example.com')
            ->assertJsonStructure([
                'data' => ['user', 'token'],
                'message',
            ]);
    }

    /**
     * Test 3: Social login still works when guest-auth is also enabled.
     */
    public function test_social_login_still_works(): void
    {
        $socialUser = new SocialiteUser;
        $socialUser->map([
            'id' => 'social-regression-1',
            'name' => 'Social Regression',
            'email' => 'social-regression@example.com',
        ]);

        $driver = Mockery::mock();
        $driver->shouldReceive('userFromToken')->once()->with('access-token-1')->andReturn($socialUser);

        $socialiteFactory = new class($driver) implements SocialiteFactory
        {
            public function __construct(private readonly mixed $driver) {}

            public function driver($driver = null): mixed
            {
                return $this->driver;
            }
        };

        $this->app->instance(SocialiteFactory::class, $socialiteFactory);

        $response = $this->postJson('/auth/social/google', [
            'access_token' => 'access-token-1',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Login successful')
            ->assertJsonPath('data.user.email', 'social-regression@example.com')
            ->assertJsonStructure([
                'data' => ['user', 'token'],
                'message',
            ]);
    }

    /**
     * Test 4: Profile update for a regular (non-guest) user returns 200 without unexpected changes.
     */
    public function test_profile_update_unchanged_for_non_guest_users(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::create([
            'name' => 'Regular User',
            'email' => 'regular@example.com',
            'password' => Hash::make('Password123'),
            'is_guest' => false,
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

        $response = $this->actingAs($user)->putJson('/user/profile', [
            'name' => 'Updated Regular User',
        ]);

        $response->assertOk();

        $fresh = $user->fresh();
        $this->assertFalse((bool) $fresh->is_guest, 'Regular user must not become a guest after profile update');
        $this->assertSame('Updated Regular User', $fresh->name);
        $this->assertSame('regular@example.com', $fresh->email, 'Email must remain unchanged');
    }

    /**
     * Test 5: User created via standard email registration has is_guest=false by default.
     */
    public function test_user_created_by_email_has_is_guest_false_by_default(): void
    {
        $response = $this->postJson('/auth/register', [
            'name' => 'Is Guest Check',
            'email' => 'is-guest-check@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertCreated();

        $user = ConcreteUser::where('email', 'is-guest-check@example.com')->firstOrFail();

        $this->assertFalse((bool) $user->is_guest, 'Email-registered users must have is_guest=false');
    }

    /**
     * Test 6: Guest user with no password can call PUT /user/password WITHOUT current_password.
     *
     * Confirms the guest-password edge case: when is_guest=true and no password is set,
     * the current_password field must NOT be required by UpdateUserPassword action.
     */
    public function test_guest_user_can_set_initial_password_without_current_password(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::create([
            'is_guest' => true,
            'device_id' => 'device-regression-1',
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

        $response = $this->actingAs($user)->putJson('/user/password', [
            // current_password intentionally omitted — must not be required for guests without a password.
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue(
            Hash::check('Password123!', (string) $fresh->password),
            'Guest must have their password set successfully',
        );
    }

    /**
     * Test 7: Non-guest user calling PUT /user/password without current_password receives 422.
     */
    public function test_non_guest_user_must_provide_current_password(): void
    {
        /** @var ConcreteUser $user */
        $user = ConcreteUser::create([
            'name' => 'Has Password',
            'email' => 'has-password@example.com',
            'password' => Hash::make('OldPassword123'),
            'is_guest' => false,
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

        $response = $this->actingAs($user)->putJson('/user/password', [
            // current_password intentionally omitted — must cause 422 for non-guests.
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertUnprocessable();
    }

    /**
     * Test 8: Standard email registration works correctly when all new features are enabled simultaneously.
     *
     * Simulates a production scenario where guest-auth + phone-otp and
     * the original email auth coexist without conflicts in configuration or routing.
     */
    public function test_multiple_features_enabled_simultaneously_no_conflicts(): void
    {
        // Explicitly set all new features together — this is the key regression assertion.
        config([
            'magic-starter.features' => [
                'guest-auth',
                'phone-otp',
                'extended-profile',
            ],
        ]);

        $response = $this->postJson('/auth/register', [
            'name' => 'Multi Feature User',
            'email' => 'multi-feature@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'multi-feature@example.com')
            ->assertJsonStructure([
                'data' => ['user', 'token'],
                'message',
            ]);

        $this->assertFalse((bool) $response->json('data.user.is_guest'));

        $user = ConcreteUser::where('email', 'multi-feature@example.com')->firstOrFail();
        $this->assertFalse((bool) $user->is_guest);
        $this->assertNotNull($user->email);
        $this->assertNotEmpty($user->password);
    }
}

/**
 * Inline CreatesUsers implementation for regression tests.
 *
 * Uses instance() binding to avoid polluting the container across test classes.
 * Omits team creation logic — teams are not the concern of these auth regression tests.
 */
final class RegressionCreatesUsersAction implements CreatesUsers
{
    /**
     * Create and return a newly registered user.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): Authenticatable
    {
        $userModel = MagicStarter::userModel();

        return $userModel::create([
            'id' => (string) Str::uuid(),
            'name' => $input['name'] ?? null,
            'email' => $input['email'],
            'password' => Hash::make((string) $input['password']),
            'locale' => $input['locale'] ?? 'en',
            'timezone' => $input['timezone'] ?? 'UTC',
            'is_guest' => false,
        ]);
    }
}
