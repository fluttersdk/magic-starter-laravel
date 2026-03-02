<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Controllers\TwoFactorChallengeController;
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

final class TwoFactorChallengeControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        config([
            'app.key' => 'base64:XG8o1YFkL9FzM3NqZ8eB4Q6dC9yQ/K1R+g3M5T3r8P4=',
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'magic-starter.models.user' => TwoFactorChallengeControllerTestUser::class,
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
            $table->string('current_team_id')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Route::post('/auth/two-factor-challenge', [TwoFactorChallengeController::class, 'store']);
    }

    public function test_challenge_with_valid_totp_code_returns_user_and_token(): void
    {
        /** @var TwoFactorAuthenticationProvider $provider */
        $provider = app(TwoFactorAuthenticationProvider::class);
        $secret = $provider->engine->generateSecretKey();

        $user = TwoFactorChallengeControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt($secret),
            'two_factor_confirmed_at' => now(),
        ]);

        $challengeToken = encrypt(json_encode([
            'user_id' => $user->getKey(),
            'expires_at' => now()->addMinutes(5)->timestamp,
        ]));

        $code = $provider->engine->getCurrentOtp($secret);

        $this->postJson('/auth/two-factor-challenge', [
            'two_factor_token' => $challengeToken,
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'user',
                    'token',
                ],
                'message',
            ]);
    }

    public function test_challenge_with_valid_recovery_code_returns_user_and_token(): void
    {
        $user = TwoFactorChallengeControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('ORUGS4ZANFZSAYJA'),
            'two_factor_recovery_codes' => encrypt(json_encode(['valid-code', 'another-code'])),
            'two_factor_confirmed_at' => now(),
        ]);

        $challengeToken = encrypt(json_encode([
            'user_id' => $user->getKey(),
            'expires_at' => now()->addMinutes(5)->timestamp,
        ]));

        $this->postJson('/auth/two-factor-challenge', [
            'two_factor_token' => $challengeToken,
            'recovery_code' => 'valid-code',
        ])
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'user',
                    'token',
                ],
                'message',
            ]);
    }

    public function test_challenge_with_recovery_code_replaces_used_code(): void
    {
        $user = TwoFactorChallengeControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('ORUGS4ZANFZSAYJA'),
            'two_factor_recovery_codes' => encrypt(json_encode(['valid-code', 'another-code'])),
            'two_factor_confirmed_at' => now(),
        ]);

        $challengeToken = encrypt(json_encode([
            'user_id' => $user->getKey(),
            'expires_at' => now()->addMinutes(5)->timestamp,
        ]));

        $this->postJson('/auth/two-factor-challenge', [
            'two_factor_token' => $challengeToken,
            'recovery_code' => 'valid-code',
        ])
            ->assertOk();

        $user->refresh();
        $codes = $user->recoveryCodes();

        $this->assertCount(2, $codes);
        $this->assertNotContains('valid-code', $codes);
        $this->assertContains('another-code', $codes);
    }

    public function test_challenge_rejects_invalid_totp_code(): void
    {
        $user = TwoFactorChallengeControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('ORUGS4ZANFZSAYJA'),
            'two_factor_confirmed_at' => now(),
        ]);

        $challengeToken = encrypt(json_encode([
            'user_id' => $user->getKey(),
            'expires_at' => now()->addMinutes(5)->timestamp,
        ]));

        $this->postJson('/auth/two-factor-challenge', [
            'two_factor_token' => $challengeToken,
            'code' => '000000',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_challenge_rejects_expired_token(): void
    {
        $user = TwoFactorChallengeControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('ORUGS4ZANFZSAYJA'),
            'two_factor_confirmed_at' => now(),
        ]);

        $challengeToken = encrypt(json_encode([
            'user_id' => $user->getKey(),
            'expires_at' => now()->subMinutes(1)->timestamp,
        ]));

        $this->postJson('/auth/two-factor-challenge', [
            'two_factor_token' => $challengeToken,
            'code' => '123456',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['two_factor_token']);
    }

    public function test_challenge_rejects_tampered_token(): void
    {
        $this->postJson('/auth/two-factor-challenge', [
            'two_factor_token' => 'invalid-base64-garbage',
            'code' => '123456',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['two_factor_token']);
    }

    public function test_challenge_rejects_invalid_recovery_code(): void
    {
        $user = TwoFactorChallengeControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('ORUGS4ZANFZSAYJA'),
            'two_factor_recovery_codes' => encrypt(json_encode(['valid-code', 'another-code'])),
            'two_factor_confirmed_at' => now(),
        ]);

        $challengeToken = encrypt(json_encode([
            'user_id' => $user->getKey(),
            'expires_at' => now()->addMinutes(5)->timestamp,
        ]));

        $this->postJson('/auth/two-factor-challenge', [
            'two_factor_token' => $challengeToken,
            'recovery_code' => 'wrong-code',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recovery_code']);
    }
}

final class TwoFactorChallengeControllerTestUser extends Model implements AuthenticatableContract
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
