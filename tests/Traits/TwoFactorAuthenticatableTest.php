<?php

namespace FlutterSdk\MagicStarter\Tests\Traits;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\TwoFactorAuthenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class TwoFactorAuthenticatableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        \call_user_func('config', ['database.default' => 'testing']);
        \call_user_func('config', ['database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        \call_user_func('config', ['app.key' => 'base64:XG8o1YFkL9FzM3NqZ8eB4Q6dC9yQ/K1R+g3M5T3r8P4=']);
        \call_user_func('config', ['magic-starter.two_factor.company_name' => 'Laravel']);

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_has_enabled_returns_false_when_not_confirmed(): void
    {
        /** @var TwoFactorAuthenticatableTestUser $user */
        $user = TwoFactorAuthenticatableTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'two_factor_confirmed_at' => null,
        ]);

        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_has_enabled_returns_true_when_confirmed(): void
    {
        /** @var TwoFactorAuthenticatableTestUser $user */
        $user = TwoFactorAuthenticatableTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'two_factor_confirmed_at' => now(),
        ]);

        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_two_factor_secret_returns_null_when_not_set(): void
    {
        /** @var TwoFactorAuthenticatableTestUser $user */
        $user = TwoFactorAuthenticatableTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'two_factor_secret' => null,
        ]);

        $this->assertNull($user->twoFactorSecret());
    }

    public function test_two_factor_secret_decrypts_stored_value(): void
    {
        /** @var TwoFactorAuthenticatableTestUser $user */
        $user = TwoFactorAuthenticatableTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'two_factor_secret' => encrypt('SECRETKEY'),
        ]);

        $this->assertSame('SECRETKEY', $user->twoFactorSecret());
    }

    public function test_recovery_codes_returns_empty_array_when_not_set(): void
    {
        /** @var TwoFactorAuthenticatableTestUser $user */
        $user = TwoFactorAuthenticatableTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'two_factor_recovery_codes' => null,
        ]);

        $this->assertSame([], $user->recoveryCodes());
    }

    public function test_recovery_codes_decrypts_and_returns_array(): void
    {
        $codes = ['code1', 'code2'];

        /** @var TwoFactorAuthenticatableTestUser $user */
        $user = TwoFactorAuthenticatableTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
        ]);

        $this->assertSame($codes, $user->recoveryCodes());
    }

    public function test_replace_recovery_code_removes_used_and_adds_fresh(): void
    {
        $codes = ['code1', 'code2', 'code3', 'code4', 'code5', 'code6', 'code7', 'code8'];

        /** @var TwoFactorAuthenticatableTestUser $user */
        $user = TwoFactorAuthenticatableTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
        ]);

        $this->assertCount(8, $user->recoveryCodes());
        $this->assertContains('code1', $user->recoveryCodes());

        $user->replaceRecoveryCode('code1');

        $user->refresh();

        $this->assertCount(8, $user->recoveryCodes());
        $this->assertNotContains('code1', $user->recoveryCodes());
    }

    public function test_two_factor_qr_code_url_returns_otpauth_string(): void
    {
        /** @var TwoFactorAuthenticatableTestUser $user */
        $user = TwoFactorAuthenticatableTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'two_factor_secret' => encrypt('SECRETKEY'),
        ]);

        $url = $user->twoFactorQrCodeUrl();

        $this->assertStringStartsWith('otpauth://totp/', $url);
        $this->assertStringContainsString('SECRETKEY', $url);
        $this->assertStringContainsString('test%40example.com', $url);
    }

    public function test_two_factor_recovery_codes_count_returns_correct_number(): void
    {
        $codes = ['code1', 'code2', 'code3'];

        /** @var TwoFactorAuthenticatableTestUser $user */
        $user = TwoFactorAuthenticatableTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
        ]);

        $this->assertSame(3, $user->twoFactorRecoveryCodesCount());
    }
}

/**
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 */
final class TwoFactorAuthenticatableTestUser extends Authenticatable implements AuthenticatableContract
{
    use HasUuids;
    use TwoFactorAuthenticatable;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
