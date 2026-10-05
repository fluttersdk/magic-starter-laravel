<?php

namespace FlutterSdk\MagicStarter\Tests\Fixtures\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;

/**
 * The fixture panel every Filament test drives: id `admin`, no tenancy,
 * carrying the package plugin. Filament's Authenticate middleware is what asks
 * `canAccessPanel()`, and the stock dashboard gives the gate a page to guard.
 */
class TestPanelProvider extends PanelProvider
{
    /**
     * The panel is built while the application boots, before the test case
     * points the starter at its user model, and the plugin refuses to mount on a
     * model that is not a FilamentUser. The fixture declares the model it needs.
     */
    public function panel(Panel $panel): Panel
    {
        MagicStarter::useUserModel(ConcreteAdminUser::class);

        return $panel
            ->id('admin')
            ->default()
            ->path('admin')
            ->login()
            ->pages([
                Dashboard::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugin(MagicStarterPlugin::make());
    }
}
