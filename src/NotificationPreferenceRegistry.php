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
     * @var array<string, array{slug?: string, label: string, channels: array<string>, default: array<string>, locked: array<string>}>
     */
    protected static array $types = [];

    /**
     * Global logical to driver channel mapping.
     *
     * @var array<string, string>
     */
    protected static array $channelAliases = [];

    /**
     * Register global logical to driver channel mapping.
     *
     * @param  array<string, string>  $aliases
     */
    public static function channelAliases(array $aliases): void
    {
        static::$channelAliases = array_merge(static::$channelAliases, $aliases);
    }

    /**
     * Resolve the slug for a notification class.
     */
    public static function resolveSlug(string $notificationClass): ?string
    {
        if (! static::has($notificationClass)) {
            return null;
        }

        $type = static::get($notificationClass);

        if (isset($type['slug'])) {
            return $type['slug'];
        }

        // Auto-derive: take class basename, strip Notification suffix, convert to snake_case.
        $basename = class_basename($notificationClass);
        $withoutSuffix = preg_replace('/Notification$/', '', $basename);
        $slug = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $withoutSuffix));

        return $slug;
    }

    /**
     * Reverse-map driver channel to logical name.
     */
    public static function resolveLogicalChannel(string $driverChannel): string
    {
        $alias = array_search($driverChannel, static::$channelAliases, true);

        return $alias !== false ? (string) $alias : $driverChannel;
    }

    /**
     * Forward-map logical name to driver channel.
     */
    public static function resolveDriverChannel(string $logicalChannel): string
    {
        return static::$channelAliases[$logicalChannel] ?? $logicalChannel;
    }

    /**
     * Get all driver-mapped channels for a notification class.
     *
     * @return array<string>
     */
    public static function driverChannelsFor(string $notificationClass): array
    {
        return array_map(
            fn (string $channel) => static::resolveDriverChannel($channel),
            static::channels($notificationClass),
        );
    }

    /**
     * Get all registered channel aliases.
     *
     * @return array<string, string>
     */
    public static function getChannelAliases(): array
    {
        return static::$channelAliases;
    }

    /**
     * Find a registered type by its slug.
     *
     * Searches through all registered types and returns the first one whose
     * resolved slug matches the given slug. Returns null if not found.
     *
     * @return array{slug?: string, label: string, channels: array<string>, default: array<string>, locked: array<string>}|null
     */
    public static function findBySlug(string $slug): ?array
    {
        foreach (static::$types as $key => $definition) {
            $resolved = static::resolveSlug($key);

            if ($resolved === $slug) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Resolve a registry key (FQCN) from a slug.
     *
     * Returns the FQCN key whose resolved slug matches, or null if not found.
     */
    public static function resolveKeyFromSlug(string $slug): ?string
    {
        foreach (static::$types as $key => $definition) {
            $resolved = static::resolveSlug($key);

            if ($resolved === $slug) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Register new notification types.
     *
     * @param  array<string, array{slug?: string, label: string, channels: array<string>, default: array<string>, locked: array<string>}>  $types
     */
    public static function register(array $types): void
    {
        static::$types = array_merge(static::$types, $types);
    }

    /**
     * Get a registered notification type by its key (FQCN) or slug.
     *
     * First checks for a direct key match, then falls back to slug-based lookup.
     *
     * @return array{slug?: string, label: string, channels: array<string>, default: array<string>, locked: array<string>}|null
     */
    public static function get(string $type): ?array
    {
        // 1. Direct key match (FQCN or legacy string key).
        if (isset(static::$types[$type])) {
            return static::$types[$type];
        }

        // 2. Slug-based fallback lookup.
        return static::findBySlug($type);
    }

    /**
     * Get all registered notification types.
     *
     * @return array<string, array{slug?: string, label: string, channels: array<string>, default: array<string>, locked: array<string>}>
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
     * Determine if a notification type is registered (by key or slug).
     */
    public static function has(string $type): bool
    {
        return static::get($type) !== null;
    }

    /**
     * Clear all registered notification types.
     */
    public static function flush(): void
    {
        static::$types = [];
        static::$channelAliases = [];
    }
}
