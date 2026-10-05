<?php

namespace FlutterSdk\MagicStarter\Tests\Fixtures\Filament;

use Filament\Panel;
use Filament\PanelProvider;

/**
 * The fixture panel every Filament test drives: id `admin`, no tenancy.
 */
class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->default()
            ->path('admin')
            ->login();
    }
}
