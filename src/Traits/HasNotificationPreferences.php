<?php

namespace FlutterSdk\MagicStarter\Traits;

use FlutterSdk\MagicStarter\Models\NotificationSetting;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasNotificationPreferences
{
    /**
     * Get the notification settings overrides for the user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<\FlutterSdk\MagicStarter\Models\NotificationSetting, $this>
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
}
