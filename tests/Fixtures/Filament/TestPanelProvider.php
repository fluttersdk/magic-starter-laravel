<?php

namespace FlutterSdk\MagicStarter\Tests\Fixtures\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\PanelProvider;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;

/**
 * The fixture panel every Filament test drives: id `admin`, no tenancy,
 * carrying the package plugin. Filament's Authenticate middleware is what asks
 * `canAccessPanel()`. The plugin mounts its own dashboard on `/dashboard`, which
 * leaves the panel root without a page, so {@see TestRootPage} takes the root
 * and gives the gate a URL that returns 200 when it admits.
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
            ->authMiddleware([
                Authenticate::class,
            ])
            ->pages([
                TestRootPage::class,
            ])
            ->plugin(MagicStarterPlugin::make());
    }
}

/**
 * An empty page on the panel root.
 *
 * A named class rather than an anonymous one: Laravel splits a route action at
 * the `@` in an anonymous class name, so it could not be routed. It sits in this
 * file because only the Filament-only provider above ever loads it.
 */
class TestRootPage extends Page
{
    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }
}
