<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\TwoFactorAuthenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

final class AuthControllerTwoFactorLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();
        MagicStarter::useUserModel(AuthControllerTwoFactorLoginTestUser::class);

        \call_user_func('config', ['database.default' => 'testing']);
        \call_user_func('config', ['database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        \call_user_func('config', ['app.key' => 'base64:XG8o1YFkL9FzM3NqZ8eB4Q6dC9yQ/K1R+g3M5T3r8P4=']);
        \call_user_func('config', ['magic-starter.features' => ['two-factor-authentication']]);
        \call_user_func('config', ['magic-starter.two_factor.challenge_token_ttl' => 5]);

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuidMorphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function test_login_returns_challenge_when_2fa_confirmed(): void
    {
        $user = AuthControllerTwoFactorLoginTestUser::query()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->postJson('/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJson([
                'two_factor' => true,
            ])
            ->assertJsonStructure([
                'two_factor_token',
            ]);
    }

    public function test_login_does_not_return_sanctum_token_when_2fa_enabled(): void
    {
        $user = AuthControllerTwoFactorLoginTestUser::query()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->postJson('/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonMissing([
                'data' => [
                    'token',
                ],
            ]);
    }

    public function test_challenge_token_contains_correct_payload(): void
    {
        $user = AuthControllerTwoFactorLoginTestUser::query()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $token = $response->json('two_factor_token');
        $payload = json_decode(decrypt($token), true);

        $this->assertEquals($user->id, $payload['user_id']);
        $this->assertGreaterThan(now()->timestamp, $payload['expires_at']);
        $this->assertLessThanOrEqual(now()->addMinutes(5)->timestamp, $payload['expires_at']);
    }

    public function test_login_proceeds_normally_when_2fa_not_confirmed(): void
    {
        $user = AuthControllerTwoFactorLoginTestUser::query()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => null, // Not confirmed
        ]);

        $this->postJson('/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'user',
                    'token',
                ],
            ]);
    }

    public function test_login_proceeds_normally_without_2fa_trait(): void
    {
        MagicStarter::useUserModel(AuthControllerTwoFactorLoginTestUserWithoutTrait::class);

        $user = AuthControllerTwoFactorLoginTestUserWithoutTrait::query()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'user',
                    'token',
                ],
            ]);
    }

    public function test_login_returns_401_with_wrong_password(): void
    {
        $user = AuthControllerTwoFactorLoginTestUser::query()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->postJson('/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401);
    }
}

final class AuthControllerTwoFactorLoginTestUser extends Authenticatable implements AuthenticatableContract
{
    use HasApiTokens;
    use HasUuids;
    use TwoFactorAuthenticatable;

    protected $table = 'users';

    protected $guarded = [];

    public function allTeams()
    {
        return collect([]);
    }

    public function getCurrentTeamOrPersonal()
    {
        return null;
    }
}

final class AuthControllerTwoFactorLoginTestUserWithoutTrait extends Authenticatable implements AuthenticatableContract
{
    use HasApiTokens;
    use HasUuids;

    protected $table = 'users';

    protected $guarded = [];

    public function allTeams()
    {
        return collect([]);
    }

    public function getCurrentTeamOrPersonal()
    {
        return null;
    }
}
