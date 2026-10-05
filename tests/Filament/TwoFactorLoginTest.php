<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Panel;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Auth\TwoFactorAuthentication;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Support\TwoFactorAuthenticationProvider;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/**
 * The panel login asks the starter's own second factor of a user who turned it
 * on through the API, and only of that user.
 *
 * The secret, the confirmation and the recovery codes are the ones the API
 * reads; the panel never keeps a second copy. Every refusal case is paired with
 * the admission it would otherwise hide: a challenge that refused everyone would
 * pass all the refusals.
 */
class TwoFactorLoginTest extends FilamentTestCase
{
    protected const ADMIN_EMAIL = 'ops@example.com';

    protected const PASSWORD = 'correct-horse';

    /**
     * A literal, not `TwoFactorAuthentication::ID`: PHPUnit evaluates class
     * constants while building the suite, and loading the provider fatals in the
     * no-Filament job before `setUp()` can skip.
     */
    protected const STATE = 'data.multiFactor.two_factor';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('magic-starter.admin.emails', [self::ADMIN_EMAIL]);
        config()->set('magic-starter.features', [Features::twoFactorAuthentication()]);
    }

    /**
     * The login page resolves the user through the guard's provider, which
     * otherwise hands back the framework's bare user model.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', ConcreteAdminUser::class);
    }

    public function test_a_user_with_two_factor_on_is_challenged_instead_of_signed_in(): void
    {
        $this->adminUser(twoFactor: true);

        $this->submitPassword()
            ->assertSet('userUndertakingMultiFactorAuthentication', fn (?string $value): bool => filled($value));

        $this->assertGuest();
    }

    public function test_the_current_code_completes_the_sign_in(): void
    {
        $user = $this->adminUser(twoFactor: true);

        $login = $this->submitPassword()
            ->assertSet('userUndertakingMultiFactorAuthentication', fn (?string $value): bool => filled($value));

        $this->assertGuest();

        $login->set(self::STATE . '.code', $this->currentCode($user))
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_code_spent_through_the_api_cannot_sign_in_to_the_panel(): void
    {
        // The panel and the API share the last accepted time step, so one
        // observed code opens neither surface twice.
        $user = $this->adminUser(twoFactor: true);
        $code = $this->currentCode($user);

        $this->assertTrue(app(TwoFactorAuthenticationProvider::class)->verifyOnce((string) $user->twoFactorSecret(), $code));

        $this->submitPassword()
            ->set(self::STATE . '.code', $code)
            ->call('authenticate')
            ->assertHasErrors([self::STATE . '.code']);

        $this->assertGuest();
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $user = $this->adminUser(twoFactor: true);

        $this->submitPassword()
            ->set(self::STATE . '.code', $this->wrongCode($user))
            ->call('authenticate')
            ->assertHasErrors([self::STATE . '.code']);

        $this->assertGuest();
    }

    public function test_a_recovery_code_signs_in_once_and_is_replaced(): void
    {
        $user = $this->adminUser(twoFactor: true);

        $this->submitPassword()
            ->set(self::STATE . '.useRecoveryCode', true)
            ->set(self::STATE . '.recoveryCode', 'first-recovery')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertNotContains('first-recovery', $user->fresh()->recoveryCodes());
        $this->assertContains('second-recovery', $user->fresh()->recoveryCodes());
        $this->assertCount(2, $user->fresh()->recoveryCodes());
    }

    public function test_an_unknown_recovery_code_is_refused(): void
    {
        $this->adminUser(twoFactor: true);

        $this->submitPassword()
            ->set(self::STATE . '.useRecoveryCode', true)
            ->set(self::STATE . '.recoveryCode', 'not-a-code')
            ->call('authenticate')
            ->assertHasErrors([self::STATE . '.recoveryCode']);

        $this->assertGuest();
    }

    public function test_a_user_without_two_factor_signs_in_with_the_password_alone(): void
    {
        $user = $this->adminUser(twoFactor: false);

        $this->submitPassword()->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_unconfirmed_secret_does_not_challenge(): void
    {
        // The API's enable step writes the secret before the user confirms a
        // code; until then two-factor is not on, for the API and the panel alike.
        $user = $this->adminUser(twoFactor: true, attributes: ['two_factor_confirmed_at' => null]);

        $this->submitPassword()->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_challenge_follows_the_feature_flag(): void
    {
        config()->set('magic-starter.features', []);

        $user = $this->adminUser(twoFactor: true);

        $this->submitPassword()->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_plugin_mounts_the_challenge_on_a_panel_without_one(): void
    {
        $this->assertSame('data.multiFactor.' . TwoFactorAuthentication::ID, self::STATE);
        $this->assertSame(
            [TwoFactorAuthentication::ID],
            array_keys(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()),
        );
    }

    public function test_the_plugin_leaves_an_app_configured_challenge_alone(): void
    {
        $panel = Panel::make()
            ->id('own-mfa')
            ->multiFactorAuthentication([AppAuthentication::make()], isRequired: true)
            ->plugin(MagicStarterPlugin::make());

        $this->assertSame(['app'], array_keys($panel->getMultiFactorAuthenticationProviders()));
        $this->assertTrue($panel->isMultiFactorAuthenticationRequired());
    }

    /**
     * @return \Livewire\Features\SupportTesting\Testable
     */
    private function submitPassword()
    {
        return Livewire::test(Login::class)
            ->set('data.email', self::ADMIN_EMAIL)
            ->set('data.password', self::PASSWORD)
            ->call('authenticate');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function adminUser(bool $twoFactor, array $attributes = []): ConcreteAdminUser
    {
        $engine = app(TwoFactorAuthenticationProvider::class)->engine;

        $user = new ConcreteAdminUser;
        $user->forceFill([
            'name' => 'Ops',
            'email' => self::ADMIN_EMAIL,
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
            ...($twoFactor ? [
                'two_factor_secret' => encrypt($engine->generateSecretKey()),
                'two_factor_recovery_codes' => encrypt(json_encode(['first-recovery', 'second-recovery'])),
                'two_factor_confirmed_at' => now(),
            ] : []),
            ...$attributes,
        ])->save();

        return $user;
    }

    private function currentCode(ConcreteAdminUser $user): string
    {
        return app(TwoFactorAuthenticationProvider::class)->engine->getCurrentOtp((string) $user->twoFactorSecret());
    }

    /**
     * A well-formed code the verifier's one-step window can never accept.
     */
    private function wrongCode(ConcreteAdminUser $user): string
    {
        $engine = app(TwoFactorAuthenticationProvider::class)->engine;
        $secret = (string) $user->twoFactorSecret();

        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while ($engine->verifyKey($secret, $code, 1));

        return $code;
    }
}
