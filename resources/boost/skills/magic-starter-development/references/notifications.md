# Notification Preferences

## Where to Find It

- src/NotificationPreferenceRegistry.php — type registration, channel aliases, slug resolution
- src/Traits/HasNotifications.php — prefers() method, notificationPreferenceMatrix(), OneSignal routing
- src/Listeners/GateNotificationChannels.php — NotificationSending listener, channel gating logic
- src/Models/NotificationSetting.php — per-user/notifiable preference overrides
- config/magic-starter.php — feature flag for notifications

## What to Watch For

### NotificationPreferenceRegistry centralizes type and channel definitions

- Register notification types in AppServiceProvider boot(): NotificationPreferenceRegistry::register(['ClassName' => ['label' => '...', 'channels' => ['email', 'push'], 'default' => ['email'], 'locked' => []]]).
- Channel aliases map logical names (e.g. 'push') to driver classes via NotificationPreferenceRegistry::channelAliases(['push' => OneSignalChannel::class]).
- Slugs auto-derive from class basename with Notification suffix stripped and converted to snake_case; override via 'slug' key in registration.

### GateNotificationChannels listener fires on every NotificationSending event

- Returns false to cancel delivery for a specific channel when user.prefers() returns false.
- Checks registry has(notificationClass); if unregistered, allows delivery by default.
- Skips gating if notifiable lacks prefers() method or if channel is locked — locked channels always deliver regardless of user preference.
- Resolves logical channel name from driver via resolveLogicalChannel() to handle aliased channels.

### HasNotifications trait prefers() uses 3-step fallback

- Step 1: if type not registered, return true (allow delivery).
- Step 2: check NotificationSetting table for explicit DB override (type + channel); if found, return override.is_enabled.
- Step 3: if no override, check registry defaults for this type + channel; return true if channel is in defaults array, false otherwise.

### notificationPreferenceMatrix() builds the settings UI payload

- Returns nested array: type slug → label + channels → [enabled (bool), locked (bool)].
- Enabled state respects DB overrides; falls back to registry default if no override.
- Locked channels cannot be toggled (UI should disable them); locked() call on registry returns list of unchangeable channels per type.

### OneSignal routing uses alias-based targeting with v5 SDK

- routeNotificationForOneSignal() returns ['external_id' => ['user_' . $this->getKey()]] for OneSignal v5 alias targeting.
- The prefix is required because OneSignal rejects bare numeric IDs as external_id; must match app-side call Notify.initializePush('user_' + user.id).
- Channel driver is FlutterSdk\MagicStarter\Notifications\Channels\OneSignalChannel; notification returned map is passed to \onesignal\client\model\Notification::setIncludeAliases().

### Consumer pattern: register types, let GateNotificationChannels filter

- Define custom notification classes that extend Notification and fire on NotificationSending.
- Register them in AppServiceProvider with prefers()-aware channels.
- GateNotificationChannels automatically gates delivery; UI uses notificationPreferenceMatrix() to build preference toggles.
