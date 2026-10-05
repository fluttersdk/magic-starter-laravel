<?php

namespace FlutterSdk\MagicStarter\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The panel dashboard, moved off the panel root onto `/dashboard`.
 *
 * Filament's stock dashboard sits on the root, and a panel whose root is
 * occupied by a page registers no `home` route of its own. Keeping the root
 * free leaves it to the host application to point at whatever it wants.
 */
class Dashboard extends BaseDashboard
{
    protected static string $routePath = 'dashboard';
}
