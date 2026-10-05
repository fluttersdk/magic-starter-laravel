<?php

namespace FlutterSdk\MagicStarter\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\ServiceProvider;

/**
 * Sets the Filament admin panel up in an application.
 *
 * Writes `app/Providers/Filament/AdminPanelProvider.php` from the stub, lists
 * it in `bootstrap/providers.php` and prints what is left to the developer:
 * the user model lines and the environment keys. The user model is never
 * written, because it is the application's own file.
 *
 * Idempotent: an existing provider is kept unless `--force` is given, and the
 * provider is listed once however often this runs.
 *
 * Registered by the package provider only while Filament is installed.
 */
class FilamentInstallCommand extends Command
{
    public const NAME = 'magic-starter:filament:install';

    public const PROVIDER = 'App\\Providers\\Filament\\AdminPanelProvider';

    /**
     * @var string
     */
    protected $signature = self::NAME . ' {--force : Overwrite the panel provider when it already exists}';

    /**
     * @var string
     */
    protected $description = 'Create the Filament admin panel provider and register it';

    public function handle(Filesystem $files): int
    {
        // 1. The provider file, kept when the application already has one.
        $this->publishProvider($files);

        // 2. Listed in the bootstrap file, once.
        $this->registerProvider();

        // 3. The two things this command will not do for the developer.
        $this->printNextSteps();

        return self::SUCCESS;
    }

    private function publishProvider(Filesystem $files): void
    {
        $destination = app_path('Providers/Filament/AdminPanelProvider.php');

        if ($files->exists($destination) && ! (bool) $this->option('force')) {
            $this->components->warn(
                'app/Providers/Filament/AdminPanelProvider.php exists; kept (use --force to overwrite).',
            );

            return;
        }

        // The CSRF middleware was renamed in Laravel 13, and the stub has to
        // name whichever one the installed framework has.
        $csrf = class_exists(PreventRequestForgery::class) ? PreventRequestForgery::class : VerifyCsrfToken::class;

        $source = $files->get(__DIR__ . '/../../stubs/filament/AdminPanelProvider.php.stub');

        $files->ensureDirectoryExists(dirname($destination));
        $files->put($destination, str_replace(
            ['{{ csrf }}', '{{ csrfName }}'],
            [$csrf, class_basename($csrf)],
            $source,
        ));

        $this->components->info('Created app/Providers/Filament/AdminPanelProvider.php.');
    }

    private function registerProvider(): void
    {
        $path = $this->laravel->getBootstrapProvidersPath();

        if (! file_exists($path)) {
            $this->components->warn('bootstrap/providers.php not found. Register the provider yourself:');
            $this->line('    ' . self::PROVIDER . '::class');

            return;
        }

        // Matched on the basename so a hand edit that imports the class still counts.
        if (str_contains((string) file_get_contents($path), 'AdminPanelProvider::class')) {
            $this->components->info('bootstrap/providers.php already lists the admin panel provider.');

            return;
        }

        ServiceProvider::addProviderToBootstrapFile(self::PROVIDER, $path);

        $this->components->info('Registered the admin panel provider in bootstrap/providers.php.');
    }

    private function printNextSteps(): void
    {
        $this->newLine();
        $this->line('Add to your user model (the panel refuses every user until you do):');
        $this->newLine();
        $this->line('    use Filament\Models\Contracts\FilamentUser;');
        $this->line('    use FlutterSdk\MagicStarter\Filament\Concerns\AuthorizesAdminPanel;');
        $this->newLine();
        $this->line('    class User extends Authenticatable implements FilamentUser');
        $this->line('    {');
        $this->line('        use AuthorizesAdminPanel;');
        $this->line('    }');
        $this->newLine();
        $this->line('Set in .env (comma-separated emails; the others are optional):');
        $this->newLine();

        foreach ([
            'MAGIC_STARTER_ADMIN_EMAILS=you@example.com',
            'MAGIC_STARTER_ADMIN_HOST=',
            'HORIZON_DOMAIN=',
            'HORIZON_PATH=',
            'PULSE_DOMAIN=',
            'PULSE_PATH=',
            'TELESCOPE_DOMAIN=',
            'TELESCOPE_PATH=',
        ] as $line) {
            $this->line('    ' . $line);
        }
    }
}
