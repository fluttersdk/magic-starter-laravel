<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Controllers\TwoFactorAuthenticationController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\TwoFactorAuthenticationProvider;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class TwoFactorAuthenticationControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        config([
            'magic-starter.two_factor.company_name' => 'Laravel',
            'app.key' => 'base64:XG8o1YFkL9FzM3NqZ8eB4Q6dC9yQ/K1R+g3M5T3r8P4=',
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'magic-starter.models.user' => TwoFactorAuthControllerTestUser::class,
        ]);

        Schema::create('users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('device_id')->unique()->nullable();
            $table->char('phone_country', 2)->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('locale')->nullable();
            $table->string('timezone')->nullable();
            $table->string('language')->nullable();
            $table->string('current_team_id')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Route::middleware('auth')->post('/two-factor-authentication', [TwoFactorAuthenticationController::class, 'store']);
        Route::middleware('auth')->post('/two-factor-authentication/confirm', [TwoFactorAuthenticationController::class, 'confirm']);
        Route::middleware('auth')->delete('/two-factor-authentication', [TwoFactorAuthenticationController::class, 'destroy']);
    }

    public function test_store_enables_two_factor_and_returns_enrollment_data(): void
    {
        $user = TwoFactorAuthControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user)
            ->postJson('/two-factor-authentication')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'secret',
                    'qr_url',
                    'qr_svg',
                    'recovery_codes',
                ],
                'message',
            ]);

        $user->refresh();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/two-factor-authentication')
            ->assertStatus(401);
    }

    public function test_confirm_sets_confirmed_at_with_valid_code(): void
    {
        $user = TwoFactorAuthControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user)
            ->postJson('/two-factor-authentication');

        $user->refresh();

        /** @var TwoFactorAuthenticationProvider $provider */
        $provider = app(TwoFactorAuthenticationProvider::class);
        $code = $provider->engine->getCurrentOtp($user->twoFactorSecret());

        $this->actingAs($user)
            ->postJson('/two-factor-authentication/confirm', [
                'code' => $code,
            ])
            ->assertOk()
            ->assertJson([
                'data' => null,
                'message' => 'Two-factor authentication confirmed successfully.',
            ]);

        $user->refresh();

        $this->assertNotNull($user->two_factor_confirmed_at);
    }

    public function test_confirm_rejects_invalid_code(): void
    {
        $user = TwoFactorAuthControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user)
            ->postJson('/two-factor-authentication');

        $this->actingAs($user)
            ->postJson('/two-factor-authentication/confirm', [
                'code' => '000000',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_destroy_requires_password(): void
    {
        $user = TwoFactorAuthControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user)
            ->deleteJson('/two-factor-authentication')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_destroy_clears_two_factor_columns(): void
    {
        $user = TwoFactorAuthControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->deleteJson('/two-factor-authentication', [
                'password' => 'password',
            ])
            ->assertOk()
            ->assertJson([
                'data' => null,
                'message' => 'Two-factor authentication has been disabled.',
            ]);

        $user->refresh();

        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_destroy_rejects_wrong_password(): void
    {
        $user = TwoFactorAuthControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->deleteJson('/two-factor-authentication', [
                'password' => 'wrong-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
}

final class TwoFactorAuthControllerTestUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use Authorizable;
    use \FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
    use \FlutterSdk\MagicStarter\Traits\HasTeams;
    use \FlutterSdk\MagicStarter\Traits\TwoFactorAuthenticatable;
    use HasUuids;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function createToken(string $name): object
    {
        return new class
        {
            public string $plainTextToken = 'test-token';

            public object $accessToken;

            public function __construct()
            {
                $this->accessToken = new class
                {
                    public function forceFill(): self
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

    public function currentAccessToken(): mixed
    {
        return null;
    }

    public function allTeams()
    {
        return collect();
    }

    public function getCurrentTeamOrPersonal(): mixed
    {
        return null;
    }
}
