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
     * @return array<string, array{label: string, channels: array<string, array{enabled: bool, locked: bool}>}>
     */
    public function notificationPreferenceMatrix(): array
    {
        $matrix = [];
        $settings = $this->notificationSettings->groupBy('type');
        $types = NotificationPreferenceRegistry::all();

        foreach ($types as $typeSlug => $definition) {
            $typeSettings = $settings->get($typeSlug, collect())->keyBy('channel');
            $channels = [];

            foreach ($definition['channels'] as $channelSlug) {
                $override = $typeSettings->get($channelSlug);
                $isLocked = in_array($channelSlug, $definition['locked'] ?? [], true);

                $channels[$channelSlug] = [
                    'enabled' => $override !== null
                        ? $override->is_enabled
                        : in_array($channelSlug, $definition['default'] ?? [], true),
                    'locked' => $isLocked,
                ];
            }

            $matrix[$typeSlug] = [
                'label' => $definition['label'],
                'channels' => $channels,
            ];
        }

        return $matrix;
    }
}
