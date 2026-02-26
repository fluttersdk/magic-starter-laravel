<?php

namespace FlutterSdk\MagicStarter\Console;

use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use Illuminate\Console\Command;

/**
 * Artisan command to scaffold Magic Starter into a Laravel application.
 */
class InstallCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'magic-starter:install {--force : Overwrite existing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install Magic Starter configuration, migrations, and action stubs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $publishOptions = [
            '--provider' => MagicStarterServiceProvider::class,
        ];

        if ((bool) $this->option('force')) {
            $publishOptions['--force'] = true;
        }

        $this->call('vendor:publish', [
            ...$publishOptions,
            '--tag' => 'magic-starter-config',
        ]);

        $this->call('vendor:publish', [
            ...$publishOptions,
            '--tag' => 'magic-starter-migrations',
        ]);

        $this->call('vendor:publish', [
            ...$publishOptions,
            '--tag' => 'magic-starter-stubs',
        ]);
        $this->call('vendor:publish', [
            ...$publishOptions,
            '--tag' => 'magic-starter-models',
        ]);


        $this->info('Magic Starter installed.');

        return 0;
    }
}
