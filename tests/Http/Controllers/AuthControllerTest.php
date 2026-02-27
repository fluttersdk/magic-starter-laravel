<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use FlutterSdk\MagicStarter\Http\Controllers\AuthController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;

class AuthControllerTest extends TestCase
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
            'auth.providers.users.model' => AuthControllerTestUser::class,
            'magic-starter.models.user' => AuthControllerTestUser::class,
            'magic-starter.models.team' => AuthControllerTestTeam::class,
            'magic-starter.models.membership' => \FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser::class,
            'auth.guards.web' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
        ]);

        config([
            'magic-starter.supported_locales' => [
                'en',
                'tr',
                'de',
            ],
            'magic-starter.supported_timezones' => [
                'UTC',
                'Europe/Istanbul',
                'Europe/London',
                'America/New_York',
            ],
            'magic-starter.features' => [
                'extended-profile',
            ],
        ]);

        if (! enum_exists('App\\Enums\\TeamRole')) {
            eval('namespace App\\Enums; enum TeamRole: string { case Owner = "owner"; }');
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('locale')->default('en');
            $table->string('timezone')->default('UTC');
            $table->string('language')->nullable();
            $table->foreignUuid('current_team_id')->nullable();
            $table->string('profile_photo_path', 2048)->nullable();
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

        $this->app->bind(CreatesUsers::class, AuthControllerCreatesUsersAction::class);

        Gate::define('switchTo', static fn (): bool => true);

        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/social-login/{provider}', [AuthController::class, 'socialLogin']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/switch-team', [AuthController::class, 'switchTeam']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        MagicStarter::reset();
        parent::tearDown();
    }

    public function test_register_returns_created_user_and_token(): void
    {
        $response = $this->postJson('/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Registration successful')
            ->assertJsonPath('data.user.email', 'john@example.com')
            ->assertJsonStructure([
                'data' => ['user', 'token'],
                'message',
            ]);
    }

    public function test_login_returns_user_and_token_for_valid_credentials(): void
    {
        AuthControllerTestUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => Hash::make('Password123'),
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

        $response = $this->postJson('/login', [
            'email' => 'jane@example.com',
            'password' => 'Password123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Login successful')
            ->assertJsonPath('data.user.email', 'jane@example.com')
            ->assertJsonStructure([
                'data' => ['user', 'token'],
                'message',
            ]);
    }

    public function test_login_response_includes_correct_user_role_for_team_owner(): void
    {
        // 1. Create user with an owned team + pivot membership.
        $userId = (string) Str::uuid();
        $teamId = (string) Str::uuid();

        $user = AuthControllerTestUser::query()->create([
            'id' => $userId,
            'name' => 'Owner User',
            'email' => 'owner@example.com',
            'password' => Hash::make('Password123'),
            'locale' => 'en',
            'timezone' => 'UTC',
            'current_team_id' => $teamId,
        ]);

        AuthControllerTestTeam::query()->create([
            'id' => $teamId,
            'user_id' => $userId,
            'name' => 'Owner Team',
            'personal_team' => true,
        ]);

        $user->teams()->attach($teamId, [
            'id' => (string) Str::uuid(),
            'role' => 'admin',
        ]);

        // 2. Login and verify user_role is resolved correctly.
        $response = $this->postJson('/login', [
            'email' => 'owner@example.com',
            'password' => 'Password123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.user.current_team.user_role', 'owner')
            ->assertJsonPath('data.user.all_teams.0.user_role', 'owner');
    }

    public function test_login_response_includes_correct_user_role_for_team_member(): void
    {
        // 1. Create an owner and a member user.
        $ownerId = (string) Str::uuid();
        $memberId = (string) Str::uuid();
        $teamId = (string) Str::uuid();

        AuthControllerTestUser::query()->create([
            'id' => $ownerId,
            'name' => 'Team Owner',
            'email' => 'team-owner@example.com',
            'password' => Hash::make('Password123'),
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

        AuthControllerTestTeam::query()->create([
            'id' => $teamId,
            'user_id' => $ownerId,
            'name' => 'Shared Team',
            'personal_team' => false,
        ]);

        $member = AuthControllerTestUser::query()->create([
            'id' => $memberId,
            'name' => 'Member User',
            'email' => 'member@example.com',
            'password' => Hash::make('Password123'),
            'locale' => 'en',
            'timezone' => 'UTC',
            'current_team_id' => $teamId,
        ]);

        $member->teams()->attach($teamId, [
            'id' => (string) Str::uuid(),
            'role' => 'member',
        ]);

        // 2. Login as member and verify role.
        $response = $this->postJson('/login', [
            'email' => 'member@example.com',
            'password' => 'Password123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.user.current_team.user_role', 'member');
    }

    public function test_social_login_creates_user_and_returns_token(): void
    {
        $socialUser = new SocialiteUser;
        $socialUser->map([
            'id' => 'social-1',
            'name' => 'Social Name',
            'email' => 'social@example.com',
        ]);

        $driver = Mockery::mock();
        $driver->shouldReceive('userFromToken')->once()->with('token-1')->andReturn($socialUser);

        $socialiteFactory = new class($driver) implements SocialiteFactory
        {
            public function __construct(private readonly mixed $driver) {}

            public function driver($driver = null): mixed
            {
                return $this->driver;
            }
        };

        $this->app->instance(SocialiteFactory::class, $socialiteFactory);

        $response = $this->postJson('/social-login/google', [
            'access_token' => 'token-1',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Login successful')
            ->assertJsonPath('data.user.email', 'social@example.com')
            ->assertJsonStructure([
                'data' => ['user', 'token'],
                'message',
            ]);
    }

    public function test_logout_deletes_current_access_token(): void
    {
        $user = AuthControllerTestUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Logout User',
            'email' => 'logout@example.com',
            'password' => Hash::make('Password123'),
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

        $user->currentAccessTokenStub = new AuthControllerCurrentAccessToken;

        $response = $this->actingAs($user)->postJson('/logout');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully');

        $this->assertTrue($user->currentAccessTokenStub->deleted);
    }

    public function test_user_returns_authenticated_user_resource_shape(): void
    {
        $user = AuthControllerTestUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Current User',
            'email' => 'current@example.com',
            'password' => Hash::make('Password123'),
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

        $response = $this->actingAs($user)->getJson('/user');

        $response
            ->assertOk()
            ->assertJsonPath('data.email', 'current@example.com')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'locale',
                    'timezone',
                    'profile_photo_url',
                    'all_teams',
                ],
            ]);
    }

    public function test_switch_team_updates_current_team_and_returns_user_resource(): void
    {
        $user = AuthControllerTestUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Switch User',
            'email' => 'switch@example.com',
            'password' => Hash::make('Password123'),
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

        $team = AuthControllerTestTeam::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'name' => 'Main Team',
            'personal_team' => false,
        ]);

        $response = $this->actingAs($user)->postJson('/switch-team', [
            'team_id' => $team->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Team switched successfully')
            ->assertJsonPath('data.current_team.id', $team->id);

        $this->assertSame($team->id, $user->fresh()->current_team_id);
    }

    public function test_switch_team_rejects_non_member(): void
    {
        $user = AuthControllerTestUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Switch User',
            'email' => 'switch2@example.com',
            'password' => Hash::make('Password123'),
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

        $otherUser = AuthControllerTestUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => Hash::make('Password123'),
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

        $team = AuthControllerTestTeam::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $otherUser->id,
            'name' => 'Other Team',
            'personal_team' => false,
        ]);

        $response = $this->actingAs($user)->postJson('/switch-team', [
            'team_id' => $team->id,
        ]);

        $response
            ->assertStatus(403)
            ->assertJsonPath('message', 'You are not a member of this team.');
    }

    public function test_login_returns_401_for_wrong_password(): void
    {
        AuthControllerTestUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => Hash::make('correct-password'),
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);
        $this->postJson('/login', [
            'email' => 'jane@example.com',
            'password' => 'Wrong-Password-123',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_login_returns_401_for_nonexistent_email(): void
    {
        $this->postJson('/login', [
            'email' => 'nobody@example.com',
            'password' => 'any-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_register_auto_detects_locale_from_accept_language_header(): void
    {
        // Bind real CreateUser action to test auto-detection.
        $this->app->bind(CreatesUsers::class, \FlutterSdk\MagicStarter\Actions\CreateUser::class);

        $this->postJson(
            '/register',
            [
                'name' => 'Auto Locale User',
                'email' => 'auto-locale@example.com',
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
            ],
            [
                'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.8',
            ],
        )
            ->assertCreated()
            ->assertJsonPath('data.user.locale', 'tr');
    }

    public function test_register_auto_detects_timezone_from_x_timezone_header(): void
    {
        // Bind real CreateUser action to test auto-detection.
        $this->app->bind(CreatesUsers::class, \FlutterSdk\MagicStarter\Actions\CreateUser::class);

        $this->postJson(
            '/register',
            [
                'name' => 'Auto TZ User',
                'email' => 'auto-tz@example.com',
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
            ],
            [
                'X-Timezone' => 'Europe/Istanbul',
            ],
        )
            ->assertCreated()
            ->assertJsonPath('data.user.timezone', 'Europe/Istanbul');
    }

    public function test_register_explicit_locale_overrides_header_detection(): void
    {
        // Bind real CreateUser action to test auto-detection.
        $this->app->bind(CreatesUsers::class, \FlutterSdk\MagicStarter\Actions\CreateUser::class);

        $this->postJson(
            '/register',
            [
                'name' => 'Override User',
                'email' => 'override@example.com',
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
                'locale' => 'en',
                'timezone' => 'UTC',
            ],
            [
                'Accept-Language' => 'tr-TR',
                'X-Timezone' => 'Europe/Istanbul',
            ],
        )
            ->assertCreated()
            ->assertJsonPath('data.user.locale', 'en')
            ->assertJsonPath('data.user.timezone', 'UTC');
    }
}

final class AuthControllerTestUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use Authorizable;
    use \FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
    use \FlutterSdk\MagicStarter\Traits\HasTeams;
    use HasUuids;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public ?AuthControllerCurrentAccessToken $currentAccessTokenStub = null;

    public function createToken(string $_name): object
    {
        $this->currentAccessTokenStub = new AuthControllerCurrentAccessToken;

        return new AuthControllerTokenResult($this->currentAccessTokenStub);
    }

    public function currentAccessToken(): ?AuthControllerCurrentAccessToken
    {
        return $this->currentAccessTokenStub;
    }
}

final class AuthControllerTestTeam extends \FlutterSdk\MagicStarter\Models\Team
{
    protected $table = 'teams';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [];

    protected $guarded = [];
}

final class AuthControllerTokenResult
{
    public string $plainTextToken;

    public AuthControllerCurrentAccessToken $accessToken;

    public function __construct(AuthControllerCurrentAccessToken $accessToken)
    {
        $this->plainTextToken = 'test-token-' . Str::random(20);
        $this->accessToken = $accessToken;
    }
}

final class AuthControllerCurrentAccessToken
{
    public bool $deleted = false;

    public array $attributes = [];

    public function forceFill(array $attributes): self
    {
        $this->attributes = array_merge($this->attributes, $attributes);

        return $this;
    }

    public function save(): bool
    {
        return true;
    }

    public function delete(): bool
    {
        $this->deleted = true;

        return true;
    }
}

final class AuthControllerCreatesUsersAction implements CreatesUsers
{
    public function create(array $input): \Illuminate\Contracts\Auth\Authenticatable
    {
        $model = MagicStarter::userModel();

        return $model::query()->create([
            'id' => (string) Str::uuid(),
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make((string) $input['password']),
            'locale' => $input['locale'] ?? 'en',
            'timezone' => $input['timezone'] ?? 'UTC',
            'email_verified_at' => $input['email_verified_at'] ?? null,
        ]);
    }
}
