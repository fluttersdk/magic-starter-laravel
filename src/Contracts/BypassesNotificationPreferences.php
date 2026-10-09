<?php

namespace FlutterSdk\MagicStarter\Contracts;

/**
 * Contract for a notification that delivers a channel regardless of the notifiable's preference.
 *
 * GateNotificationChannels asks it per channel, per notifiable, at NotificationSending time. The notification
 * instance is shared across recipients, so the decision belongs to the notification (keyed by notifiable), not
 * to the gate.
 */
interface BypassesNotificationPreferences
{
    /**
     * Whether the notification asks the gate to deliver this channel to this notifiable even though the person
     * disabled it. The app owns the decision, for example a fallback when a paid channel is unavailable.
     *
     * @param  object  $notifiable  The recipient the channel is about to deliver to.
     * @param  string  $logicalChannel  The registry channel name (for example `push`), not the driver class.
     */
    public function bypassesPreference(object $notifiable, string $logicalChannel): bool;
}
