<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Panel;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\Filament\TestPanelProvider;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Livewire\LivewireServiceProvider;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

/**
 * Base for tests that need the Filament panel.
 *
 * Filament is an optional dev dependency, so the whole class skips when it is
 * absent: the no-optional CI job proves the package passes without it.
 */
abstract class FilamentTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(Panel::class)) {
            $this->markTestSkipped('filament/filament is not installed.');
        }

        parent::setUp();

        MagicStarter::useUserModel(ConcreteAdminUser::class);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->runPackageMigrations();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            TestPanelProvider::class,
        ];
    }

    /**
     * Filament's session and cookie middleware encrypt, which needs an app key.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    /**
     * The package ships its migrations unprefixed, so the framework loader
     * would sort `add_*` ahead of the `create_*` it alters. Creates run first.
     */
    private function runPackageMigrations(): void
    {
        $files = glob(__DIR__ . '/../../database/migrations/*.php');

        usort($files, static fn (string $a, string $b): int => [
            ! str_starts_with(basename($a), 'create_'),
            $a,
        ] <=> [
            ! str_starts_with(basename($b), 'create_'),
            $b,
        ]);

        foreach ($files as $file) {
            (require $file)->up();
        }
    }
}
