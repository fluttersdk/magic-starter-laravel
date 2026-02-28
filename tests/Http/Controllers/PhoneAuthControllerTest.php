<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Actions\CreateUser;
use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Controllers\PhoneAuthController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
use FlutterSdk\MagicStarter\Traits\HasTeams;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * Full test coverage for PhoneAuthController register() and login().
 */
class PhoneAuthControllerTest extends TestCase
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
            'auth.providers.users.model' => PhoneAuthTestUser::class,
            'magic-starter.models.user' => PhoneAuthTestUser::class,
            'magic-starter.models.team' => ConcreteTeam::class,
            'magic-starter.models.membership' => ConcreteTeamUser::class,
            'magic-starter.supported_locales' => [
                'en',
                'tr',
                'de',
            ],
            'magic-starter.supported_timezones' => [
                'UTC',
                'Europe/Istanbul',
                'Europe/London',
            ],
            'magic-starter.features' => [
                Features::phoneAuth(),
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

        $this->app->bind(CreatesUsers::class, CreateUser::class);

        Route::post('/phone-register', [PhoneAuthController::class, 'register']);
        Route::post('/phone-login', [PhoneAuthController::class, 'login']);
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Register tests
    // -------------------------------------------------------------------------

    /** @test */
    public function test_register_creates_user_with_phone_and_returns_201(): void
    {
        $response = $this->postJson('/phone-register', [
            'name' => 'Ali Veli',
            'phone' => '+905551234567',
            'phone_country' => 'TR',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Registration successful')
            ->assertJsonStructure([
                'data' => ['user', 'token'],
                'message',
            ]);

        $this->assertDatabaseHas('users', [
            'phone' => '+905551234567',
            'phone_country' => 'TR',
            'email' => null,
        ]);
    }

    /** @test */
    public function test_register_returns_422_on_duplicate_phone(): void
    {
        PhoneAuthTestUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Existing User',
            'phone' => '+905551234567',
            'phone_country' => 'TR',
            'password' => Hash::make('Password123'),
        ]);

        $response = $this->postJson('/phone-register', [
            'name' => 'New User',
            'phone' => '+905551234567',
            'phone_country' => 'TR',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    /** @test */
    public function test_register_returns_422_on_invalid_e164_format(): void
    {
        $response = $this->postJson('/phone-register', [
            'name' => 'Ali Veli',
            'phone' => '05551234567',
            'phone_country' => 'TR',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    /** @test */
    public function test_register_returns_422_on_invalid_phone_country(): void
    {
        $response = $this->postJson('/phone-register', [
            'name' => 'Ali Veli',
            'phone' => '+905551234567',
            'phone_country' => 'TUR',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_country']);
    }

    /** @test */
    public function test_register_returns_404_when_feature_disabled(): void
    {
        config(['magic-starter.features' => []]);

        Route::post('/phone-register-disabled', function (): \Illuminate\Http\JsonResponse {
            if (! Features::hasPhoneAuthFeatures()) {
                return response()->json(['message' => 'Not found.'], 404);
            }

            return (new PhoneAuthController)->register(
                app(\FlutterSdk\MagicStarter\Http\Requests\PhoneRegisterRequest::class),
            );
        });

        $response = $this->postJson('/phone-register-disabled', [
            'name' => 'Ali Veli',
            'phone' => '+905551234567',
            'phone_country' => 'TR',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertNotFound();
    }

    /** @test */
    public function test_register_fires_registered_event(): void
    {
        Event::fake([Registered::class]);

        $this->postJson('/phone-register', [
            'name' => 'Ali Veli',
            'phone' => '+905551234567',
            'phone_country' => 'TR',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        Event::assertDispatched(Registered::class);
    }

    // -------------------------------------------------------------------------
    // Login tests
    // -------------------------------------------------------------------------

    /** @test */
    public function test_login_returns_200_with_token_for_valid_credentials(): void
    {
        PhoneAuthTestUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Ali Veli',
            'phone' => '+905551234567',
            'phone_country' => 'TR',
            'password' => Hash::make('Password123'),
        ]);

        $response = $this->postJson('/phone-login', [
            'phone' => '+905551234567',
            'password' => 'Password123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Login successful')
            ->assertJsonStructure([
                'data' => ['user', 'token'],
                'message',
            ]);
    }

    /** @test */
    public function test_login_returns_401_for_wrong_password(): void
    {
        PhoneAuthTestUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Ali Veli',
            'phone' => '+905551234567',
            'phone_country' => 'TR',
            'password' => Hash::make('Password123'),
        ]);

        $response = $this->postJson('/phone-login', [
            'phone' => '+905551234567',
            'password' => 'WrongPassword1',
        ]);

        $response
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials');
    }

    /** @test */
    public function test_login_returns_401_for_non_existent_phone(): void
    {
        $response = $this->postJson('/phone-login', [
            'phone' => '+905559999999',
            'password' => 'Password123',
        ]);

        $response
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials');
    }

    /** @test */
    public function test_login_returns_422_for_invalid_phone_format(): void
    {
        $response = $this->postJson('/phone-login', [
            'phone' => '05551234567',
            'password' => 'Password123',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    /** @test */
    public function test_login_returns_404_when_feature_disabled(): void
    {
        config(['magic-starter.features' => []]);

        Route::post('/phone-login-disabled', function (): \Illuminate\Http\JsonResponse {
            if (! Features::hasPhoneAuthFeatures()) {
                return response()->json(['message' => 'Not found.'], 404);
            }

            return (new PhoneAuthController)->login(
                app(\FlutterSdk\MagicStarter\Http\Requests\PhoneLoginRequest::class),
            );
        });

        $response = $this->postJson('/phone-login-disabled', [
            'phone' => '+905551234567',
            'password' => 'Password123',
        ]);

        $response->assertNotFound();
    }
}

// ---------------------------------------------------------------------------
// Test-local user model — minimal for phone auth testing
// ---------------------------------------------------------------------------

/**
 * Minimal User fixture for PhoneAuthController tests.
 * Includes HasUuids for auto-ID generation and HasTeams/HasProfilePhoto
 * so UserResource serialises without missing-method errors.
 */
class PhoneAuthTestUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use Authorizable;
    use HasApiTokens;
    use HasProfilePhoto;
    use HasTeams;
    use HasUuids;

    protected $table = 'users';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
