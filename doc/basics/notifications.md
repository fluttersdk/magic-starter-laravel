# Notifications

## Table of Contents

- [Introduction](#introduction)
- [List Notifications](#list-notifications)
- [Unread Count](#unread-count)
- [Mark as Read](#mark-as-read)
- [Mark All as Read](#mark-all-as-read)
- [Delete a Notification](#delete-a-notification)
- [Notification Preferences](#notification-preferences)
  - [Get Preference Matrix](#get-preference-matrix)
  - [Update Preferences](#update-preferences)
- [HasNotifications Trait](#hasnotifications-trait)
  - [prefers()](#prefers)
  - [routeNotificationForOneSignal()](#routenotificationforonesignal)
- [NotificationSetting Model](#notificationsetting-model)
- [Channel Gating via GateNotificationChannels](#channel-gating-via-gatenotificationchannels)
- [NotificationPreferenceRegistry](#notificationpreferenceregistry)

---

## <a name="introduction"></a>Introduction

The notifications module is feature-gated behind `Features::notifications()`. All routes described below are only registered when that feature is enabled.

```php
// config/magic-starter.php
'features' => [
    Features::notifications(),
],
```

The module provides two distinct concerns:

1. **Database notification management** — read, mark, and delete Laravel database notifications via `NotificationController`.
2. **Notification preference management** — per-type, per-channel opt-in/opt-out matrix via `NotificationPreferenceController`.

All routes require an authenticated user (Sanctum `auth:sanctum` middleware is applied at the route group level by `MagicStarterServiceProvider`).

---

## <a name="list-notifications"></a>List Notifications

**`GET /notifications`**

Returns a paginated list of all database notifications for the authenticated user, ordered by `created_at` descending (Laravel default).

### Query Parameters

| Parameter  | Type    | Default | Description                        |
|------------|---------|---------|------------------------------------|
| `per_page` | integer | `15`    | Number of notifications per page.  |

### Response

```json
{
    "data": [
        {
            "id": "uuid",
            "type": "App\\Notifications\\OrderShipped",
            "notifiable_type": "App\\Models\\User",
            "notifiable_id": "uuid",
            "data": { },
            "read_at": null,
            "created_at": "2025-01-01T00:00:00.000000Z",
            "updated_at": "2025-01-01T00:00:00.000000Z"
        }
    ],
    "links": { },
    "meta": { }
}
```

The response shape follows Laravel's `AnonymousResourceCollection` with standard pagination links and meta. The `data` field inside each notification item is application-defined.

> [!NOTE]
> The controller delegates to `$request->user()->notifications()->paginate($perPage)`. The `notifications()` relation is provided by Laravel's `Notifiable` trait — ensure your User model uses it.

**Controller**: `NotificationController@index`

---

## <a name="unread-count"></a>Unread Count

**`GET /notifications/unread-count`**

Returns the number of unread notifications for the authenticated user.

### Response

```json
{
    "data": {
        "count": 3
    }
}
```

**Controller**: `NotificationController@unreadCount`

---

## <a name="mark-as-read"></a>Mark as Read

**`POST /notifications/{id}/read`**

Marks a single notification as read by setting `read_at` to the current timestamp.

### Path Parameters

| Parameter | Type   | Description                             |
|-----------|--------|-----------------------------------------|
| `id`      | string | The UUID of the notification to mark.   |

The notification is scoped to the authenticated user — attempting to mark another user's notification returns `404`.

### Response

```json
{
    "data": null,
    "message": "Notification marked as read."
}
```

**Controller**: `NotificationController@markAsRead`

---

## <a name="mark-all-as-read"></a>Mark All as Read

**`POST /notifications/read-all`**

Bulk-updates all unread notifications for the authenticated user by setting `read_at = now()` in a single query.

### Response

```json
{
    "data": null,
    "message": "All notifications marked as read."
}
```

**Controller**: `NotificationController@markAllAsRead`

---

## <a name="delete-a-notification"></a>Delete a Notification

**`DELETE /notifications/{id}`**

Permanently deletes a single notification. The notification is scoped to the authenticated user — attempting to delete another user's notification returns `404`.

### Path Parameters

| Parameter | Type   | Description                          |
|-----------|--------|--------------------------------------|
| `id`      | string | The UUID of the notification to delete. |

### Response

```json
{
    "data": null,
    "message": "Notification deleted."
}
```

**Controller**: `NotificationController@destroy`

---

## <a name="notification-preferences"></a>Notification Preferences

Notification preferences allow users to opt in or out of specific notification types on specific channels. The available types and channels are declared at boot time via `NotificationPreferenceRegistry::register()`.

### <a name="get-preference-matrix"></a>Get Preference Matrix

**`GET /notification-preferences`**

Returns the full type × channel matrix for the authenticated user. Each entry reflects the effective preference — either the stored DB override or the registry default.

### Response

```json
{
    "data": {
        "order_shipped": {
            "label": "Order Shipped",
            "channels": {
                "push": { "enabled": true, "locked": false },
                "email": { "enabled": false, "locked": true }
            }
        }
    }
}
```

- `enabled` — whether the user currently has this channel on for this type.
- `locked` — whether the channel cannot be changed (the registry defined it as locked). Locked channels should be rendered as non-interactive in the UI.

**Controller**: `NotificationPreferenceController@show`

---

### <a name="update-preferences"></a>Update Preferences

**`PUT /notification-preferences`**

Supports two payload shapes: single and bulk.

#### Single update

```json
{
    "type": "order_shipped",
    "channel": "push",
    "is_enabled": false
}
```

#### Bulk update

```json
{
    "preferences": [
        { "type": "order_shipped", "channel": "push", "is_enabled": false },
        { "type": "order_shipped", "channel": "sms",  "is_enabled": true  }
    ]
}
```

The presence of the `preferences` key determines which path is taken. Each item is validated and then upserted into `notification_settings` via `NotificationSetting::updateOrCreate()`.

#### Validation rules

| Field         | Rule                                                         |
|---------------|--------------------------------------------------------------|
| `type`        | Must be registered in `NotificationPreferenceRegistry`.     |
| `channel`     | Must be a channel declared for the given type.              |
| `channel`     | Must not be a locked channel for the given type.            |
| `is_enabled`  | Required boolean.                                           |

Attempting to update a locked channel returns a `422` with an error message such as:

```
The channel 'email' is locked for type 'order_shipped' and cannot be changed.
```

### Response

Returns the updated preference matrix in the same shape as `GET /notification-preferences`.

**Controller**: `NotificationPreferenceController@update`

> [!NOTE]
> The `type` value in the request payload must match either the notification class FQCN or its resolved slug (e.g., `order_shipped` for `App\Notifications\OrderShippedNotification`). The DB always stores slugs.

---

## <a name="hasnotifications-trait"></a>HasNotifications Trait

`FlutterSdk\MagicStarter\Traits\HasNotifications`

Add to your User model to unlock notification preference management and push routing:

```php
use FlutterSdk\MagicStarter\Traits\HasNotifications;

class User extends Authenticatable
{
    use HasNotifications;
}
```

The trait provides two public methods and one relation.

### Relation: `notificationSettings()`

```php
public function notificationSettings(): MorphMany<NotificationSetting, $this>
```

Polymorphic relation returning all stored preference overrides for the user. Loaded eagerly by `NotificationPreferenceController` before building the matrix.

---

### <a name="prefers"></a>`prefers(string $type, string $channel): bool`

Determines whether the user wants to receive a given notification type on a given channel. Resolution order:

1. If `$type` is not registered in `NotificationPreferenceRegistry` → **return `true`** (allow by default).
2. Check `notificationSettings` collection for a stored override matching `$type` + `$channel` → return `$override->is_enabled`.
3. If no override exists → check whether `$channel` is in the type's `default` channels list and return accordingly.

This method is called by `GateNotificationChannels` during the `NotificationSending` event.

> [!NOTE]
> `prefers()` returns `true` for any type not in the registry. This means unregistered notification classes are always delivered, regardless of any stored settings.

---

### <a name="routenotificationforonesignal"></a>`routeNotificationForOneSignal(): array`

```php
public function routeNotificationForOneSignal(): array
{
    return [
        'include_external_user_ids' => ['user_' . $this->id],
    ];
}
```

Returns the routing payload for the OneSignal notification channel. The `user_` prefix is **required** because OneSignal rejects bare numeric strings like `'0'`, `'1'`, or `'-1'` as external IDs.

The format must match the external ID registered by the Flutter client:

```dart
Notify.initializePush('user_' + user.id);
```

To use a different format, override this method in your User model:

```php
public function routeNotificationForOneSignal(): array
{
    return [
        'include_external_user_ids' => ['app_user_' . $this->uuid],
    ];
}
```

---

## <a name="notificationsetting-model"></a>NotificationSetting Model

`FlutterSdk\MagicStarter\Models\NotificationSetting`

Stores one row per (notifiable, type, channel) combination. Only explicit overrides are stored — the absence of a row means the registry default applies.

### Columns

| Column            | Type      | Description                                       |
|-------------------|-----------|---------------------------------------------------|
| `id`              | string    | Primary key (UUID or integer per app config).     |
| `notifiable_id`   | string    | Polymorphic owner ID.                             |
| `notifiable_type` | string    | Polymorphic owner class.                          |
| `type`            | string    | Notification type slug (e.g., `order_shipped`).   |
| `channel`         | string    | Channel slug (e.g., `push`, `email`, `sms`).      |
| `is_enabled`      | boolean   | Whether the user has this channel enabled.        |
| `created_at`      | timestamp |                                                   |
| `updated_at`      | timestamp |                                                   |

### Relation

```php
public function notifiable(): MorphTo<Model, $this>
```

`ConditionallyUsesUuids` is applied, so the primary key type follows the application's UUID configuration.

---

## <a name="channel-gating-via-gatenotificationchannels"></a>Channel Gating via GateNotificationChannels

`FlutterSdk\MagicStarter\Listeners\GateNotificationChannels`

This event listener is registered automatically by `MagicStarterServiceProvider` and listens to Laravel's `Illuminate\Notifications\Events\NotificationSending` event. Laravel fires this event once per channel per notifiable — if the listener returns `false`, delivery for that channel is cancelled.

### Gate logic (in order)

1. Resolve the notification FQCN from the event.
2. If the class is not in the registry → **allow**.
3. If the notifiable does not have a `prefers()` method → **allow**.
4. Resolve the slug for the notification class.
5. Resolve the logical channel name from the driver channel name via `NotificationPreferenceRegistry::resolveLogicalChannel()`.
6. If the logical channel is not in the registry's channels list for this type → **allow** (unknown channel passes through).
7. If the channel is marked as **locked** for this type → **allow** (locked channels are always delivered).
8. Call `$notifiable->prefers($slug, $logicalChannel)` and return the result.

> [!NOTE]
> Channel aliases allow you to map driver channel class names (e.g., `NotificationChannels\OneSignal\OneSignalChannel`) to logical names (e.g., `push`). Register aliases at boot time with `NotificationPreferenceRegistry::channelAliases(['push' => OneSignalChannel::class])`.

---

## <a name="notificationpreferenceregistry"></a>NotificationPreferenceRegistry

`FlutterSdk\MagicStarter\NotificationPreferenceRegistry`

A static registry that declares which notification types are preference-managed and what channels they support. Register types in a service provider:

```php
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use App\Notifications\OrderShippedNotification;

NotificationPreferenceRegistry::register([
    OrderShippedNotification::class => [
        'label'    => 'Order Shipped',
        'channels' => ['push', 'email'],
        'default'  => ['push'],         // channels enabled by default
        'locked'   => ['email'],        // channels the user cannot change
    ],
]);
```

### Slug resolution

The registry key can be a FQCN or a plain string. The slug stored in `notification_settings.type` is derived automatically:

- Strip the `Notification` suffix from the class basename.
- Convert the remainder from `PascalCase` to `snake_case`.
- Example: `OrderShippedNotification` → `order_shipped`.

Provide an explicit `slug` key to override this:

```php
NotificationPreferenceRegistry::register([
    OrderShippedNotification::class => [
        'slug'     => 'order_shipped_v2',
        'label'    => 'Order Shipped',
        'channels' => ['push'],
        'default'  => ['push'],
        'locked'   => [],
    ],
]);
```

### Channel aliases

Map logical channel names used in the registry to the actual Laravel notification channel driver strings:

```php
NotificationPreferenceRegistry::channelAliases([
    'push' => \NotificationChannels\OneSignal\OneSignalChannel::class,
]);
```

This mapping is used by `GateNotificationChannels` to translate the driver channel name received in `NotificationSending` back to the logical name stored in the registry and in `notification_settings`.
