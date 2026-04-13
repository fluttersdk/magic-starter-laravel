# Models Architecture

This document describes the model layer of the `magic-starter-laravel` package: how models are resolved at runtime, how primary key types are toggled between UUID and integer, and the purpose and structure of each built-in model and user-model trait.

## Table of Contents

- [Introduction](#introduction)
- [MagicStarter Registry](#magicstarter-registry)
  - [Resolution Priority](#resolution-priority)
  - [App\\Models Fallback](#appmodels-fallback)
  - [Runtime Overrides](#runtime-overrides)
- [ConditionallyUsesUuids Trait](#conditionallyusesuuids-trait)
- [MigrationHelper](#migrationhelper)
  - [primaryKey](#primarykey)
  - [foreignKey](#foreignkey)
  - [morphColumns](#morphcolumns)
- [Package Models](#package-models)
  - [Team](#team)
  - [TeamUser (Pivot)](#teamuser-pivot)
  - [TeamInvitation](#teaminvitation)
  - [NotificationSetting](#notificationsetting)
  - [NewsletterSubscriber](#newslettersubscriber)
  - [PersonalAccessToken](#personalaccesstoken)
- [User Traits](#user-traits)
  - [HasTeams](#hasteams)
  - [HasProfilePhoto](#hasprofilephoto)
  - [HasNotifications](#hasnotifications)
  - [TwoFactorAuthenticatable](#twofactorauthenticatable)
  - [HasGuestSupport](#hasguestsupport)
  - [MustVerifyEmail](#mustverifyemail)
- [Relationship Typing Conventions](#relationship-typing-conventions)
- [PHPDoc Conventions](#phpdoc-conventions)

---

## <a name="introduction"></a>Introduction

The package never hardcodes `App\Models\*` class names anywhere in its own code. All model references go through the `MagicStarter` static registry, which resolves the correct class at runtime based on configuration, consumer overrides, and auto-published stubs.

Primary key type (UUID vs. auto-incrementing integer) is a single config flag (`magic-starter.use_uuids`, default `true`). The `ConditionallyUsesUuids` trait and `MigrationHelper` class both read the same flag, ensuring that the schema and Eloquent behavior stay in sync without any model-level changes.

---

## <a name="magicstarter-registry"></a>MagicStarter Registry

`FlutterSdk\MagicStarter\MagicStarter` is a static service-locator for model class names. It exposes four public resolution methods:

| Method | Config key | Fallback |
|---|---|---|
| `userModel()` | `magic-starter.models.user` | `auth.providers.users.model` |
| `teamModel()` | `magic-starter.models.team` | — |
| `membershipModel()` | `magic-starter.models.membership` | — |
| `teamInvitationModel()` | `magic-starter.models.team_invitation` | — |

All four throw `RuntimeException` when the resolved value is `null` or an empty string.

### <a name="resolution-priority"></a>Resolution Priority

For each resolver the lookup order is:

1. Runtime override registered via `MagicStarter::use*Model(string $class)`.
2. `config('magic-starter.models.*')` value.
3. Fallback config (user only: `auth.providers.users.model`).

### <a name="appmodels-fallback"></a>App\\Models Fallback

For `teamModel()`, `membershipModel()`, and `teamInvitationModel()`, after the config value is obtained it is passed through `resolveConcreteModel()`. This method checks an internal map:

```php
protected static array $appModelOverrides = [
    Team::class           => 'App\\Models\\Team',
    TeamUser::class       => 'App\\Models\\TeamUser',
    TeamInvitation::class => 'App\\Models\\TeamInvitation',
];
```

When the configured class is one of the three built-in package models, `class_exists()` is called on the corresponding `App\Models\*` equivalent. If the consumer has published and extended the stub, that class is returned instead. The call is wrapped in `try/catch (Throwable)` to survive stale Composer classmaps that reference a deleted file.

> [!NOTE]
> `userModel()` does **not** go through `resolveConcreteModel()`. The user model is always whatever the consumer specifies — the package ships no built-in `User` model.

### <a name="runtime-overrides"></a>Runtime Overrides

Overrides are stored in the static `$using` array and take precedence over everything else. They are used in tests to inject fixture models:

```php
MagicStarter::useUserModel(ConcreteUser::class);
MagicStarter::useTeamModel(ConcreteTeam::class);
MagicStarter::useMembershipModel(ConcreteTeamUser::class);
MagicStarter::useTeamInvitationModel(ConcreteTeamInvitation::class);
```

Call `MagicStarter::reset()` in `tearDown()` to clear all overrides and the `ignoreRoutes` flag.

---

## <a name="conditionallyusesuuids-trait"></a>ConditionallyUsesUuids Trait

`FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids` is applied to every package model. It conditionally activates UUID primary key behavior based on `MigrationHelper::usesUuids()`.

**Boot phase** (`bootConditionallyUsesUuids`): when UUIDs are enabled, registers a `creating` observer that populates the primary key with an ordered UUID if it is empty. This replicates the core behavior of Laravel's `HasUuids` trait without statically `use`-ing it (which cannot be done conditionally).

**Initialization phase** (`initializeConditionallyUsesUuids`): runs on every model instantiation. Sets `$incrementing = false` and `$keyType = 'string'` when UUIDs are enabled.

**`uniqueIds()` method**: returns `[$this->getKeyName()]` when UUIDs are enabled, or an empty array otherwise. This is the hook used by `HasUuids` to discover which columns receive auto-generated UUIDs.

```php
// Integer mode (use_uuids = false):
//   $incrementing = true, $keyType = 'int', no UUID generation

// UUID mode (use_uuids = true):
//   $incrementing = false, $keyType = 'string', ordered UUID on creating
```

> [!NOTE]
> The `ConditionallyUsesUuids` trait must be present on every model — including pivot models extending `Pivot`. It is **not** applied automatically; it must be explicitly declared with `use ConditionallyUsesUuids;` in each model class.

---

## <a name="migrationhelper"></a>MigrationHelper

`FlutterSdk\MagicStarter\Support\MigrationHelper` centralizes the UUID/integer switch for all package migrations. The single source of truth is:

```php
public static function usesUuids(): bool
{
    return (bool) config('magic-starter.use_uuids', true);
}
```

All three helper methods delegate to this flag.

### <a name="primarykey"></a>primaryKey

```php
MigrationHelper::primaryKey(Blueprint $table): ColumnDefinition
```

- UUID mode: `$table->uuid('id')->primary()`
- Integer mode: `$table->id()`

Never use raw `$table->id()` or `$table->uuid('id')` directly in package migrations.

### <a name="foreignkey"></a>foreignKey

```php
MigrationHelper::foreignKey(Blueprint $table, string $column): ForeignIdColumnDefinition
```

- UUID mode: `$table->foreignUuid($column)`
- Integer mode: `$table->foreignId($column)`

Chain `.constrained()->cascadeOnDelete()` after the call to complete the foreign key definition:

```php
MigrationHelper::foreignKey($table, 'user_id')->constrained()->cascadeOnDelete();
```

### <a name="morphcolumns"></a>morphColumns

```php
MigrationHelper::morphColumns(Blueprint $table, string $name): void
```

- UUID mode: `$table->uuidMorphs($name)` — generates `{name}_type VARCHAR` and `{name}_id CHAR(36)`
- Integer mode: `$table->morphs($name)` — generates `{name}_type VARCHAR` and `{name}_id BIGINT UNSIGNED`

Used for polymorphic relations such as `notifiable` on `notification_settings` and `tokenable` on `personal_access_tokens`.

---

## <a name="package-models"></a>Package Models

All package models live in `FlutterSdk\MagicStarter\Models\`. Every model uses `ConditionallyUsesUuids` and the Laravel 11+ `casts()` method syntax (not the `$casts` property).

### <a name="team"></a>Team

`FlutterSdk\MagicStarter\Models\Team`

Represents a team (workspace). Teams can be personal (created automatically for each user) or shared.

**Columns**

| Column | Type | Notes |
|---|---|---|
| `id` | uuid / bigint | PK via `ConditionallyUsesUuids` |
| `user_id` | uuid / bigint | FK → owner user |
| `name` | string | Team display name |
| `personal_team` | boolean | `true` for auto-created personal workspace |
| `profile_photo_path` | string\|null | Stored path; URL resolved via accessor |
| `created_at`, `updated_at` | timestamp | |

**Accessor**: `profile_photo_url` (appended) — reads from the configured storage disk (`magic-starter.profile_photo_disk`, falling back to `filesystems.default`). Falls back to a ui-avatars.com URL built from name initials when no photo is stored.

**Relations**

| Method | Type | Notes |
|---|---|---|
| `owner()` | `BelongsTo<Model, $this>` | Resolves via `MagicStarter::userModel()` |
| `users()` | `BelongsToMany<Model, $this>` | `team_user` pivot, uses `membershipModel()`, exposes `role` pivot column |
| `invitations()` | `HasMany<TeamInvitation, $this>` | Resolves via `MagicStarter::teamInvitationModel()` |

### <a name="teamuser-pivot"></a>TeamUser (Pivot)

`FlutterSdk\MagicStarter\Models\TeamUser`

Pivot model for the `team_user` join table. Extends `Illuminate\Database\Eloquent\Relations\Pivot` (not `Model`).

**Columns**

| Column | Type | Notes |
|---|---|---|
| `id` | uuid / bigint | PK |
| `team_id` | uuid / bigint | FK → teams |
| `user_id` | uuid / bigint | FK → users |
| `role` | string\|null | Member role string |
| `created_at`, `updated_at` | timestamp | |

> [!NOTE]
> Always pass `->using(MagicStarter::membershipModel())` when defining `belongsToMany` relations involving this pivot so that role data is available via `$model->pivot->role`.

### <a name="teaminvitation"></a>TeamInvitation

`FlutterSdk\MagicStarter\Models\TeamInvitation`

Stores pending invitations to join a team, identified by a unique token sent to the invitee's email address.

**Columns**

| Column | Type | Notes |
|---|---|---|
| `id` | uuid / bigint | PK |
| `team_id` | uuid / bigint | FK → teams (not fillable; set on creation) |
| `email` | string | Invitee email address |
| `role` | string | Role to assign on acceptance |
| `token` | string | Unique invitation token |
| `expires_at` | datetime\|null | Nullable; no expiry when `null` |
| `created_at` | timestamp | No `updated_at` column |

**Methods**

- `isExpired(): bool` — returns `true` when `expires_at` is set and is in the past.
- `scopeValid(Builder $query)` — filters to non-expired invitations (`expires_at IS NULL OR expires_at > NOW()`).

**Relations**

| Method | Type |
|---|---|
| `team()` | `BelongsTo<Team, $this>` via `MagicStarter::teamModel()` |

### <a name="notificationsetting"></a>NotificationSetting

`FlutterSdk\MagicStarter\Models\NotificationSetting`

Stores per-user overrides for notification delivery preferences. Each row represents a single `(user, notification_type, channel)` combination.

**Columns**

| Column | Type | Notes |
|---|---|---|
| `id` | uuid / bigint | PK |
| `notifiable_id` | uuid / bigint | Polymorphic FK |
| `notifiable_type` | string | Polymorphic type |
| `type` | string | Notification slug |
| `channel` | string | Delivery channel (e.g. `push`, `email`) |
| `is_enabled` | boolean | Whether the channel is active |
| `created_at`, `updated_at` | timestamp | |

**Relations**

| Method | Type |
|---|---|
| `notifiable()` | `MorphTo<Model, $this>` |

### <a name="newslettersubscriber"></a>NewsletterSubscriber

`FlutterSdk\MagicStarter\Models\NewsletterSubscriber`

Tracks email newsletter opt-ins. Created with `firstOrCreate()` in the newsletter action for idempotency.

**Columns**

| Column | Type | Notes |
|---|---|---|
| `id` | uuid / bigint | PK |
| `email` | string | Subscriber email (unique enforced at DB level) |
| `is_active` | boolean | Subscription status |
| `source` | string\|null | Optional attribution source |
| `created_at`, `updated_at` | timestamp | |

### <a name="personalaccesstoken"></a>PersonalAccessToken

`FlutterSdk\MagicStarter\Models\PersonalAccessToken`

Extends `Laravel\Sanctum\PersonalAccessToken` with device-tracking columns. This model is registered as the Sanctum token model in the service provider, replacing the default Sanctum implementation.

> [!NOTE]
> Sanctum's own migrations are disabled by the package. The package-provided migration adds `ip_address` and `user_agent` columns on top of the standard Sanctum schema.

**Additional columns** (beyond standard Sanctum fields)

| Column | Type | Notes |
|---|---|---|
| `ip_address` | string\|null | Client IP at token creation |
| `user_agent` | string\|null | Client user-agent string |

All standard Sanctum columns (`tokenable_type`, `tokenable_id`, `name`, `token`, `abilities`, `last_used_at`, `expires_at`) are inherited.

Uses `ConditionallyUsesUuids` — the `tokenable_id` column type is kept in sync with the user PK type via `MigrationHelper::morphColumns($table, 'tokenable')`.

---

## <a name="user-traits"></a>User Traits

These traits are mixed into the consumer's `User` model. They are all located in `FlutterSdk\MagicStarter\Traits\`.

### <a name="hasteams"></a>HasTeams

Adds team-membership relationships and helper methods.

**Relations**

| Method | Return type | Notes |
|---|---|---|
| `ownedTeams()` | `HasMany<Model, $this>` | Teams where `user_id = this.id` |
| `teams()` | `BelongsToMany<Model, $this>` | Via `team_user` pivot; uses `membershipModel()`, exposes `role` |
| `currentTeam()` | `BelongsTo<Model, $this>` | Resolved from `current_team_id` column |

**Helper methods**

| Method | Returns | Notes |
|---|---|---|
| `personalTeam()` | `Model\|null` | First owned team with `personal_team = true` |
| `allTeams()` | `Collection<int, Model>` | Merge of `ownedTeams` + `teams`, sorted by name |
| `getCurrentTeamOrPersonal()` | `Model\|null` | `currentTeam ?? personalTeam()` |
| `belongsToTeam(Model $team)` | `bool` | Checks both owned and member teams |
| `ownsTeam(Model $team)` | `bool` | Compares `user_id` on the team with this user's PK |
| `hasTeamRole(Model $team, string $role)` | `bool` | Checks pivot `role` on member teams; does not cover owners |

### <a name="hasprofilephoto"></a>HasProfilePhoto

Provides the `profile_photo_url` Eloquent accessor.

**Accessor**: `getProfilePhotoUrlAttribute(): string`

Resolution order:
1. If `profile_photo_path` is non-empty: resolves the URL via the `magic-starter.profile_photo_disk` filesystem disk (falling back to `filesystems.default`).
2. Otherwise: calls `defaultProfilePhotoUrl()`, which generates a ui-avatars.com URL from name initials. The background color is `#009E60` and the text color is `#FFFFFF`.

> [!NOTE]
> The `Team` model implements an equivalent `profilePhotoUrl` Attribute directly (not via this trait) with slightly different default colors (`#EBF4FF` / `#7F9CF5`). The trait is for user models only.

### <a name="hasnotifications"></a>HasNotifications

Manages per-user notification preferences and OneSignal routing.

**Relation**: `notificationSettings(): MorphMany<NotificationSetting, $this>`

**Core method**: `prefers(string $type, string $channel): bool`

Resolution order:
1. If `$type` is not in the `NotificationPreferenceRegistry`, returns `true` (allow delivery by default).
2. Looks for a DB override in `notificationSettings` for the `(type, channel)` pair; returns `is_enabled` if found.
3. Falls back to the registry's default channels for the type.

**`notificationPreferenceMatrix(): array`** — returns the full preference state for all registered notification types. DB overrides use slug-based keys (not FQCNs) to ensure a stable API response shape regardless of how types were registered.

**`routeNotificationForOneSignal(): array`**: returns `['external_id' => ['user_' . $this->getKey()]]` for v5 SDK alias-based routing. The payload is passed to `FlutterSdk\MagicStarter\Notifications\Channels\OneSignalChannel`, which applies the aliases to the notification via `setIncludeAliases()`. The `user_` prefix is required because OneSignal rejects simple numeric values such as `0` or `1` as external IDs. The format must match `Notify.initializePush('user_' + user.id)` in the Flutter app.

### <a name="twofactorauthenticatable"></a>TwoFactorAuthenticatable

Adds TOTP-based two-factor authentication to the user model.

**Expected columns on the user table**

| Column | Type |
|---|---|
| `two_factor_secret` | text\|null (encrypted) |
| `two_factor_recovery_codes` | text\|null (encrypted JSON array) |
| `two_factor_confirmed_at` | timestamp\|null |

**Methods**

| Method | Returns | Notes |
|---|---|---|
| `hasEnabledTwoFactorAuthentication()` | `bool` | `true` when `two_factor_confirmed_at` is not null |
| `twoFactorSecret()` | `string\|null` | Decrypts `two_factor_secret`; returns `null` when not set |
| `recoveryCodes()` | `array<int, string>` | Decrypts and JSON-decodes `two_factor_recovery_codes` |
| `twoFactorRecoveryCodesCount()` | `int` | Count of remaining recovery codes |
| `replaceRecoveryCode(string $code)` | `void` | Swaps the given code for a new `random(10)-random(10)` string and saves |
| `twoFactorQrCodeUrl()` | `string` | TOTP provisioning URI via `TwoFactorAuthenticationProvider` |
| `twoFactorQrCodeSvg()` | `string` | 192×192 SVG QR code rendered by `bacon/bacon-qr-code` |

The company name in the QR code provisioning URI is read from `magic-starter.two_factor.company_name`, falling back to `app.name`.

### <a name="hasguestsupport"></a>HasGuestSupport

Provides identity helpers for models that support unauthenticated (guest) sessions.

> [!NOTE]
> Guest users may have `null` email **and** `null` password. All code paths that read these fields must guard with null checks.

**Methods**

| Method | Returns | Notes |
|---|---|---|
| `isGuest()` | `bool` | Casts `is_guest` column to boolean |
| `isRegistered()` | `bool` | Not a guest, and has either `(email + password)` or `(phone + password)` |

### <a name="mustverifyemail"></a>MustVerifyEmail

Overrides Laravel's default `MustVerifyEmail` contract implementation with guest-awareness.

**`hasVerifiedEmail(): bool`** — guests are treated as verified (they have no email address, so the `verified` middleware must not block them). Internally calls `method_exists($this, 'isGuest')` before using `isGuest()` to avoid coupling to `HasGuestSupport`.

**`sendEmailVerificationNotification(): void`** — dispatches `VerifyEmailNotification` (the package's own notification class, not Laravel's default).

---

## <a name="relationship-typing-conventions"></a>Relationship Typing Conventions

All relationship return types use the generic syntax introduced in Laravel 11 / PHPStan-aware stubs:

```php
// BelongsTo
public function owner(): BelongsTo  // typed as BelongsTo<Model, $this>

// HasMany
public function invitations(): HasMany  // typed as HasMany<TeamInvitation, $this>

// BelongsToMany (always include pivot setup)
public function users(): BelongsToMany  // BelongsToMany<Model, $this>
    // ...
    ->using(MagicStarter::membershipModel())
    ->withPivot('role')
    ->withTimestamps();

// MorphMany
public function notificationSettings(): MorphMany  // MorphMany<NotificationSetting, $this>

// MorphTo
public function notifiable(): MorphTo  // MorphTo<Model, $this>
```

The concrete type parameter for user-model relations is `Model` (not a specific class) because the actual class is resolved dynamically at runtime via `MagicStarter::userModel()`.

---

## <a name="phpdoc-conventions"></a>PHPDoc Conventions

Every model class carries PHPDoc block annotations above the class declaration:

```php
/**
 * @property string      $id               Primary key (UUID or bigint as string)
 * @property string      $user_id          Foreign key
 * @property string      $name
 * @property bool        $personal_team
 * @property string|null $profile_photo_path
 * @property-read string $profile_photo_url Computed accessor
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model            $owner        Relation accessor
 * @property-read Collection<...>  $users        Relation collection
 */
```

Key rules:
- Use `@property` for every database column.
- Use `@property-read` for Eloquent accessors and loaded relation results.
- Type the `id` column as `string` even in integer mode — Eloquent casts it to string in PHP; document the PHP-visible type, not the SQL type.
- Use `Carbon|null` for nullable timestamp columns, not `string`.
- Collections are typed with their item type: `Collection<int, TeamInvitation>`.
