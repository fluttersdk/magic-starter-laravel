<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Filament\Facades\Filament;
use Filament\Panel;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;

/**
 * The admin panel's access gate: {@see \FlutterSdk\MagicStarter\Filament\Concerns\AuthorizesAdminPanel}.
 *
 * The panel carries cross-team writes over every user and team, so this gate is
 * the whole of the access control. The allow case comes first: every other case
 * asserts a denial, and a gate that returns false unconditionally would pass all
 * of them, so the positive control is what gives them meaning.
 */
class AdminGateTest extends FilamentTestCase
{
    protected const ADMIN_EMAIL = 'ops@example.com';

    public function test_an_allowlisted_verified_user_may_access_the_panel(): void
    {
        $this->allowlist([self::ADMIN_EMAIL]);

        $this->assertTrue($this->adminUser()->canAccessPanel($this->panel()));
    }

    public function test_a_user_absent_from_the_allowlist_is_denied(): void
    {
        $this->allowlist(['someone.else@example.com']);

        $this->assertFalse($this->adminUser()->canAccessPanel($this->panel()));
    }

    public function test_an_empty_allowlist_denies_an_otherwise_perfect_user(): void
    {
        $this->allowlist([]);

        $this->assertFalse($this->adminUser()->canAccessPanel($this->panel()));
    }

    public function test_an_unset_allowlist_denies_an_otherwise_perfect_user(): void
    {
        config()->set('magic-starter.admin.emails', null);

        $this->assertFalse($this->adminUser()->canAccessPanel($this->panel()));
    }

    public function test_an_unverified_address_is_denied(): void
    {
        $this->allowlist([self::ADMIN_EMAIL]);

        $user = $this->adminUser(['email_verified_at' => null]);

        $this->assertFalse($user->canAccessPanel($this->panel()));
    }

    public function test_the_allowlist_matches_case_insensitively_and_tolerates_whitespace(): void
    {
        // Injected raw on purpose: a hand-edited config hands the gate exactly
        // this shape, which is why the gate normalises both sides itself.
        $this->allowlist(['  OPS@Example.COM ']);

        $user = $this->adminUser(['email' => 'Ops@EXAMPLE.com']);

        $this->assertTrue($user->canAccessPanel($this->panel()));
    }

    public function test_a_user_with_no_address_is_denied_even_against_an_empty_allowlist_entry(): void
    {
        // Verified and not a guest, so the empty-address refusal is the only
        // check that can keep this user out.
        $this->allowlist(['']);

        $user = $this->adminUser([
            'email' => null,
        ]);

        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertFalse($user->isGuest());
        $this->assertFalse($user->canAccessPanel($this->panel()));
    }

    public function test_a_guest_holding_an_allowlisted_address_is_denied(): void
    {
        // A guest can set any address through the profile endpoint, and a
        // guest's address always reads as verified.
        $this->allowlist([self::ADMIN_EMAIL]);

        $guest = $this->adminUser([
            'email_verified_at' => null,
            'is_guest' => true,
        ]);

        $this->assertTrue($guest->hasVerifiedEmail(), 'The guest bypass this case exists for is gone.');
        $this->assertFalse($guest->canAccessPanel($this->panel()));
    }

    public function test_the_gate_answers_only_for_a_panel_that_carries_the_plugin(): void
    {
        $this->allowlist([self::ADMIN_EMAIL]);

        $other = Panel::make()->id('customer');

        $this->assertFalse($this->adminUser()->canAccessPanel($other));
    }

    public function test_an_authorize_using_callback_replaces_the_allowlist(): void
    {
        $this->allowlist([]);

        $panel = Panel::make()
            ->id('custom')
            ->plugin(MagicStarterPlugin::make()->authorizeUsing(
                static fn (ConcreteAdminUser $user, Panel $panel): bool => $user->email === 'custom@example.com'
                    && $panel->getId() === 'custom',
            ));

        $this->assertTrue($this->adminUser(['email' => 'custom@example.com'])->canAccessPanel($panel));
        $this->assertFalse($this->adminUser(['email' => 'other@example.com'])->canAccessPanel($panel));
    }

    public function test_an_authorize_using_callback_can_deny_an_allowlisted_user(): void
    {
        $this->allowlist([self::ADMIN_EMAIL]);

        $panel = Panel::make()
            ->id('custom')
            ->plugin(MagicStarterPlugin::make()->authorizeUsing(static fn (): bool => false));

        $this->assertFalse($this->adminUser()->canAccessPanel($panel));
    }

    public function test_the_panel_http_path_actually_consults_the_gate(): void
    {
        // The 200 is the control: without it the 403 could come from any other
        // middleware on the panel, or from a route that stopped existing.
        $this->allowlist(['someone.else@example.com']);

        $this->actingAs($this->adminUser())
            ->get('/admin')
            ->assertForbidden();

        $this->allowlist([self::ADMIN_EMAIL]);

        $this->actingAs($this->adminUser(['email' => 'second.' . self::ADMIN_EMAIL]))
            ->get('/admin')
            ->assertForbidden();

        $this->actingAs(ConcreteAdminUser::query()->where('email', self::ADMIN_EMAIL)->sole())
            ->get('/admin')
            ->assertOk();
    }

    protected function panel(): Panel
    {
        return Filament::getPanel('admin');
    }

    /**
     * Inject the allowlist in its raw, un-normalised form.
     *
     * @param  list<string>  $emails
     */
    protected function allowlist(array $emails): void
    {
        config()->set('magic-starter.admin.emails', $emails);
    }

    /**
     * A user that satisfies every condition, before a case breaks exactly one.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function adminUser(array $attributes = []): ConcreteAdminUser
    {
        $user = new ConcreteAdminUser;
        $user->forceFill([
            'name' => 'Ops',
            'email' => self::ADMIN_EMAIL,
            'email_verified_at' => now(),
            'password' => 'secret',
            ...$attributes,
        ])->save();

        return $user;
    }
}
