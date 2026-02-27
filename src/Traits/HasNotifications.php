<?php

namespace FlutterSdk\MagicStarter\Traits;

use FlutterSdk\MagicStarter\Models\NotificationSetting;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Notification management trait for user models.
 *
 * Provides notification preference management (per-type channel toggles)
 * and push notification routing (OneSignal external ID resolution).
 *
 * Add to your User model:
 *
 * ```php
 * use FlutterSdk\MagicStarter\Traits\HasNotifications;
 *
 * class User extends Authenticatable
 * {
 *     use HasNotifications;
 * }
 * ```
 */
trait HasNotifications
{
    /**
     * Get the notification settings overrides for the user.
     *
     * @return MorphMany<NotificationSetting, $this>
     */
    public function notificationSettings(): MorphMany
    {
        return $this->morphMany(NotificationSetting::class, 'notifiable');
    }

    /**
     * Determine if the user prefers to receive a notification type on a given channel.
     */
    public function prefers(string $type, string $channel): bool
    {
        // 1. If type not in registry, allow delivery by default.
        if (! NotificationPreferenceRegistry::has($type)) {
            return true;
        }

        // 2. Check DB for explicit override.
        $override = $this->notificationSettings->where('type', $type)->where('channel', $channel)->first();

        if ($override !== null) {
            return $override->is_enabled;
        }

        // 3. If no override, check if channel is enabled by default in registry.
        return in_array($channel, NotificationPreferenceRegistry::defaults($type), true);
    }

    /**
     * Get the full matrix of notification preferences across all registered types and channels.
     *
     * Returns slug-based keys for the matrix, even when the registry uses FQCN keys.
     * This ensures the API response shape is consistent regardless of the registry key format.
     *
     * @return array<string, array{label: string, channels: array<string, array{enabled: bool, locked: bool}>}>
     */
    public function notificationPreferenceMatrix(): array
    {
        $matrix = [];
        $settings = $this->notificationSettings->groupBy('type');
        $types = NotificationPreferenceRegistry::all();

        foreach ($types as $registryKey => $definition) {
            // 1. Resolve slug from the registry key (FQCN or legacy string).
            $slug = NotificationPreferenceRegistry::resolveSlug($registryKey) ?? $registryKey;

            // 2. Look up DB overrides by slug (DB stores slugs, not FQCNs).
            $typeSettings = $settings->get($slug, collect())->keyBy('channel');
            $channels = [];

            foreach ($definition['channels'] as $channelSlug) {
                $override = $typeSettings->get($channelSlug);
                $isLocked = in_array(
                    $channelSlug,
                    $definition['locked'] ?? [],
                    true,
                );

                $channels[$channelSlug] = [
                    'enabled' => $override !== null
                        ? $override->is_enabled
                        : in_array(
                            $channelSlug,
                            $definition['default'] ?? [],
                            true,
                        ),
                    'locked' => $isLocked,
                ];
            }

            // 3. Use slug as the matrix key (not FQCN) for consistent API shape.
            $matrix[$slug] = [
                'label' => $definition['label'],
                'channels' => $channels,
            ];
        }

        return $matrix;
    }

    /**
     * Route notifications for the OneSignal channel.
     *
     * Returns the external user ID that OneSignal uses to target this user.
     * The `user_` prefix is required because OneSignal blocks simple values
     * like '0', '1', '-1' as external_id.
     *
     * The format must match what the Flutter app sets when calling
     * `Notify.initializePush('user_' + user.id)`.
     *
     * Override this method in your User model if you need a different format.
     *
     * @return array<string, mixed>
     */
    public function routeNotificationForOneSignal(): array
    {
        return [
            'include_external_user_ids' => ['user_' . $this->id],
        ];
    }
}
