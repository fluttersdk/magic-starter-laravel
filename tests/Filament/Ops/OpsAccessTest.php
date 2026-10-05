<?php

namespace FlutterSdk\MagicStarter\Tests\Filament\Ops;

use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Widgets\HorizonStatusWidget;
use FlutterSdk\MagicStarter\Filament\Widgets\PulseSummaryWidget;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Filament\FilamentTestCase;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\Filament\TestPanelProvider;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonServiceProvider;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\PulseServiceProvider;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeServiceProvider;

/**
 * Horizon, Pulse and Telescope behind the admin panel's gate.
 *
 * The app carries its own provider that tells all three tools to deny everyone,
 * the way an app's HorizonServiceProvider or published TelescopeServiceProvider
 * does in its boot. The plugin must still be the rule in force, so the admin's
 * 200 is the overwrite case and the outsider's 403 is the gate case.
 *
 * Both providers are anonymous classes registered from a method: a file-level
 * class extending a Filament class would fatal in the no-Filament CI job.
 */
class OpsAccessTest extends FilamentTestCase
{
    protected const ADMIN_EMAIL = 'ops@example.com';

    /**
     * Telescope and Horizon keep their rules in statics that outlive this
     * application; restore them so no later test inherits this panel's gate.
     *
     * @var array<string, mixed>
     */
    private array $statics = [];

    protected function setUp(): void
    {
        if (! class_exists(Horizon::class) || ! class_exists(Pulse::class) || ! class_exists(Telescope::class)) {
            $this->markTestSkipped('laravel/horizon, laravel/pulse and laravel/telescope are not installed.');
        }

        $this->statics = [
            'horizon' => Horizon::$authUsing,
            'telescope' => Telescope::$authUsing,
            'filterBatch' => Telescope::$filterBatchUsing,
            'requestParameters' => Telescope::$hiddenRequestParameters,
            'responseParameters' => Telescope::$hiddenResponseParameters,
            'requestHeaders' => Telescope::$hiddenRequestHeaders,
        ];

        parent::setUp();

        $this->runVendorMigrations();
    }

    protected function tearDown(): void
    {
        // setUp skipped the test before the tools were touched.
        if ($this->statics === []) {
            parent::tearDown();

            return;
        }

        Telescope::stopRecording();
        Telescope::flushEntries();

        parent::tearDown();

        Horizon::$authUsing = $this->statics['horizon'];
        Telescope::$authUsing = $this->statics['telescope'];
        Telescope::$filterBatchUsing = $this->statics['filterBatch'];
        Telescope::$hiddenRequestParameters = $this->statics['requestParameters'];
        Telescope::$hiddenResponseParameters = $this->statics['responseParameters'];
        Telescope::$hiddenRequestHeaders = $this->statics['requestHeaders'];
    }

    public function test_an_allowlisted_admin_reaches_every_tool_despite_the_app_denying_all(): void
    {
        config()->set('magic-starter.admin.emails', [self::ADMIN_EMAIL]);

        $admin = $this->user(self::ADMIN_EMAIL);

        foreach (['/horizon', '/pulse', '/telescope'] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }
    }

    public function test_a_user_outside_the_allowlist_is_refused_by_every_tool(): void
    {
        config()->set('magic-starter.admin.emails', [self::ADMIN_EMAIL]);

        $outsider = $this->user('someone@example.com');

        foreach (['/horizon', '/pulse', '/telescope'] as $path) {
            $this->actingAs($outsider)->get($path)->assertForbidden();
        }
    }

    public function test_a_guest_is_refused_by_every_tool(): void
    {
        config()->set('magic-starter.admin.emails', [self::ADMIN_EMAIL]);

        foreach (['/horizon', '/pulse', '/telescope'] as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    public function test_the_operations_group_links_each_tool_by_its_route(): void
    {
        $items = collect(Filament::getPanel('admin')->getNavigationItems())
            ->mapWithKeys(static fn (NavigationItem $item): array => [
                $item->getUrl() => $item->getGroup(),
            ]);

        $this->assertSame(
            [
                route('horizon.index') => 'Operations',
                route('pulse') => 'Operations',
                route('telescope') => 'Operations',
                'https://sentry.example.com/issues' => 'Operations',
            ],
            $items->all(),
        );
    }

    public function test_the_tool_widgets_are_mounted_and_telescope_hides_the_starter_secrets(): void
    {
        $widgets = Filament::getPanel('admin')->getWidgets();

        $this->assertContains(HorizonStatusWidget::class, $widgets);
        $this->assertContains(PulseSummaryWidget::class, $widgets);
        $this->assertContains('id_token', Telescope::$hiddenRequestParameters);
        $this->assertContains('authorization', Telescope::$hiddenRequestHeaders);
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...array_values(array_filter(
                parent::getPackageProviders($app),
                static fn (string $provider): bool => $provider !== TestPanelProvider::class,
            )),
            HorizonServiceProvider::class,
            PulseServiceProvider::class,
            TelescopeServiceProvider::class,
        ];
    }

    /**
     * The panel, then the app provider that denies everyone. Boot follows
     * registration order, so the app provider boots after the panel is built,
     * exactly where an application's own tool provider sits.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('telescope.storage.database.connection', 'testing');

        $app->register($this->opsPanelProvider($app));
        $app->register($this->denyEveryoneProvider($app));
    }

    private function opsPanelProvider(mixed $app): PanelProvider
    {
        return new class($app) extends PanelProvider
        {
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
                    ->plugin(
                        MagicStarterPlugin::make()
                            ->horizon()
                            ->pulse()
                            ->telescope()
                            ->sentryUrl('https://sentry.example.com/issues'),
                    );
            }
        };
    }

    private function denyEveryoneProvider(mixed $app): ServiceProvider
    {
        return new class($app) extends ServiceProvider
        {
            public function boot(): void
            {
                Horizon::auth(static fn (): bool => false);
                Telescope::auth(static fn (): bool => false);
                $this->app->make(Gate::class)->define('viewPulse', static fn (): bool => false);
            }
        };
    }

    private function runVendorMigrations(): void
    {
        $files = [
            ...glob(__DIR__ . '/../../../vendor/laravel/pulse/database/migrations/*.php'),
            ...glob(__DIR__ . '/../../../vendor/laravel/telescope/database/migrations/*.php'),
        ];

        foreach ($files as $file) {
            (require $file)->up();
        }
    }

    private function user(string $email): ConcreteAdminUser
    {
        $user = new ConcreteAdminUser;
        $user->forceFill([
            'name' => 'Ops',
            'email' => $email,
            'email_verified_at' => now(),
            'password' => 'secret',
        ])->save();

        return $user;
    }
}
