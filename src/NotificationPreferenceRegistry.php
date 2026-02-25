<?php

namespace FlutterSdk\MagicStarter;

/**
 * Registry for notification preferences types and their available channels.
 */
class NotificationPreferenceRegistry
{
    /**
     * Registered notification types.
     *
     * @var array<string, array{label: string, channels: array<string>, default: array<string>, locked: array<string>}>
     */
    protected static array $types = [];

    /**
     * Register new notification types.
     *
     * @param  array<string, array{label: string, channels: array<string>, default: array<string>, locked: array<string>}>  $types
     */
    public static function register(array $types): void
    {
        static::$types = array_merge(static::$types, $types);
    }

    /**
     * Get a registered notification type by its slug.
     *
     * @return array{label: string, channels: array<string>, default: array<string>, locked: array<string>}|null
     */
    public static function get(string $type): ?array
    {
        return static::$types[$type] ?? null;
    }

    /**
     * Get all registered notification types.
     *
     * @return array<string, array{label: string, channels: array<string>, default: array<string>, locked: array<string>}>
     */
    public static function all(): array
    {
        return static::$types;
    }

    /**
     * Get the available channels for a specific notification type.
     *
     * @return array<string>
     */
    public static function channels(string $type): array
    {
        return static::get($type)['channels'] ?? [];
    }

    /**
     * Get the default-enabled channels for a specific notification type.
     *
     * @return array<string>
     */
    public static function defaults(string $type): array
    {
        return static::get($type)['default'] ?? [];
    }

    /**
     * Get the locked channels for a specific notification type.
     *
     * @return array<string>
     */
    public static function locked(string $type): array
    {
        return static::get($type)['locked'] ?? [];
    }

    /**
     * Determine if a notification type is registered.
     */
    public static function has(string $type): bool
    {
        return isset(static::$types[$type]);
    }

    /**
     * Clear all registered notification types.
     */
    public static function flush(): void
    {
        static::$types = [];
    }
}
