<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\ConfirmTwoFactorAuthentication;
use FlutterSdk\MagicStarter\Actions\DisableTwoFactorAuthentication;
use FlutterSdk\MagicStarter\Actions\EnableTwoFactorAuthentication;
use FlutterSdk\MagicStarter\Actions\GenerateNewRecoveryCodes;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\TwoFactorAuthenticationProvider;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\TwoFactorAuthenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Validation\ValidationException;

final class TwoFactorActionsTest extends TestCase
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

    public function test_enable_stores_encrypted_secret_and_codes(): void
    {
        $action = new EnableTwoFactorAuthentication(app(TwoFactorAuthenticationProvider::class));

        /** @var TwoFactorActionsTestUser $user */
        $user = TwoFactorActionsTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
        ]);

        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);

        $action->enable($user);

        $user->refresh();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_recovery_codes);
        $this->assertNotNull($user->twoFactorSecret());
        $this->assertCount(8, $user->recoveryCodes());
    }

    public function test_enable_returns_secret_qr_url_and_recovery_codes(): void
    {
        $action = new EnableTwoFactorAuthentication(app(TwoFactorAuthenticationProvider::class));

        /** @var TwoFactorActionsTestUser $user */
        $user = TwoFactorActionsTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
        ]);

        $result = $action->enable($user);

        $this->assertArrayHasKey('secret', $result);
        $this->assertArrayHasKey('qr_url', $result);
        $this->assertArrayHasKey('recovery_codes', $result);

        $this->assertIsString($result['secret']);
        $this->assertStringStartsWith('otpauth://totp/', $result['qr_url']);
        $this->assertIsArray($result['recovery_codes']);
        $this->assertCount(8, $result['recovery_codes']);
    }

    public function test_enable_resets_confirmed_at_to_null(): void
    {
        $action = new EnableTwoFactorAuthentication(app(TwoFactorAuthenticationProvider::class));

        /** @var TwoFactorActionsTestUser $user */
        $user = TwoFactorActionsTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'two_factor_confirmed_at' => now(),
        ]);

        $this->assertNotNull($user->two_factor_confirmed_at);

        $action->enable($user);

        $user->refresh();

        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_enable_generates_configured_number_of_recovery_codes(): void
    {
        \call_user_func('config', ['magic-starter.two_factor.recovery_codes_count' => 12]);

        $action = new EnableTwoFactorAuthentication(app(TwoFactorAuthenticationProvider::class));

        /** @var TwoFactorActionsTestUser $user */
        $user = TwoFactorActionsTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
        ]);

        $result = $action->enable($user);

        $this->assertCount(12, $result['recovery_codes']);

        $user->refresh();

        $this->assertCount(12, $user->recoveryCodes());
    }

    public function test_confirm_sets_confirmed_at_for_valid_code(): void
    {
        /** @var \PragmaRX\Google2FA\Google2FA $engine */
        $engine = new \PragmaRX\Google2FA\Google2FA;
        $secret = $engine->generateSecretKey();
        $validCode = $engine->getCurrentOtp($secret);

        $action = new ConfirmTwoFactorAuthentication(app(TwoFactorAuthenticationProvider::class));

        /** @var TwoFactorActionsTestUser $user */
        $user = TwoFactorActionsTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'two_factor_secret' => encrypt($secret),
            'two_factor_confirmed_at' => null,
        ]);

        $action->confirm($user, $validCode);

        $user->refresh();

        $this->assertNotNull($user->two_factor_confirmed_at);
    }

    public function test_confirm_throws_validation_exception_for_invalid_code(): void
    {
        /** @var \PragmaRX\Google2FA\Google2FA $engine */
        $engine = new \PragmaRX\Google2FA\Google2FA;
        $secret = $engine->generateSecretKey();

        $action = new ConfirmTwoFactorAuthentication(app(TwoFactorAuthenticationProvider::class));

        /** @var TwoFactorActionsTestUser $user */
        $user = TwoFactorActionsTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'two_factor_secret' => encrypt($secret),
            'two_factor_confirmed_at' => null,
        ]);

        $this->expectException(ValidationException::class);

        $action->confirm($user, '000000');
    }

    public function test_disable_clears_all_two_factor_columns(): void
    {
        $action = new DisableTwoFactorAuthentication;

        /** @var TwoFactorActionsTestUser $user */
        $user = TwoFactorActionsTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'two_factor_secret' => encrypt('SECRET'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_recovery_codes);
        $this->assertNotNull($user->two_factor_confirmed_at);

        $action->disable($user);

        $user->refresh();

        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_generate_new_recovery_codes_replaces_existing(): void
    {
        $action = new GenerateNewRecoveryCodes;

        /** @var TwoFactorActionsTestUser $user */
        $user = TwoFactorActionsTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        ]);

        $oldCodes = $user->recoveryCodes();

        $this->assertCount(2, $oldCodes);
        $this->assertContains('code1', $oldCodes);

        $action->generate($user);

        $user->refresh();

        $newCodes = $user->recoveryCodes();

        $this->assertCount(8, $newCodes);
        $this->assertNotContains('code1', $newCodes);
    }

    public function test_generate_new_recovery_codes_returns_plain_codes(): void
    {
        $action = new GenerateNewRecoveryCodes;

        /** @var TwoFactorActionsTestUser $user */
        $user = TwoFactorActionsTestUser::query()->create([
            'name' => 'Test',
            'email' => 'test@example.com',
        ]);

        $result = $action->generate($user);

        $this->assertCount(8, $result);

        foreach ($result as $code) {
            $this->assertIsString($code);
        }
    }
}

/**
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 */
final class TwoFactorActionsTestUser extends Authenticatable implements AuthenticatableContract
{
    use HasUuids;
    use TwoFactorAuthenticatable;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
