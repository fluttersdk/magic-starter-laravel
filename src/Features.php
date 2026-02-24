<?php

namespace FlutterSdk\MagicStarter;

use Illuminate\Support\Facades\Config;

/**
 * Feature toggle registry for Magic Starter capabilities.
 */
class Features
{
    /**
     * Enable the teams feature.
     */
    public static function teams(array $options = []): string
    {
        return 'teams';
    }

    /**
     * Enable the profile photos feature.
     */
    public static function profilePhotos(): string
    {
        return 'profile-photos';
    }

    /**
     * Enable the session management feature.
     */
    public static function sessions(): string
    {
        return 'sessions';
    }

    /**
     * Enable the social login feature.
     */
    public static function socialLogin(): string
    {
        return 'social-login';
    }

    /**
     * Determine whether the given feature is enabled.
     */
    public static function enabled(string $feature): bool
    {
        return in_array($feature, Config::get('magic-starter.features', []), true);
    }

    /**
     * Determine whether the teams feature is enabled.
     */
    public static function hasTeamFeatures(): bool
    {
        return static::enabled(static::teams());
    }

    /**
     * Determine whether the profile photos feature is enabled.
     */
    public static function hasProfilePhotoFeatures(): bool
    {
        return static::enabled(static::profilePhotos());
    }

    /**
     * Determine whether the session management feature is enabled.
     */
    public static function hasSessionFeatures(): bool
    {
        return static::enabled(static::sessions());
    }

    /**
     * Determine whether the social login feature is enabled.
     */
    public static function hasSocialLoginFeatures(): bool
    {
        return static::enabled(static::socialLogin());
    }
}
