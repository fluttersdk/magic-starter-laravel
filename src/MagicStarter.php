<?php

namespace FlutterSdk\MagicStarter;

use FlutterSdk\MagicStarter\Models\Team;
use FlutterSdk\MagicStarter\Models\TeamInvitation;
use FlutterSdk\MagicStarter\Models\TeamUser;
use RuntimeException;
use Throwable;

/**
 * Main entry point for Magic Starter configuration and model resolution.
 *
 * Provides static methods for resolving configured model classes,
 * controlling route registration, and setting runtime overrides.
 */
class MagicStarter
{
    /**
     * Indicates whether package routes should be ignored.
     */
    protected static bool $ignoreRoutes = false;

    /**
     * Custom class overrides registered at runtime.
     *
     * @var array<string, class-string>
     */
    protected static array $using = [];

    /**
     * Package model classes mapped to their App\Models equivalents.
     *
     * When the consumer publishes model stubs, this map allows the package
     * to auto-resolve the published App\Models class instead of the built-in one.
     *
     * @var array<class-string, string>
     */
    protected static array $appModelOverrides = [
        Team::class => 'App\\Models\\Team',
        TeamUser::class => 'App\\Models\\TeamUser',
        TeamInvitation::class => 'App\\Models\\TeamInvitation',
    ];

    /**
     * Resolve the configured user model class name.
     *
     * @return class-string
     *
     * @throws RuntimeException
     */
    public static function userModel(): string
    {
        $model = static::$using['user']
            ?? config('magic-starter.models.user')
            ?? config('auth.providers.users.model');

        if ($model === null || $model === '') {
            throw new RuntimeException(
                'User model not configured. Set magic-starter.models.user or auth.providers.users.model.',
            );
        }

        return $model;
    }

    /**
     * Resolve the configured team model class name.
     *
     * When the config points to the package's built-in class, this method
     * auto-resolves to the App\Models equivalent if published by the consumer.
     *
     * @return class-string
     *
     * @throws RuntimeException
     */
    public static function teamModel(): string
    {
        $model = static::$using['team']
            ?? config('magic-starter.models.team');

        if ($model === null || $model === '') {
            throw new RuntimeException(
                'Team model not configured. Set magic-starter.models.team.',
            );
        }

        return static::resolveConcreteModel($model);
    }

    /**
     * Resolve the configured membership model class name.
     *
     * @return class-string
     *
     * @throws RuntimeException
     */
    public static function membershipModel(): string
    {
        $model = static::$using['membership']
            ?? config('magic-starter.models.membership');

        if ($model === null || $model === '') {
            throw new RuntimeException(
                'Membership model not configured. Set magic-starter.models.membership.',
            );
        }

        return static::resolveConcreteModel($model);
    }

    /**
     * Resolve the configured team invitation model class name.
     *
     * @return class-string
     *
     * @throws RuntimeException
     */
    public static function teamInvitationModel(): string
    {
        $model = static::$using['team_invitation']
            ?? config('magic-starter.models.team_invitation');

        if ($model === null || $model === '') {
            throw new RuntimeException(
                'TeamInvitation model not configured. Set magic-starter.models.team_invitation.',
            );
        }

        return static::resolveConcreteModel($model);
    }

    /**
     * Resolve a package model to its App\Models equivalent if published.
     *
     * When the configured class is one of the package's built-in models,
     * this method checks if a concrete class exists in the application's
     * App\Models namespace and returns it instead. This allows consumers
     * to extend and customize models by publishing stubs.
     *
     * @param  class-string  $model  The configured model class.
     * @return class-string The resolved model class.
     */
    protected static function resolveConcreteModel(string $model): string
    {
        // 1. If the class is not in our override map, return as-is.
        if (! isset(static::$appModelOverrides[$model])) {
            return $model;
        }

        // 2. Check if the consumer has published an App\Models override.
        //    Wrapped in try/catch because Composer's classmap may reference a
        //    file that no longer exists (e.g. after stubs were deleted without
        //    running `composer dump-autoload`), which causes class_exists() to
        //    trigger a fatal include error.
        $concrete = static::$appModelOverrides[$model];

        try {
            if (class_exists($concrete)) {
                return $concrete;
            }
        } catch (Throwable) {
            // Stale classmap or broken autoload — fall through to built-in.
        }

        // 3. No override found — return built-in package model.
        return $model;
    }

    /**
     * Instruct Magic Starter to skip route registration.
     */
    public static function ignoreRoutes(): static
    {
        static::$ignoreRoutes = true;

        return new static;
    }

    /**
     * Determine whether package routes should be ignored.
     */
    public static function shouldIgnoreRoutes(): bool
    {
        return static::$ignoreRoutes;
    }

    /**
     * Register a custom user model class.
     */
    public static function useUserModel(string $class): static
    {
        static::$using['user'] = $class;

        return new static;
    }

    /**
     * Register a custom team model class.
     */
    public static function useTeamModel(string $class): static
    {
        static::$using['team'] = $class;

        return new static;
    }

    /**
     * Register a custom membership model class.
     */
    public static function useMembershipModel(string $class): static
    {
        static::$using['membership'] = $class;

        return new static;
    }

    /**
     * Register a custom team invitation model class.
     */
    public static function useTeamInvitationModel(string $class): static
    {
        static::$using['team_invitation'] = $class;

        return new static;
    }

    /**
     * Retrieve a custom class override by key.
     */
    public static function getUsing(string $key): ?string
    {
        return static::$using[$key] ?? null;
    }

    /**
     * Reset all static configuration to defaults.
     */
    public static function reset(): void
    {
        static::$ignoreRoutes = false;
        static::$using = [];
    }
}
