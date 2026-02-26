<?php

namespace FlutterSdk\MagicStarter\Listeners;

use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use Illuminate\Notifications\Events\NotificationSending;

/**
 * Automatically gates notification channels based on user preferences
 * registered in the NotificationPreferenceRegistry.
 *
 * Listens to NotificationSending events (fired per-channel, per-notifiable)
 * and returns false to cancel delivery when the user has disabled that channel.
 */
class GateNotificationChannels
{
    /**
     * Handle the NotificationSending event.
     *
     * Returns false to cancel the notification for this specific channel,
     * or true to allow delivery.
     *
     * @param  NotificationSending  $event
     * @return bool
     */
    public function handle(NotificationSending $event): bool
    {
        // 1. Resolve notification FQCN from the event.
        $notificationClass = get_class($event->notification);

        // 2. If notification class is not registered in the registry, allow delivery.
        if (! NotificationPreferenceRegistry::has($notificationClass)) {
            return true;
        }

        // 3. If the notifiable does not have the prefers() method, allow delivery.
        if (! method_exists($event->notifiable, 'prefers')) {
            return true;
        }

        // 4. Resolve the slug for this notification class.
        $slug = NotificationPreferenceRegistry::resolveSlug($notificationClass);

        if ($slug === null) {
            return true;
        }

        // 5. Resolve the logical channel name from the driver channel.
        $logicalChannel = NotificationPreferenceRegistry::resolveLogicalChannel($event->channel);

        // 6. If this logical channel is not in the registry's channels list, allow (unknown channel).
        $registeredChannels = NotificationPreferenceRegistry::channels($notificationClass);

        if (! in_array($logicalChannel, $registeredChannels, true)) {
            return true;
        }

        // 7. If channel is locked, always allow delivery.
        $lockedChannels = NotificationPreferenceRegistry::locked($notificationClass);

        if (in_array($logicalChannel, $lockedChannels, true)) {
            return true;
        }

        // 8. Check user preferences — this is the gate.
        return $event->notifiable->prefers(
            $slug,
            $logicalChannel,
        );
    }
}
