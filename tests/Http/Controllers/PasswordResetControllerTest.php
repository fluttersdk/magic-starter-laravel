<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Controllers\PasswordResetController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Mockery;

class PasswordResetControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        config([
            'magic-starter.models.user' => PasswordResetControllerTestUser::class,
            'magic-starter.models.team' => PasswordResetControllerTestTeam::class,
        ]);

        Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLinkEmail']);
        Route::post('/reset-password', [PasswordResetController::class, 'reset']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        MagicStarter::reset();
        parent::tearDown();
    }

    public function test_send_reset_link_email_always_returns_ok_to_prevent_enumeration(): void
    {
        Password::shouldReceive('sendResetLink')
            ->once()
            ->with(['email' => 'john@example.com'])
            ->andReturn(Password::RESET_LINK_SENT);

        $response = $this->postJson('/forgot-password', [
            'email' => 'john@example.com',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'If an account with that email exists, a password reset link has been sent.');
    }

    public function test_send_reset_link_email_returns_ok_even_when_user_not_found(): void
    {
        Password::shouldReceive('sendResetLink')
            ->once()
            ->with(['email' => 'missing@example.com'])
            ->andReturn(Password::INVALID_USER);

        $response = $this->postJson('/forgot-password', [
            'email' => 'missing@example.com',
        ]);

        // Always returns 200 to prevent email enumeration.
        $response
            ->assertOk()
            ->assertJsonPath('message', 'If an account with that email exists, a password reset link has been sent.');
    }

    public function test_reset_returns_ok_when_password_is_reset(): void
    {
        $user = new PasswordResetTestUser;
        $user->password = Hash::make('Old-Password-123');

        Password::shouldReceive('reset')
            ->once()
            ->andReturnUsing(function (array $credentials, callable $callback) use ($user): string {
                $callback($user, $credentials['password']);

                return Password::PASSWORD_RESET;
            });

        $response = $this->postJson('/reset-password', [
            'token' => 'token-1',
            'email' => 'john@example.com',
            'password' => 'New-Password-123',
            'password_confirmation' => 'New-Password-123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', __((string) Password::PASSWORD_RESET));

        $this->assertTrue(Hash::check('New-Password-123', $user->password));
        $this->assertNotNull($user->remember_token);
        $this->assertTrue($user->saved);
    }

    public function test_reset_returns_bad_request_when_broker_fails(): void
    {
        Password::shouldReceive('reset')
            ->once()
            ->andReturn(Password::INVALID_TOKEN);

        $response = $this->postJson('/reset-password', [
            'token' => 'invalid-token',
            'email' => 'john@example.com',
            'password' => 'New-Password-123',
            'password_confirmation' => 'New-Password-123',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', __((string) Password::INVALID_TOKEN));
    }
}

final class PasswordResetTestUser
{
    public ?string $password = null;

    public ?string $remember_token = null;

    public bool $saved = false;

    public function forceFill(array $attributes): self
    {
        if (array_key_exists('password', $attributes)) {
            $this->password = $attributes['password'];
        }

        return $this;
    }

    public function setRememberToken(string $value): self
    {
        $this->remember_token = $value;

        return $this;
    }

    public function save(): bool
    {
        $this->saved = true;

        return true;
    }
}

final class PasswordResetControllerTestUser {}

final class PasswordResetControllerTestTeam {}
