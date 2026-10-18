<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Filament\Facades\Filament;
use Filament\Panel;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Support\BillingAuthorization;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Locks which panel the billing gate asks: the one a caller passes, or the
 * current panel when it passes none, so a caller acting for a known panel is
 * not decided by whichever panel the request booted.
 *
 * The list and callback rules themselves are locked in BillingRelationManagerTest.
 */
class BillingAuthorizationTest extends FilamentTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.admin.billing_emails', []);
    }

    public function test_an_explicit_panel_decides_with_its_own_plugin_callback(): void
    {
        $seen = null;
        $strict = $this->strictPanel(static function (Authenticatable $admin, ?Panel $panel) use (&$seen): bool {
            $seen = $panel;

            return false;
        });

        $admin = $this->admin();

        Filament::setCurrentPanel(null);

        // With no current panel and no billing list, the default still admits every admin.
        $this->assertTrue(BillingAuthorization::allows($admin));
        $this->assertFalse(BillingAuthorization::allows($admin, $strict));
        $this->assertSame($strict, $seen);
    }

    public function test_without_a_panel_argument_the_current_panel_still_decides(): void
    {
        Filament::setCurrentPanel($this->strictPanel(static fn (): bool => false));

        $this->assertFalse(BillingAuthorization::allows($this->admin()));
    }

    /**
     * @param  callable(Authenticatable, ?Panel): mixed  $callback
     */
    private function strictPanel(callable $callback): Panel
    {
        return Panel::make()
            ->id('strict')
            ->plugin(MagicStarterPlugin::make()->authorizeBillingUsing($callback(...)));
    }

    private function admin(): ConcreteAdminUser
    {
        $admin = new ConcreteAdminUser;
        $admin->forceFill([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'email_verified_at' => now(),
            'password' => 'secret',
        ])->save();

        return $admin;
    }
}
