<?php

namespace FlutterSdk\MagicStarter;

/**
 * Main entry point for Magic Starter configuration and model resolution.
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
     * Resolve the configured user model class name.
     *
     *
     * @throws \RuntimeException
     */
    public static function userModel(): string
    {
        $model = config('magic-starter.models.user')
            ?? config('auth.providers.users.model');

        if ($model === null || $model === '') {
            throw new \RuntimeException(
                'User model not configured. Set magic-starter.models.user or auth.providers.users.model.',
            );
        }

        return $model;
    }

    /**
     * Resolve the configured team model class name.
     *
     *
     * @throws \RuntimeException
     */
    public static function teamModel(): string
    {
        $model = config('magic-starter.models.team');

        if ($model === null || $model === '') {
            throw new \RuntimeException(
                'Team model not configured. Set magic-starter.models.team.',
            );
        }

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
