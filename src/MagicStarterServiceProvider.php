<?php

namespace FlutterSdk\MagicStarter;

use FlutterSdk\MagicStarter\Console\InstallCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Magic Starter package.
 */
class MagicStarterServiceProvider extends ServiceProvider
{
    /**
     * Register package services and merge configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/magic-starter.php',
            'magic-starter',
        );
    }

    /**
     * Bootstrap package routes, commands, and publishable assets.
     */
    public function boot(): void
    {
        if (! MagicStarter::shouldIgnoreRoutes()) {
            $this->loadRoutesFrom(__DIR__ . '/routes/api.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/magic-starter.php' => config_path('magic-starter.php'),
            ], 'magic-starter-config');

            $this->publishesMigrations([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'magic-starter-migrations');

            $this->publishes([
                __DIR__ . '/../stubs' => base_path('stubs/vendor/magic-starter'),
            ], 'magic-starter-stubs');

            $this->publishes([
                __DIR__ . '/../stubs/actions' => app_path('Actions/MagicStarter'),
            ], 'magic-starter-stubs');
        }
    }
}
