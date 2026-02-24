<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Tests\Http\Requests;

use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use FlutterSdk\MagicStarter\Http\Controllers\AuthController;
use FlutterSdk\MagicStarter\Http\Controllers\PasswordResetController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class AuthRequestsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        \call_user_func('config', [
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'auth.providers.users.model' => AuthRequestsTestUser::class,
            'magic-starter.models.user' => AuthRequestsTestUser::class,
            'magic-starter.models.team' => AuthRequestsTestTeam::class,
        ]);

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('locale')->default('en');
            $table->string('timezone')->default('UTC');
            $table->string('language')->nullable();
            $table->string('current_team_id')->nullable();
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'team_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tokenable_type');
            $table->uuid('tokenable_id');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        $this->app->bind(CreatesUsers::class, function () {
            return new class implements CreatesUsers
            {
                public function create(array $input): mixed
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
            };
        });

        Gate::define('switchTo', static fn (): bool => true);

        \call_user_func('app', 'router')->post('/auth/register', [AuthController::class, 'register']);
        \call_user_func('app', 'router')->post('/auth/login', [AuthController::class, 'login']);
        \call_user_func('app', 'router')->post('/auth/social/{provider}', [AuthController::class, 'socialLogin']);
        \call_user_func('app', 'router')->post('/auth/forgot-password', [PasswordResetController::class, 'sendResetLinkEmail']);
        \call_user_func('app', 'router')->post('/auth/reset-password', [PasswordResetController::class, 'reset']);

        \call_user_func('app', 'router')->put('/user/current-team', [AuthController::class, 'switchTeam']);
    }

    public function test_register_missing_name_returns_422(): void
    {
        $this->postJson('/auth/register', [
            'email' => 'john@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_register_invalid_email_returns_422(): void
    {
        $this->postJson('/auth/register', [
            'name' => 'John Doe',
            'email' => 'not-an-email',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_short_password_returns_422(): void
    {
        $this->postJson('/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'Short12',
            'password_confirmation' => 'Short12',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_register_rejects_weak_password(): void
    {
        $this->postJson('/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'alllowercase123',
            'password_confirmation' => 'alllowercase123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->postJson('/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'ALLUPPERCASE123',
            'password_confirmation' => 'ALLUPPERCASE123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->postJson('/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'NoNumbersHere',
            'password_confirmation' => 'NoNumbersHere',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_register_missing_password_confirmation_returns_422(): void
    {
        $this->postJson('/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'Password123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_register_duplicate_email_returns_422(): void
    {
        AuthRequestsTestUser::query()->create([
            'name' => 'Existing',
            'email' => 'taken@example.com',
            'password' => Hash::make('Password123'),
        ]);

        $this->postJson('/auth/register', [
            'name' => 'John Doe',
            'email' => 'taken@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_valid_data_returns_201(): void
    {
        $this->postJson('/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'john@example.com');
    }

    public function test_login_missing_email_returns_422(): void
    {
        $this->postJson('/auth/login', [
            'password' => 'Password123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_missing_password_returns_422(): void
    {
        $this->postJson('/auth/login', [
            'email' => 'john@example.com',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_login_invalid_email_format_returns_422(): void
    {
        $this->postJson('/auth/login', [
            'email' => 'not-valid',
            'password' => 'Password123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_social_login_neither_token_nor_code_returns_422(): void
    {
        $this->postJson('/auth/social/github', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['access_token', 'authorization_code']);
    }

    public function test_forgot_password_missing_email_returns_422(): void
    {
        $this->postJson('/auth/forgot-password', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_forgot_password_invalid_email_format_returns_422(): void
    {
        $this->postJson('/auth/forgot-password', [
            'email' => 'not-an-email',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_reset_password_missing_token_returns_422(): void
    {
        $this->postJson('/auth/reset-password', [
            'email' => 'john@example.com',
            'password' => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);
    }

    public function test_reset_password_missing_email_returns_422(): void
    {
        $this->postJson('/auth/reset-password', [
            'token' => 'some-token',
            'password' => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_reset_password_missing_password_returns_422(): void
    {
        $this->postJson('/auth/reset-password', [
            'token' => 'some-token',
            'email' => 'john@example.com',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_switch_team_missing_team_id_returns_forbidden(): void
    {
        $user = AuthRequestsTestUser::query()->create([
            'name' => 'User',
            'email' => 'user@example.com',
            'password' => Hash::make('Password123'),
        ]);

        $this->actingAs($user)
            ->putJson('/user/current-team', [])
            ->assertForbidden();
    }

    public function test_switch_team_invalid_team_id_returns_forbidden(): void
    {
        $user = AuthRequestsTestUser::query()->create([
            'name' => 'User',
            'email' => 'user2@example.com',
            'password' => Hash::make('Password123'),
        ]);

        $this->actingAs($user)
            ->putJson('/user/current-team', ['team_id' => '00000000-0000-0000-0000-000000000000'])
            ->assertForbidden();
    }
}

final class AuthRequestsTestUser extends Model implements AuthenticatableContract
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

    public function createToken(string $_name): object
    {
        return new class
        {
            public string $plainTextToken = 'test-token-abc123';

            public object $accessToken;

            public function __construct()
            {
                $this->accessToken = new class
                {
                    public function forceFill(array $attributes): self
                    {
                        return $this;
                    }

                    public function save(): bool
                    {
                        return true;
                    }
                };
            }
        };
    }
}

final class AuthRequestsTestTeam extends \FlutterSdk\MagicStarter\Models\Team
{
    protected $table = 'teams';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(AuthRequestsTestUser::class, 'team_user', 'team_id', 'user_id')
            ->using(\FlutterSdk\MagicStarter\Models\TeamUser::class)
            ->withPivot('role')
            ->withTimestamps();
    }
}
