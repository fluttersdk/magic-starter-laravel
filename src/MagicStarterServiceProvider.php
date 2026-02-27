<?php

namespace FlutterSdk\MagicStarter;

use FlutterSdk\MagicStarter\Console\InstallCommand;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * Service provider for the Magic Starter package.
 *
 * Handles configuration merging, action contract binding,
 * Sanctum token model setup, password reset URL, and
 * personal team creation on registration.
 */
class MagicStarterServiceProvider extends ServiceProvider
{
    /**
     * Register package services, merge configuration, and bind action contracts.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/magic-starter.php',
            'magic-starter',
        );

        // Prevent Sanctum from auto-loading its own migrations — we provide a custom version.
        if (class_exists(Sanctum::class) && method_exists(Sanctum::class, 'ignoreMigrations')) {
            Sanctum::ignoreMigrations();
        }

        // Bind all action contracts to package default implementations.
        // Consuming apps can override any of these with singleton() in their AppServiceProvider.
        $this->app->bind(Contracts\CreatesUsers::class, Actions\CreateUser::class);
        $this->app->bind(Contracts\UpdatesUserProfiles::class, Actions\UpdateUserProfile::class);
        $this->app->bind(Contracts\UpdatesUserPasswords::class, Actions\UpdateUserPassword::class);
        $this->app->bind(Contracts\DeletesUsers::class, Actions\DeleteUser::class);
        $this->app->bind(Contracts\CreatesTeams::class, Actions\CreateTeam::class);
        $this->app->bind(Contracts\UpdatesTeams::class, Actions\UpdateTeam::class);
        $this->app->bind(Contracts\DeletesTeams::class, Actions\DeleteTeam::class);
        $this->app->bind(Contracts\AddsTeamMembers::class, Actions\AddTeamMember::class);
        $this->app->bind(Contracts\RemovesTeamMembers::class, Actions\RemoveTeamMember::class);
        $this->app->bind(Contracts\InvitesTeamMembers::class, Actions\InviteTeamMember::class);
        $this->app->bind(Contracts\UpdatesTeamMemberRoles::class, Actions\UpdateTeamMemberRole::class);

        $this->app->singleton(Support\TwoFactorAuthenticationProvider::class);
        $this->app->bind(Contracts\EnablesTwoFactorAuthentication::class, Actions\EnableTwoFactorAuthentication::class);
        $this->app->bind(Contracts\ConfirmsTwoFactorAuthentication::class, Actions\ConfirmTwoFactorAuthentication::class);
        $this->app->bind(Contracts\DisablesTwoFactorAuthentication::class, Actions\DisableTwoFactorAuthentication::class);
        $this->app->bind(Contracts\GeneratesNewRecoveryCodes::class, Actions\GenerateNewRecoveryCodes::class);
    }

    /**
     * Bootstrap package routes, commands, Sanctum, password reset, and event listeners.
     */
    public function boot(): void
    {
        // 1. Sanctum personal access token model binding.
        if (class_exists(Sanctum::class)) {
            Sanctum::usePersonalAccessTokenModel(
                Models\PersonalAccessToken::class,
            );
        }

        // 2. Password reset URL using package config with app config fallback.
        ResetPassword::createUrlUsing(
            function (object $notifiable, string $token) {
                $frontendUrl = config(
                    'magic-starter.frontend_url',
                    config('app.frontend_url'),
                );

                return "{$frontendUrl}/auth/reset-password?token={$token}&email="
                    . $notifiable->getEmailForPasswordReset();
            },
        );

        // 3. Auto-register personal team creation when teams feature is enabled.
        if (Features::hasTeamFeatures()) {
            Event::listen(
                Registered::class,
                Listeners\CreatePersonalTeamListener::class,
            );
        }
        // 3.5. Auto-gate notification channels when notification feature is enabled.
        if (Features::hasNotificationFeatures()) {
            Event::listen(
                NotificationSending::class,
                Listeners\GateNotificationChannels::class,
            );
        }

        // 4. Load package routes unless explicitly ignored.
        if (! MagicStarter::shouldIgnoreRoutes()) {
            $this->loadRoutesFrom(__DIR__ . '/routes/api.php');
        }

        // 5. Console-only: publish config, migrations, and stubs.
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
                __DIR__ . '/../stubs/actions' => app_path('Actions/MagicStarter'),
            ], 'magic-starter-stubs');
            $this->publishes([
                __DIR__ . '/../stubs/models/Team.php' => app_path('Models/Team.php'),
                __DIR__ . '/../stubs/models/TeamUser.php' => app_path('Models/TeamUser.php'),
                __DIR__ . '/../stubs/models/TeamInvitation.php' => app_path('Models/TeamInvitation.php'),
            ], 'magic-starter-models');

        }
    }
}
