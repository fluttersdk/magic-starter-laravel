# Configuration

- [Introduction](#introduction)
- [Publishing the Config File](#publishing-the-config-file)
- [Feature Toggles](#feature-toggles)
- [Programmatic Feature Checking](#programmatic-feature-checking)
- [All Config Keys](#all-config-keys)
- [Authentication Identity](#authentication-identity)
- [Route Prefix](#route-prefix)
- [Storage Disks](#storage-disks)
- [UUID Configuration](#uuid-configuration)
- [Model Resolution](#model-resolution)
- [Two-Factor Authentication Settings](#two-factor-authentication-settings)
- [Environment Variables](#environment-variables)

<a name="introduction"></a>
## Introduction

Magic Starter is configured via a single file at `config/magic-starter.php`. This file controls feature flags, model resolution, authentication identity strategy, storage disks, route prefixes, UUID behavior, and two-factor authentication settings.

All configuration keys support environment variable overrides where noted, making it straightforward to manage per-environment settings without modifying the config file directly.

> [!TIP]
> For a full walkthrough of installation and initial setup, see the [Installation Guide](https://wind.fluttersdk.com/packages/starter-laravel/getting-started/installation).

<a name="publishing-the-config-file"></a>
## Publishing the Config File

If you used the install command, the config file is already published. To publish it manually:

```bash
php artisan vendor:publish --tag=magic-starter-config
```

This copies the package config to `config/magic-starter.php` in your application.

<a name="feature-toggles"></a>
## Feature Toggles

Features follow a toggle pattern inspired by Jetstream. Enable features by adding their corresponding `Features::` method call to the `features` array in `config/magic-starter.php`:

```php
'features' => [
    \FlutterSdk\MagicStarter\Features::teams(),
    \FlutterSdk\MagicStarter\Features::profilePhotos(),
    \FlutterSdk\MagicStarter\Features::sessions(),
    \FlutterSdk\MagicStarter\Features::socialLogin(),
    \FlutterSdk\MagicStarter\Features::newsletterSubscription(),
    \FlutterSdk\MagicStarter\Features::extendedProfile(),
    \FlutterSdk\MagicStarter\Features::notifications(),
    \FlutterSdk\MagicStarter\Features::twoFactorAuthentication(),
    \FlutterSdk\MagicStarter\Features::emailVerification(),
    \FlutterSdk\MagicStarter\Features::guestAuth(),
    \FlutterSdk\MagicStarter\Features::phoneOtp(),
    \FlutterSdk\MagicStarter\Features::timezones(),
],
```

When a feature is disabled (omitted from the array), its routes are not registered and its functionality is unavailable. Core authentication and profile management routes are always active regardless of feature flags.

The 12 features and their toggle methods:

| Feature Key | Enable Method | Check Method | Description |
|:------------|:--------------|:-------------|:------------|
| `teams` | `Features::teams()` | `Features::hasTeamFeatures()` | Team creation, membership, invitations, and roles |
| `profile-photos` | `Features::profilePhotos()` | `Features::hasProfilePhotoFeatures()` | User profile photo upload and management |
| `sessions` | `Features::sessions()` | `Features::hasSessionFeatures()` | Active session listing and revocation |
| `social-login` | `Features::socialLogin()` | `Features::hasSocialLoginFeatures()` | OAuth-based social login via Socialite |
| `newsletter-subscription` | `Features::newsletterSubscription()` | `Features::hasNewsletterSubscriptionFeatures()` | Newsletter opt-in subscription management |
| `extended-profile` | `Features::extendedProfile()` | `Features::hasExtendedProfileFeatures()` | Extended profile fields (phone, timezone, language, locale) |
| `notifications` | `Features::notifications()` | `Features::hasNotificationFeatures()` | Notification preference management |
| `two-factor-authentication` | `Features::twoFactorAuthentication()` | `Features::hasTwoFactorAuthenticationFeatures()` | TOTP-based two-factor authentication with recovery codes |
| `email-verification` | `Features::emailVerification()` | `Features::hasEmailVerificationFeatures()` | Email verification flow after registration |
| `guest-auth` | `Features::guestAuth()` | `Features::hasGuestAuthFeatures()` | Anonymous guest user authentication |
| `phone-otp` | `Features::phoneOtp()` | `Features::hasPhoneOtpFeatures()` | Phone-based OTP verification flow |
| `timezones` | `Features::timezones()` | `Features::hasTimezoneFeatures()` | Timezone detection and per-user timezone storage |

<a name="programmatic-feature-checking"></a>
## Programmatic Feature Checking

Use the `Features` class to check feature status at runtime in your application code, controllers, or service providers:

```php
use FlutterSdk\MagicStarter\Features;

// Generic check by feature key string
Features::enabled('teams');                          // bool

// Dedicated convenience methods
Features::hasTeamFeatures();                         // bool
Features::hasProfilePhotoFeatures();                 // bool
Features::hasSessionFeatures();                      // bool
Features::hasSocialLoginFeatures();                  // bool
Features::hasNewsletterSubscriptionFeatures();       // bool
Features::hasExtendedProfileFeatures();              // bool
Features::hasNotificationFeatures();                 // bool
Features::hasTwoFactorAuthenticationFeatures();      // bool
Features::hasEmailVerificationFeatures();            // bool
Features::hasGuestAuthFeatures();                    // bool
Features::hasPhoneOtpFeatures();                     // bool
Features::hasTimezoneFeatures();                     // bool

// Composite check — true when either timezones or extended profile is enabled
Features::hasTimezoneOrExtendedProfileFeatures();    // bool

// Authentication identity checks
Features::emailIdentity();                           // bool
Features::phoneIdentity();                           // bool
```

> [!TIP]
> Use the dedicated `has*Features()` methods in conditional logic rather than `enabled()` with raw strings. They are type-safe, IDE-friendly, and survive feature key renames.

<a name="all-config-keys"></a>
## All Config Keys

Every key available in `config/magic-starter.php`:

| Key | Default | Description |
|:----|:--------|:------------|
| `use_uuids` | `true` | When `true`, all package migrations use UUID primary keys; when `false`, auto-incrementing integers |
| `features` | `[]` | Array of enabled feature strings (populated via `Features::` method calls) |
| `frontend_url` | `env('MAGIC_STARTER_FRONTEND_URL')` | Base URL for the frontend application, used in email invitation links |
| `models.user` | `env('MAGIC_STARTER_USER_MODEL')` | Custom User model class; falls back to `auth.providers.users.model` via `MagicStarter::userModel()` |
| `models.team` | `Team::class` | Team model class |
| `models.membership` | `TeamUser::class` | Pivot model for team membership |
| `models.team_invitation` | `TeamInvitation::class` | Invitation model class |
| `defaults.locale` | `'en'` | Default locale assigned to new users during registration |
| `defaults.timezone` | `'UTC'` | Default timezone assigned to new users during registration |
| `supported_locales` | `['en', 'tr']` | Locale codes accepted by validation rules during registration and profile updates |
| `profile_photo_disk` | `'public'` | Laravel filesystem disk for user profile photos |
| `team_photo_disk` | `'public'` | Laravel filesystem disk for team photos (defaults to `profile_photo_disk` value) |
| `profile_photo_path` | `'profile-photos'` | Directory path within disk for user profile photos |
| `team_photo_path` | `'team-photos'` | Directory path within disk for team photos |
| `ui_avatars_url` | `'https://ui-avatars.com/api/'` | Fallback avatar generation service URL when no photo is uploaded |
| `route_prefix` | `'api/v1'` | Global prefix applied to all package-registered routes |
| `invitation_expiry_days` | `7` | Number of days until a team invitation token expires |
| `token_expiration_minutes` | `null` | Sanctum personal access token TTL in minutes; `null` means tokens never expire |
| `auth.email` | `true` | Whether email-based authentication is accepted for login and registration |
| `auth.phone` | `false` | Whether phone-based authentication is accepted for login and registration |
| `two_factor.company_name` | `env('APP_NAME', 'Laravel')` | Company name displayed in authenticator apps (Google Authenticator, Authy, etc.) |
| `two_factor.recovery_codes_count` | `8` | Number of recovery codes generated when a user enables 2FA |
| `two_factor.geoip_db_path` | `null` | Absolute path to MaxMind GeoIP2 `.mmdb` database file; `null` disables location resolution |
| `two_factor.challenge_token_ttl` | `5` | Minutes until a two-factor challenge token expires |

<a name="authentication-identity"></a>
## Authentication Identity

The package supports email and phone as separate, independent identity mechanisms. Both can be active simultaneously -- a user may authenticate with either their email or phone number depending on what they provided at registration.

```php
'auth' => [
    'email' => true,   // allow email-based login/register
    'phone' => false,  // allow phone-based login/register
],
```

**Mode behavior:**

| `auth.email` | `auth.phone` | Behavior |
|:-------------|:-------------|:---------|
| `true` | `false` | Email is required for registration and login |
| `false` | `true` | Phone is required for registration and login |
| `true` | `true` | Either email or phone is accepted (at least one required) |

When both are enabled, the validation rules use `required_without` constraints so that at least one identifier must be provided.

Check identity status programmatically:

```php
use FlutterSdk\MagicStarter\Features;

Features::emailIdentity();   // bool — whether email identity is enabled
Features::phoneIdentity();   // bool — whether phone identity is enabled
```

Override via environment variables:

```env
MAGIC_STARTER_AUTH_EMAIL=true
MAGIC_STARTER_AUTH_PHONE=false
```

> [!NOTE]
> The `auth.phone` setting is independent of the `phone-otp` feature flag. `auth.phone` controls whether phone is accepted as a login credential; the `phone-otp` feature adds the OTP-based verification flow on top.

<a name="route-prefix"></a>
## Route Prefix

All routes registered by the package are prefixed with the `route_prefix` value. This avoids collisions with your application's existing routes:

```php
'route_prefix' => 'api/v1',
```

```env
MAGIC_STARTER_ROUTE_PREFIX=api/v1
```

With this default, authentication endpoints are available at `/api/v1/login`, `/api/v1/register`, etc.

<a name="storage-disks"></a>
## Storage Disks

Configure separate filesystem disks and path prefixes for user profile photos and team photos:

```php
'profile_photo_disk' => 'public',
'team_photo_disk'    => 'public',
'profile_photo_path' => 'profile-photos',
'team_photo_path'    => 'team-photos',
'ui_avatars_url'     => 'https://ui-avatars.com/api/',
```

The `team_photo_disk` defaults to the same value as `profile_photo_disk` if not explicitly set. When no photo has been uploaded, the `HasProfilePhoto` trait falls back to the `ui_avatars_url` service to generate a placeholder avatar.

Override disks via environment variables for production (e.g., to use S3):

```env
MAGIC_STARTER_PROFILE_PHOTO_DISK=s3
MAGIC_STARTER_TEAM_PHOTO_DISK=s3
MAGIC_STARTER_PROFILE_PHOTO_PATH=profile-photos
MAGIC_STARTER_TEAM_PHOTO_PATH=team-photos
MAGIC_STARTER_UI_AVATARS_URL=https://ui-avatars.com/api/
```

> [!TIP]
> The `profile_photo_path` field supports max 2048 characters in the database to accommodate long filesystem paths. Keep your path prefixes short and let the storage driver handle the rest.

<a name="uuid-configuration"></a>
## UUID Configuration

The `use_uuids` key determines the primary key strategy for all package migrations:

```php
'use_uuids' => true,
```

| Value | Primary Keys | Foreign Keys | Morph Columns |
|:------|:-------------|:-------------|:--------------|
| `true` | `uuid()` columns | `foreignUuid()` references | UUID-based polymorphic columns |
| `false` | `id()` auto-incrementing | `foreignId()` references | Integer-based polymorphic columns |

This value is set automatically during installation based on your existing database schema. All package models include the `ConditionallyUsesUuids` trait, which reads this config at runtime to determine key behavior.

In migrations, always use the `MigrationHelper` methods instead of raw Laravel column methods:

```php
use FlutterSdk\MagicStarter\Support\MigrationHelper;

// Primary key — uses uuid() or id() based on config
MigrationHelper::primaryKey($table);

// Foreign key — uses foreignUuid() or foreignId() based on config
MigrationHelper::foreignKey($table, 'user_id')->constrained()->cascadeOnDelete();

// Morph columns — handles UUID/integer polymorphism
MigrationHelper::morphColumns($table, 'notifiable');
```

> [!NOTE]
> Changing `use_uuids` after running migrations requires a full migration reset. Plan your primary key strategy before deploying to production.

<a name="model-resolution"></a>
## Model Resolution

The package resolves model classes dynamically through `MagicStarter::userModel()`, `MagicStarter::teamModel()`, etc. The resolution order is:

1. **Config value** -- checks `config('magic-starter.models.user')` (settable via environment variable)
2. **`class_exists()` fallback** -- tries `App\Models\User`, `App\Models\Team`, etc.
3. **Package default** -- falls back to the package's built-in model classes

Override model classes via environment variables:

```env
MAGIC_STARTER_USER_MODEL=App\Models\User
MAGIC_STARTER_TEAM_MODEL=App\Models\Team
MAGIC_STARTER_MEMBERSHIP_MODEL=App\Models\TeamUser
MAGIC_STARTER_TEAM_INVITATION_MODEL=App\Models\TeamInvitation
```

Or set them directly in the config file:

```php
'models' => [
    'user'            => \App\Models\User::class,
    'team'            => \App\Models\Team::class,
    'membership'      => \App\Models\TeamUser::class,
    'team_invitation' => \App\Models\TeamInvitation::class,
],
```

You can also override model resolution programmatically in your `AppServiceProvider`:

```php
use FlutterSdk\MagicStarter\MagicStarter;

MagicStarter::useUserModel(\App\Models\User::class);
MagicStarter::useTeamModel(\App\Models\Team::class);
```

> [!NOTE]
> A stale Composer classmap can cause `class_exists()` to return incorrect results. Run `composer dump-autoload` if model auto-resolution is not picking up your custom models.

<a name="two-factor-authentication-settings"></a>
## Two-Factor Authentication Settings

When the `two-factor-authentication` feature is enabled, the following settings control its behavior:

```php
'two_factor' => [
    'company_name'        => env('APP_NAME', 'Laravel'),
    'recovery_codes_count' => 8,
    'geoip_db_path'       => null,
    'challenge_token_ttl'  => 5,
],
```

| Key | Description |
|:----|:------------|
| `company_name` | Displayed in the user's authenticator app as the issuer. Defaults to your `APP_NAME`. |
| `recovery_codes_count` | Number of one-time recovery codes generated when a user enables 2FA. Each code can be used once to bypass the TOTP challenge. |
| `geoip_db_path` | Absolute path to a MaxMind GeoIP2 `.mmdb` database file. When set, 2FA challenge attempts include location data. Set to `null` to disable. |
| `challenge_token_ttl` | Number of minutes a 2FA challenge token remains valid. Users must complete the challenge within this window. |

> [!TIP]
> To use GeoIP location resolution, download a MaxMind GeoLite2-City database and set the `geoip_db_path` to its absolute path on disk. The database is not included with the package.

<a name="environment-variables"></a>
## Environment Variables

All environment variables recognized by the package:

| Variable | Config Key | Default | Description |
|:---------|:-----------|:--------|:------------|
| `MAGIC_STARTER_FRONTEND_URL` | `frontend_url` | `null` | Frontend application URL for email links |
| `MAGIC_STARTER_USER_MODEL` | `models.user` | `null` | Custom User model class |
| `MAGIC_STARTER_TEAM_MODEL` | `models.team` | `Team::class` | Custom Team model class |
| `MAGIC_STARTER_MEMBERSHIP_MODEL` | `models.membership` | `TeamUser::class` | Custom membership pivot model class |
| `MAGIC_STARTER_TEAM_INVITATION_MODEL` | `models.team_invitation` | `TeamInvitation::class` | Custom invitation model class |
| `MAGIC_STARTER_DEFAULT_LOCALE` | `defaults.locale` | `'en'` | Default locale for new users |
| `MAGIC_STARTER_DEFAULT_TIMEZONE` | `defaults.timezone` | `'UTC'` | Default timezone for new users |
| `MAGIC_STARTER_PROFILE_PHOTO_DISK` | `profile_photo_disk` | `'public'` | Filesystem disk for profile photos |
| `MAGIC_STARTER_TEAM_PHOTO_DISK` | `team_photo_disk` | `'public'` | Filesystem disk for team photos |
| `MAGIC_STARTER_PROFILE_PHOTO_PATH` | `profile_photo_path` | `'profile-photos'` | Directory for profile photos |
| `MAGIC_STARTER_TEAM_PHOTO_PATH` | `team_photo_path` | `'team-photos'` | Directory for team photos |
| `MAGIC_STARTER_UI_AVATARS_URL` | `ui_avatars_url` | `'https://ui-avatars.com/api/'` | Fallback avatar service URL |
| `MAGIC_STARTER_ROUTE_PREFIX` | `route_prefix` | `'api/v1'` | Route prefix for all package routes |
| `MAGIC_STARTER_INVITATION_EXPIRY_DAYS` | `invitation_expiry_days` | `7` | Team invitation expiry in days |
| `MAGIC_STARTER_TOKEN_EXPIRATION` | `token_expiration_minutes` | `null` | Sanctum token TTL in minutes |
| `MAGIC_STARTER_AUTH_EMAIL` | `auth.email` | `true` | Enable email-based authentication |
| `MAGIC_STARTER_AUTH_PHONE` | `auth.phone` | `false` | Enable phone-based authentication |
| `APP_NAME` | `two_factor.company_name` | `'Laravel'` | Company name in authenticator apps |
