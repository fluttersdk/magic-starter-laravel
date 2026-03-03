<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Controllers\TwoFactorRecoveryCodeController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class TwoFactorRecoveryCodeControllerTest extends TestCase
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
            'magic-starter.models.user' => TwoFactorRecoveryControllerTestUser::class,
        ]);

        Schema::create('users', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('device_id')->unique()->nullable();
            $table->string('phone')->nullable()->unique();
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

        Route::post('/two-factor-recovery-codes/show', [TwoFactorRecoveryCodeController::class, 'index']);
        Route::post('/two-factor-recovery-codes', [TwoFactorRecoveryCodeController::class, 'store']);
    }

    public function test_index_returns_recovery_codes_when_2fa_enabled(): void
    {
        $codes = ['code1', 'code2', 'code3'];

        $user = TwoFactorRecoveryControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/two-factor-recovery-codes/show', ['password' => 'password'])
            ->assertOk()
            ->assertJson([
                'data' => $codes,
            ]);
    }

    public function test_index_returns_403_when_2fa_not_enabled(): void
    {
        $user = TwoFactorRecoveryControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user)
            ->postJson('/two-factor-recovery-codes/show', ['password' => 'password'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Two-factor authentication is not enabled.');
    }

    public function test_store_regenerates_recovery_codes(): void
    {
        $oldCodes = ['old-code-1', 'old-code-2'];

        $user = TwoFactorRecoveryControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode($oldCodes)),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/two-factor-recovery-codes', ['password' => 'password'])
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'message',
            ]);

        $user->refresh();

        $newCodes = $user->recoveryCodes();

        $this->assertNotSame($oldCodes, $newCodes);
        $this->assertCount(8, $newCodes); // Magic starter generates 8 codes by default
    }

    public function test_store_returns_403_when_2fa_not_enabled(): void
    {
        $user = TwoFactorRecoveryControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user)
            ->postJson('/two-factor-recovery-codes', ['password' => 'password'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Two-factor authentication is not enabled.');
    }

    public function test_index_requires_password(): void
    {
        $user = TwoFactorRecoveryControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1'])),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/two-factor-recovery-codes/show', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_index_rejects_wrong_password(): void
    {
        $user = TwoFactorRecoveryControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1'])),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/two-factor-recovery-codes/show', ['password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_store_requires_password(): void
    {
        $user = TwoFactorRecoveryControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1'])),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/two-factor-recovery-codes', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_store_rejects_wrong_password(): void
    {
        $user = TwoFactorRecoveryControllerTestUser::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1'])),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/two-factor-recovery-codes', [
                'password' => 'wrong-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
}

final class TwoFactorRecoveryControllerTestUser extends Model implements AuthenticatableContract
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
