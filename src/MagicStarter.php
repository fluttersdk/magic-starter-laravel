<?php

namespace FlutterSdk\MagicStarter;

use FlutterSdk\MagicStarter\Models\Team;
use FlutterSdk\MagicStarter\Models\TeamInvitation;
use FlutterSdk\MagicStarter\Models\TeamUser;
use RuntimeException;

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
     * Abstract package model classes that should be auto-resolved
     * to their concrete App\Models equivalents.
     *
     * @var array<class-string, string>
     */
    protected static array $abstractModelMap = [
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
     * When the config points to the abstract package class, this method
     * auto-resolves to the concrete App\Models equivalent if it exists.
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
     * Resolve an abstract package model to its concrete App\Models equivalent.
     *
     * When the configured class is one of the package's abstract models,
     * this method checks if a concrete class exists in the application's
     * App\Models namespace and returns it instead. This prevents
     * "cannot instantiate abstract class" errors when the consumer
     * has published model stubs but hasn't updated the config.
     *
     * @param  class-string  $model  The configured model class.
     * @return class-string The resolved concrete class.
     */
    protected static function resolveConcreteModel(string $model): string
    {
        // 1. If the class is not abstract, return as-is.
        if (! isset(static::$abstractModelMap[$model])) {
            return $model;
        }

        // 2. Check if the App\Models concrete class exists.
        $concrete = static::$abstractModelMap[$model];

        if (class_exists($concrete)) {
            return $concrete;
        }

        // 3. No concrete found — return original (will fail at instantiation with a clear error).
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
