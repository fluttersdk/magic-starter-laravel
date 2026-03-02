# Magic Starter Laravel

A modular Laravel backend package providing authentication, team management, profile settings, session management, and social login — inspired by [Laravel Jetstream](https://jetstream.laravel.com)'s architecture with feature toggles, action contracts, and sensible defaults that work out of the box.

- [Requirements](#requirements)
- [Installation](#installation)
  - [Composer Setup](#composer-setup)
  - [Running the Install Command](#running-the-install-command)
  - [User Model Setup](#user-model-setup)
  - [Binding Action Contracts](#binding-action-contracts)
- [Configuration](#configuration)
  - [Feature Toggles](#feature-toggles)
  - [All Config Keys](#all-config-keys)
  - [Route Prefix](#route-prefix)
  - [Storage Disks](#storage-disks)
- [Architecture](#architecture)
  - [Directory Structure](#directory-structure)
  - [Service Provider](#service-provider)
  - [MagicStarter Class](#magicstarter-class)
  - [Action Contract Pattern](#action-contract-pattern)
  - [Dynamic Model Resolution](#dynamic-model-resolution)
  - [Event Listeners](#event-listeners)
  - [Notification Preference Registry](#notification-preference-registry)
  - [Route Control](#route-control)
- [Features](#features)
  - [Authentication](#authentication)
  - [Social Login](#social-login)
  - [Password Reset](#password-reset)
  - [Teams](#teams)
  - [Team Members](#team-members)
  - [Team Invitations](#team-invitations)
  - [Profile Management](#profile-management)
  - [Profile Photo](#profile-photo)
  - [Team Photo](#team-photo)
  - [Session Management](#session-management)
  - [Two-Factor Authentication](#two-factor-authentication)
  - [Notifications](#notifications)
  - [Email Verification](#email-verification)
  - [Settings API](#settings-api)
  - [Newsletter Subscription](#newsletter-subscription)
  - [Timezone List](#timezone-list)
  - [Guest Authentication](#guest-authentication)
  - [Phone OTP](#phone-otp)
- [API Reference](#api-reference)
  - [Public Routes](#public-routes)
  - [Protected Routes](#protected-routes)
  - [Response Shapes](#response-shapes)
- [Action Contracts](#action-contracts-reference)
- [Models](#models-reference)
- [User Traits](#user-traits)
- [Form Requests](#form-requests)
- [Publishable Migrations](#publishable-migrations)
- [Testing](#testing)

## Requirements

| Dependency | Version |
|:-----------|:--------|
| PHP | ^8.2 |
| Laravel | ^11.0 \| ^12.0 |
| Laravel Sanctum | ^4.0 |
| Laravel Socialite | ^5.0 (bundled) |

## Installation

### Composer Setup

Add the package as a path repository in your application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "plugins/magic-starter-laravel"
        }
    ]
}
```

Then require it:

```shell
composer require fluttersdk/magic-starter-laravel-back-end:@dev
```

The service provider is auto-discovered via `extra.laravel.providers` in the package's `composer.json`.

### Running the Install Command

The install command publishes configuration and feature-relevant migrations:

```shell
php artisan magic-starter:install
```

**Interactive mode** — when run without flags, the command uses Laravel Prompts to guide you through the setup: a `multiselect` for features, `text` inputs for route prefix and frontend URL, and a `confirm` for running migrations immediately.

**Non-interactive mode** — pass flags to skip all prompts. Useful in CI/CD pipelines:

```shell
php artisan magic-starter:install --all --uuid --route-prefix=api/v1 --frontend-url=https://app.example.com
```

**Available options:**

| Option | Description |
|:-------|:------------|
| `--all` | Install all features without prompting |
| `--features=*` | Comma-separated list of features to install |
| `--uuid` | Use UUID primary keys (default for fresh installs) |
| `--no-uuid` | Use auto-incrementing integer primary keys |
| `--route-prefix=` | Route prefix for package routes |
| `--frontend-url=` | Frontend application URL used in email links |
| `--force` | Overwrite existing published files |

The `--features` option accepts: `teams`, `profile-photos`, `sessions`, `social-login`, `newsletter-subscription`, `extended-profile`, `notifications`, `two-factor-authentication`, `guest-auth`, `phone-otp`, `email-verification`, `timezones`. When `--all` is passed, omitting `--features` enables everything.

> [!NOTE]
> When neither `--uuid` nor `--no-uuid` is provided, the installer auto-detects your existing `users` table schema. If no `users` table exists (fresh install), UUID is used by default.

The install command publishes configuration and migrations. Action stubs and model stubs are **not** published by default — the plugin ships with sensible default implementations that work out of the box.

| Asset | Destination | Publish Tag |
|:------|:------------|:------------|
| Config | `config/magic-starter.php` | `magic-starter-config` |
| Migrations | `database/migrations/` | `magic-starter-migrations` |
| Translations | `lang/vendor/magic-starter/` | `magic-starter-lang` |

To customize the default action or model implementations, publish the stubs manually:

| Asset | Destination | Publish Tag |
|:------|:------------|:------------|
| Action Stubs | `app/Actions/MagicStarter/` | `magic-starter-stubs` |
| Model Stubs | `app/Models/` | `magic-starter-models` |

```shell
php artisan vendor:publish --tag=magic-starter-stubs
php artisan vendor:publish --tag=magic-starter-models
```

### User Model Setup

Add the relevant traits to your `User` model. `HasApiTokens` (from Sanctum) is required for token authentication. When using UUID primary keys (the default), also add `HasUuids`. Add `HasTeams`, `HasProfilePhoto`, and optionally `HasNotifications` and `TwoFactorAuthenticatable`:

```php
use FlutterSdk\MagicStarter\Traits\HasTeams;
use FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
use FlutterSdk\MagicStarter\Traits\HasNotifications;
use FlutterSdk\MagicStarter\Traits\TwoFactorAuthenticatable;
use FlutterSdk\MagicStarter\Traits\HasGuestSupport;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasUuids;
    use HasTeams;
    use HasProfilePhoto;
    use HasNotifications;
    use TwoFactorAuthenticatable;
    use HasGuestSupport; // optional — when guest-auth feature is enabled

    protected $appends = [
        'profile_photo_url',
    ];
}
```

> [!IMPORTANT]
> When `use_uuids` is `true` (the default for fresh installs), you **must** add the `HasUuids` trait to your User model. Without it, user creation will fail with a `NOT NULL constraint` error on the `id` column. If you opted for auto-incrementing integers (`--no-uuid`), omit `HasUuids`.

Only add `HasNotifications` when the `notifications` feature is enabled. Add `HasGuestSupport` only when the `guest-auth` feature is enabled. The other traits are safe to include regardless of enabled features.

### Binding Action Contracts

The package binds all action contracts to default implementations in its service provider — **no manual binding is required** for the plugin to work out of the box.

To customize behavior, publish the action stubs and override the bindings in your `AppServiceProvider`:

```shell
php artisan vendor:publish --tag=magic-starter-stubs
```

```php
use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use FlutterSdk\MagicStarter\Contracts\CreatesTeams;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeams;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserPasswords;
use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\Contracts\AddsTeamMembers;
use FlutterSdk\MagicStarter\Contracts\InvitesTeamMembers;
use FlutterSdk\MagicStarter\Contracts\RemovesTeamMembers;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeamMemberRoles;

public function register(): void
{
    $this->app->bind(CreatesUsers::class, \App\Actions\MagicStarter\CreateUser::class);
    $this->app->bind(CreatesTeams::class, \App\Actions\MagicStarter\CreateTeam::class);
    $this->app->bind(UpdatesTeams::class, \App\Actions\MagicStarter\UpdateTeam::class);
    $this->app->bind(DeletesTeams::class, \App\Actions\MagicStarter\DeleteTeam::class);
    $this->app->bind(UpdatesUserProfiles::class, \App\Actions\MagicStarter\UpdateUserProfile::class);
    $this->app->bind(UpdatesUserPasswords::class, \App\Actions\MagicStarter\UpdateUserPassword::class);
    $this->app->bind(DeletesUsers::class, \App\Actions\MagicStarter\DeleteUser::class);
    $this->app->bind(AddsTeamMembers::class, \App\Actions\MagicStarter\AddTeamMember::class);
    $this->app->bind(InvitesTeamMembers::class, \App\Actions\MagicStarter\InviteTeamMember::class);
    $this->app->bind(RemovesTeamMembers::class, \App\Actions\MagicStarter\RemoveTeamMember::class);
    $this->app->bind(UpdatesTeamMemberRoles::class, \App\Actions\MagicStarter\UpdateTeamMemberRole::class);
}
```

> [!NOTE]
> Published action stubs throw `RuntimeException` by default. You must implement the business logic in each action class before deploying.

## Configuration

The configuration file is at `config/magic-starter.php`.

### Feature Toggles

Features follow Jetstream's toggle pattern. Enable features by adding them to the `features` array:

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
    // \FlutterSdk\MagicStarter\Features::emailVerification(),
    // \FlutterSdk\MagicStarter\Features::guestAuth(),
    // \FlutterSdk\MagicStarter\Features::phoneOtp(),
    // \FlutterSdk\MagicStarter\Features::timezones(),
],
```

When a feature is disabled, its routes are not registered and its functionality is unavailable. Core auth and profile management routes are always active.

Check feature status programmatically:

```php
use FlutterSdk\MagicStarter\Features;

Features::enabled('teams');                          // bool — generic check
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
Features::hasTimezoneOrExtendedProfileFeatures();    // bool — true when either is enabled
```

**All 12 features and their toggle methods:**

| Feature Key | Enable Method | Check Method |
|:------------|:--------------|:-------------|
| `teams` | `Features::teams()` | `Features::hasTeamFeatures()` |
| `profile-photos` | `Features::profilePhotos()` | `Features::hasProfilePhotoFeatures()` |
| `sessions` | `Features::sessions()` | `Features::hasSessionFeatures()` |
| `social-login` | `Features::socialLogin()` | `Features::hasSocialLoginFeatures()` |
| `newsletter-subscription` | `Features::newsletterSubscription()` | `Features::hasNewsletterSubscriptionFeatures()` |
| `extended-profile` | `Features::extendedProfile()` | `Features::hasExtendedProfileFeatures()` |
| `notifications` | `Features::notifications()` | `Features::hasNotificationFeatures()` |
| `two-factor-authentication` | `Features::twoFactorAuthentication()` | `Features::hasTwoFactorAuthenticationFeatures()` |
| `email-verification` | `Features::emailVerification()` | `Features::hasEmailVerificationFeatures()` |
| `guest-auth` | `Features::guestAuth()` | `Features::hasGuestAuthFeatures()` |
| `phone-otp` | `Features::phoneOtp()` | `Features::hasPhoneOtpFeatures()` |
| `timezones` | `Features::timezones()` | `Features::hasTimezoneFeatures()` |

### All Config Keys

| Key | Default | Description |
|:----|:--------|:------------|
| `use_uuids` | `true` | When `true`, all package migrations use UUID primary keys; when `false`, auto-incrementing integers |
| `features` | `[]` | Array of enabled feature strings |
| `frontend_url` | `env('MAGIC_STARTER_FRONTEND_URL')` | Base URL for the frontend application, used in email links |
| `models.user` | `env('MAGIC_STARTER_USER_MODEL')` | Custom User model class; falls back to `auth.providers.users.model` |
| `models.team` | `Team::class` | Team model class |
| `models.membership` | `TeamUser::class` | Pivot model for team membership |
| `models.team_invitation` | `TeamInvitation::class` | Invitation model class |
| `defaults.locale` | `'en'` | Default locale assigned to new users |
| `defaults.timezone` | `'UTC'` | Default timezone assigned to new users |
| `supported_locales` | `['en', 'tr']` | Locales accepted by locale/language validation rules |
| `profile_photo_disk` | `'public'` | Storage disk for user profile photos |
| `team_photo_disk` | `'public'` | Storage disk for team photos |
| `profile_photo_path` | `'profile-photos'` | Directory within disk for user profile photos |
| `team_photo_path` | `'team-photos'` | Directory within disk for team photos |
| `ui_avatars_url` | `'https://ui-avatars.com/api/'` | Fallback avatar generation service URL |
| `route_prefix` | `'api/v1'` | Global prefix applied to all package routes |
| `invitation_expiry_days` | `7` | Days until a team invitation token expires |
| `token_expiration_minutes` | `null` | Sanctum personal access token TTL in minutes; `null` means no expiry |
| `auth.email` | `true` | Whether to allow email-based authentication for login/register |
| `auth.phone` | `false` | Whether to allow phone-based authentication for login/register |
| `two_factor.company_name` | `env('APP_NAME', 'Laravel')` | Company name displayed in authenticator apps |
| `two_factor.recovery_codes_count` | `8` | Number of recovery codes generated when 2FA is enabled |
| `two_factor.geoip_db_path` | `null` | Path to MaxMind GeoIP2 database for location resolution |
| `two_factor.challenge_token_ttl` | `5` | Minutes until a 2FA challenge token expires |

Override model classes via environment variables:

```env
MAGIC_STARTER_USER_MODEL=App\Models\User
MAGIC_STARTER_FRONTEND_URL=https://app.example.com
MAGIC_STARTER_AUTH_EMAIL=true
MAGIC_STARTER_AUTH_PHONE=false
```

### Route Prefix

Prefix all package routes to avoid collisions with your application's existing routes:

```php
'route_prefix' => 'api/v1',
```

```env
MAGIC_STARTER_ROUTE_PREFIX=api/v1
```

### Authentication Identity

The package supports email and phone as separate, independent identity mechanisms. Both can be active simultaneously — a user may authenticate with either their email or their phone number depending on what they provided at registration.

Control which identity methods are accepted via the `auth` config keys:

```php
'auth' => [
    'email' => true,   // allow email-based login/register
    'phone' => false,  // allow phone-based login/register
],
```

Check identity status programmatically:

```php
use FlutterSdk\MagicStarter\Features;

Features::emailIdentity();   // bool — whether email identity is enabled
Features::phoneIdentity();   // bool — whether phone identity is enabled
```

> [!NOTE]
> These are independent of the `phone-otp` feature. `auth.phone` controls whether phone is accepted as a login credential; `phone-otp` adds the OTP-based verification flow on top.

### Storage Disks

Configure separate disks and path prefixes for user and team photos:

```php
'profile_photo_disk' => 'public',
'team_photo_disk'    => 'public',
'profile_photo_path' => 'profile-photos',
'team_photo_path'    => 'team-photos',
```

```env
MAGIC_STARTER_PROFILE_PHOTO_DISK=s3
```

## Architecture

### Directory Structure

```
magic-starter-laravel/
├── config/
│   └── magic-starter.php                  # Package configuration
├── database/
│   └── migrations/                        # 18 publishable migration stubs
├── lang/
│   ├── en/
│   │   └── teams.php                      # English team translations
│   └── tr/
│       └── teams.php                      # Turkish team translations
├── src/
│   ├── Actions/                          # 18 action classes (11 core + 4 two-factor + 3 auth)
│   ├── Console/
│   │   └── InstallCommand.php             # magic-starter:install
│   ├── Contracts/                         # 18 contracts (11 core + 4 two-factor + 3 auth)
│   ├── Enums/                            # Role enum
│   ├── Http/
│   │   ├── Controllers/                   # 20 API controllers
│   │   ├── Requests/                      # 23 form requests
│   │   └── Resources/                     # 7 API resources
│   ├── Listeners/
│   │   ├── CreatePersonalTeamListener.php # Fires on Registered event
│   │   └── GateNotificationChannels.php   # Fires on NotificationSending event
│   ├── Models/                            # Team, TeamInvitation, TeamUser,
│   │   │                                  #   PersonalAccessToken, NotificationSetting,
│   │   │                                  #   NewsletterSubscriber
│   ├── Notifications/                    # TeamInvitationNotification, VerifyEmailNotification
│   ├── NotificationPreferenceRegistry.php  # Notification type/channel matrix
│   ├── Rules/                            # E164Phone validation rule
│   ├── Support/                          # Helper classes (MigrationHelper, SessionAgent, etc.)
│   ├── Traits/                            # HasTeams, HasProfilePhoto, HasNotifications, TwoFactorAuthenticatable, HasGuestSupport, MustVerifyEmail
│   ├── routes/
│   │   └── api.php                        # Conditional route registration
│   ├── Features.php                       # Feature toggle class
│   ├── MagicStarter.php                   # Model resolution + route control
│   └── MagicStarterServiceProvider.php
├── stubs/
│   ├── actions/                           # 12 publishable action stubs
│   └── models/                            # Team, TeamInvitation, TeamUser model stubs
└── tests/                                 # PHPUnit + Orchestra Testbench
```

### Service Provider

`MagicStarterServiceProvider` handles the full bootstrap lifecycle:

- Merges the package config with any application overrides.
- Binds all 18 action contracts to their default implementations in the IoC container.
- Sets the Sanctum `PersonalAccessToken` model to the package's extended version (adds `ip_address` and `user_agent`).
- Configures the password reset URL to point at the configured `frontend_url`.
- Registers event listeners (`CreatePersonalTeamListener`, `GateNotificationChannels`).
- Registers the `TwoFactorAuthenticationProvider` for TOTP code generation and verification.
- Loads routes conditionally based on enabled features, unless `ignoreRoutes()` has been called.
- Loads package translations (`lang/`) under the `magic-starter` namespace, publishable via the `magic-starter-lang` tag.
- Registers the five publishing groups (`config`, `migrations`, `stubs`, `models`, `lang`) and the `magic-starter:install` Artisan command.

### MagicStarter Class

The `MagicStarter` class acts as the central configuration point, providing static methods for model resolution and route control:

| Method | Description |
|:-------|:------------|
| `userModel()` | Resolves user model: runtime override → config → auth provider fallback |
| `teamModel()` | Resolves team model class |
| `membershipModel()` | Resolves team membership pivot model class |
| `teamInvitationModel()` | Resolves team invitation model class |
| `ignoreRoutes()` | Suppresses all package route loading |
| `useUserModel(string $model)` | Sets a runtime user model override |
| `useTeamModel(string $model)` | Sets a runtime team model override |
| `reset()` | Clears all runtime overrides (useful in tests) |

### Action Contract Pattern

Business logic is never hardcoded in controllers. Controllers resolve action contracts from the IoC container, and your application provides the implementations:

```
┌─────────────────────┐     resolve      ┌──────────────────────────┐
│   AuthController     │ ──────────────→ │  CreatesUsers (contract) │
│   register()         │                  └──────────────────────────┘
└─────────────────────┘                              ▲
                                                     │ implements
                                          ┌──────────┴──────────────┐
                                          │  CreateUser (your app)  │
                                          │  app/Actions/MagicStarter/
                                          └─────────────────────────┘
```

The package owns the interfaces (`src/Contracts/`). Your application owns the implementations (`app/Actions/MagicStarter/`).

### Dynamic Model Resolution

The package never hardcodes `App\Models\User`. All model references go through the `MagicStarter` class:

```php
MagicStarter::userModel();            // string — configured user model class
MagicStarter::teamModel();            // string — configured team model class
MagicStarter::membershipModel();      // string — configured pivot model class
MagicStarter::teamInvitationModel();  // string — configured invitation model class
```

### Event Listeners

The service provider registers two event listeners automatically.

**`CreatePersonalTeamListener`** — listens on `Illuminate\Auth\Events\Registered`

Active when the `teams` feature is enabled. After a user registers, this listener creates a personal team with a **localized name** based on the user's `locale` attribute, attaches the user as owner, and sets `current_team_id` on the user record. Team names are translated using the `magic-starter::teams.personal_team_name` translation key (e.g., "John's Team" in English, "John Takımı" in Turkish).

**`GateNotificationChannels`** — listens on `Illuminate\Notifications\Events\NotificationSending`

Active when the `notifications` feature is enabled. Before a notification is delivered, this listener checks the `NotificationPreferenceRegistry` to see if the type and channel are registered, then calls `prefers()` on the notifiable model. If the user has disabled that channel for that notification type, the listener blocks delivery by returning `false`.

### Notification Preference Registry

`NotificationPreferenceRegistry` is a static registry where you declare what notification types exist and which channels they support. Register your types in a service provider:

```php
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;

NotificationPreferenceRegistry::register([
    [
        'slug'     => 'monitor_down',
        'label'    => 'Monitor Down',
        'channels' => ['mail', 'database', 'push'],
        'default'  => true,
        'locked'   => false,
    ],
]);
```

Channel aliases map logical channel names to notification driver names:

```php
NotificationPreferenceRegistry::channelAliases([
    'push' => 'onesignal',
]);
```

The registry exposes resolution helpers for lookup by slug or fully-qualified class name. The `GateNotificationChannels` listener uses these internally.

### Route Control

To completely disable package route loading and define your own:

```php
// Call this before MagicStarterServiceProvider boots — typically in a service
// provider that is registered earlier in config/app.php.
\FlutterSdk\MagicStarter\MagicStarter::ignoreRoutes();
```

## Features

### Authentication

Provides registration, login, logout, current user retrieval, and team switching via Sanctum token authentication. These routes are always registered regardless of which optional features are enabled.

- **Register**: Creates the user via the `CreatesUsers` contract, fires the `Registered` event (which triggers personal team creation if the `teams` feature is active), and returns a Sanctum token.
- **Login**: Validates credentials, issues a Sanctum token. The token always stores `ip_address` and `user_agent` on the `PersonalAccessToken` record.
- **Logout**: Revokes the current personal access token.
- **Current User**: Returns the authenticated user. When `teams` feature is enabled, includes `current_team` and `all_teams` fields; these are omitted when teams is disabled.
- **Switch Team**: Updates `current_team_id` on the user record. Requires `teams` feature.

### Social Login

> Requires `Features::socialLogin()` to be useful. The route is always registered, but OAuth providers must be configured separately via Socialite.

Accepts either an `access_token` (for mobile OAuth flows) or an `authorization_code` (for server-side flows). If the authenticated social account's email already exists in the database, the user is logged in. Otherwise, a new user is created via the `CreatesUsers` contract.

### Password Reset

Standard Laravel password reset flow, always active:

- **Forgot Password**: Calls `Password::sendResetLink()`. The reset link points to `config('magic-starter.frontend_url')`.
- **Reset Password**: Validates the token, resets the password, fires the `PasswordReset` event.

### Teams

> Requires `Features::teams()` enabled.

Full team CRUD with authorization gates:

- **List**: Returns all teams the authenticated user belongs to (owned and member).
- **Create**: Via `CreatesTeams` contract.
- **Show**: With `view` gate authorization.
- **Update**: Via `UpdatesTeams` contract, protected by the `update` gate.
- **Delete**: Via `DeletesTeams` contract, protected by the `delete` gate. Prevents deleting the personal team or the last team. Auto-switches `current_team_id` to the next available team.
- **Switch Team**: Updates `current_team_id`. The target team must exist and the user must belong to it.

### Team Members

> Requires `Features::teams()` enabled.

- **List**: All current members including the owner, who is listed with the `owner` role.
- **Update Role**: Via `UpdatesTeamMemberRoles` contract. Changes the pivot `role`. Cannot change the owner's role.
- **Remove**: Via `RemovesTeamMembers` contract. Cannot remove the owner.
- **Leave**: Member voluntarily leaves the team. The owner cannot leave — they must transfer ownership or delete the team. Auto-switches `current_team_id` after leaving.

### Team Invitations

> Requires `Features::teams()` enabled.

Token-based invitation system with configurable expiry:

- **List**: All pending (non-expired) invitations for a team.
- **Send**: Via `InvitesTeamMembers` contract. Prevents sending duplicate invitations to the same address and prevents inviting users who are already members.
- **Cancel**: Deletes a pending invitation.
- **Accept**: Token-based acceptance endpoint. Attaches the authenticated user to the team with the invitation's role, then deletes the invitation.

Invitations expire after `config('magic-starter.invitation_expiry_days')` days (default: 7).

### Profile Management

Always active. These routes require `auth:sanctum`:

- **Update Profile**: Via `UpdatesUserProfiles` contract. Accepted fields: `name`, `phone` (E.164 format), `timezone` (any valid IANA timezone), `language` (from supported locales).
- **Update Password**: Via `UpdatesUserPasswords` contract. Requires current password verification.
- **Delete Account**: Via `DeletesUsers` contract. Requires password confirmation.

### Profile Photo

> Requires `Features::profilePhotos()` enabled.

- **Upload**: Accepts a JPEG/PNG image up to 1 MB. Stores it on the configured disk under `profile_photo_path/`. Replaces any previously uploaded photo.
- **Delete**: Removes the file from storage and clears `profile_photo_path` on the user record.

When no photo is set, `getProfilePhotoUrlAttribute()` generates a fallback avatar via the configured `ui_avatars_url` using the user's name initials.

### Team Photo

> Requires both `Features::profilePhotos()` and `Features::teams()` enabled.

- **Upload**: Accepts an image up to 2 MB. Stored on the configured `team_photo_disk` under `team_photo_path/`. Replaces any previous team photo.
- **Delete**: Removes the file from storage and clears `profile_photo_path` on the team record.

Fallback avatar generation works the same way as user photos, using the team name.

### Session Management

> Requires `Features::sessions()` enabled.

The package treats each Sanctum personal access token as a "session". The extended `PersonalAccessToken` model stores `ip_address` and `user_agent` alongside each token.

- **List**: All active tokens for the authenticated user, with a boolean `is_current_device` flag on the current token.
- **Revoke One**: Deletes a specific token by its ID.
- **Revoke Others**: Deletes all tokens except the currently active one.

### Two-Factor Authentication

> Requires `Features::twoFactorAuthentication()` enabled.

TOTP-based two-factor authentication. When enabled, users must provide a 6-digit code from their authenticator app (Google Authenticator, Authy, or any TOTP-compatible app) to complete the login process.

**Enabling 2FA**

A user enables 2FA by calling the store endpoint. The response includes a QR code SVG, the raw secret URL, and a set of one-time recovery codes. The user must then confirm the setup by submitting a valid TOTP code — 2FA is not active until confirmed.

```shell
POST two-factor-authentication
Authorization: Bearer {token}
```

```json
{
    "data": {
        "secret": "...",
        "qr_url": "...",
        "qr_svg": "...",
        "recovery_codes": ["..."]
    },
    "message": "Two-factor authentication enabled. Please confirm with your authenticator app."
}
```

After scanning the QR code, the user confirms it:

```shell
POST two-factor-authentication/confirm
Authorization: Bearer {token}

{"code": "123456"}
```

**Logging in with 2FA**

When a user with confirmed 2FA logs in, the login endpoint returns a challenge response instead of a Sanctum token:

```json
{
    "two_factor": true,
    "two_factor_token": "eyJpdiI6..."
}
```

The user submits this token with their TOTP code (or a recovery code) to the public challenge endpoint. On success, they receive the normal authenticated response:

```shell
POST auth/two-factor-challenge

{
    "two_factor_token": "eyJpdiI6...",
    "code": "123456"
}
```

The challenge token expires after `config('magic-starter.two_factor.challenge_token_ttl')` minutes (default: 5).

**Recovery codes**

Users can view their current recovery codes or regenerate a fresh set:

```shell
GET  two-factor-recovery-codes    # list current codes
POST two-factor-recovery-codes    # regenerate all codes
```

Each recovery code can only be used once. Using a code during the challenge flow automatically replaces it with a new one via `TwoFactorAuthenticatable::replaceRecoveryCode()`.

**Disabling 2FA**

Users disable 2FA by providing their current password:

```shell
DELETE two-factor-authentication
Authorization: Bearer {token}

{"password": "current-password"}
```

### Notifications

> Requires `Features::notifications()` enabled.

Provides a channel-based notification preference registry and a full API for managing user preferences and database notifications.

- **Registry**: Declare notification types and supported channels via `NotificationPreferenceRegistry::register()`. Each type has a slug, label, default enabled state, locked flag, and channel list.
- **Preference Matrix**: Returns the full matrix of registered types and channels merged with the user's stored overrides.
- **Update Preferences**: Accepts either a single `{type, channel, is_enabled}` object or a `preferences` array for bulk updates.
- **Notification List**: Paginated list of the user's database notifications.
- **Unread Count**: Returns the count of unread notifications.
- **Mark as Read**: Marks a single notification as read.
- **Mark All as Read**: Marks all unread notifications as read.
- **Delete**: Deletes a single notification.

> Requires `Features::emailVerification()` enabled.

Provides a two-step email address ownership confirmation flow. When enabled, new user registration sends a verification notification, and users can request a new verification link at any time.

- **Send Notification**: `POST email/verification-notification` (authenticated) — sends a fresh verification link. Returns 200 if already verified, 400 if no email, 202 on dispatch.

- **Verify Email**: `GET email/verify/{id}/{hash}` (signed URL) — validates the signed URL and marks the email as verified. The verification URL uses `frontend_url` for mobile deep-link support.

Add `MustVerifyEmail` to your User model to participate in the verification flow:

```php
use FlutterSdk\MagicStarter\Traits\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use MustVerifyEmail;
}
```

Apply `verified` middleware to routes that require a verified email:

```php
Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    // protected routes
});
```

### Settings API

Always active. No authentication required.

Returns a strictly allowlisted configuration payload for frontend apps to bootstrap without hardcoded values.

```shell
GET /settings
```

Response:

```json
{
    "supported_locales": ["en", "tr"],
    "features": {
        "registration": true,
        "teams": false,
        "social_login": false,
        "email_verification": false,
        "guest_auth": false,
        "phone_otp": false,
        "newsletter": false,
        "extended_profile": false,
        "two_factor_authentication": false,
        "sessions": false,
        "profile_photos": false,
        "notifications": false,
        "timezones": false
    },
    "auth": {
        "email": true,
        "phone": false
    },
    "defaults": {
        "locale": "en",
        "timezone": "UTC"
    }
}
```

### Newsletter Subscription

> Requires `Features::newsletterSubscription()` enabled.

Adds a `subscribe_newsletter` boolean field to the registration payload. When `true`, the `CreatesUsers` action (or the listener) creates a `NewsletterSubscriber` record tied to the new user's email with `source` set to `register`.

Authenticated users can check and toggle their newsletter subscription status via:

- **Show**: `GET /user/newsletter` (authenticated) — returns `{subscribed, source, subscribed_at}` or `{subscribed: false}` if not subscribed.

- **Update**: `PUT /user/newsletter` (authenticated) — request body `{subscribe: boolean}`. Returns the updated status shape.

### Timezone List

> Requires `Features::timezones()` enabled.

Provides a paginated, searchable list of all IANA timezones sourced from PHP's `DateTimeZone::listIdentifiers()`. Not backed by a database table.

- **Route**: `GET /timezones` (public, rate-limited)
- **Search**: Case-insensitive partial match on identifier or UTC offset string (e.g., `+03:00`).
- **Sorting**: Sorted by UTC offset ascending (UTC first, then eastward).
- **Pagination**: Supports `page` and `per_page` query parameters (default: 15 per page).

Response shape:

```json
{
    "data": [
        {
            "identifier": "Europe/Istanbul",
            "label": "(UTC+03:00) Europe/Istanbul",
            "offset": "+03:00",
            "offset_minutes": 180,
            "region": "Europe"
        }
    ],
    "links": { "..." : "pagination links" },
    "meta": { "..." : "pagination meta" }
}
```

### Guest Authentication

> Requires `Features::guestAuth()` enabled.

Allows unauthenticated users to obtain a guest token by providing only a `device_id`. This is useful for mobile apps that need to track state before a user registers.

- **Route**: `POST auth/guest` (public, rate-limited)
- **Request**: `GuestLoginRequest` — requires `device_id` (string, max:255).
- **Behaviour**: Looks up an existing guest user by `device_id`. If none exists, creates a new one with `is_guest = true` and returns HTTP 201. Subsequent logins for the same `device_id` revoke all existing tokens (preventing session buildup) and return a fresh token with HTTP 200.
- **Personal Team**: When both `guest-auth` and `teams` features are enabled, a localized personal team is automatically created for new guest users. The team name uses the `magic-starter::teams.personal_team_name` translation key with the guest's translated name (e.g., "Guest's Team" in English, "Misafir Takımı" in Turkish). Returning guests (same `device_id`) do not receive duplicate teams.
- **Contract**: Delegates to the `CreatesGuestUsers` contract.
- **Trait**: Add `HasGuestSupport` to your User model to access `isGuest()` and `isRegistered()` helpers.
- **Email Verification Bypass**: Guest users with the `MustVerifyEmail` trait automatically pass the `verified` middleware — since they have no email to verify, `hasVerifiedEmail()` returns `true`.

### Phone OTP

> Requires `Features::phoneOtp()` enabled.

Provides a two-step OTP login flow over phone numbers in E.164 format.

- **Send route**: `POST auth/otp/send` (public, rate-limited) — generates a 6-digit code, caches it for 5 minutes, and delegates delivery to the `SendsOtpCodes` contract.
- **Verify route**: `POST auth/otp/verify` (public, rate-limited) — validates the submitted code via the `VerifiesOtpCodes` contract, finds the user by phone number, and returns a Sanctum token on success.
- **Phone format**: All phone values must be in E.164 format (e.g. `+14155552671`).
- **Code TTL**: 5 minutes from send time. Codes are single-use.

## API Reference

All routes are prefixed by `config('magic-starter.route_prefix')` (default: `api/v1`). The examples below assume no prefix is configured.

### Public Routes

Rate-limited at `throttle:5,1` (5 requests per minute):

| Method | URI | Controller@Method | Request |
|:-------|:----|:------------------|:--------|
| GET | `settings` | `SettingsController@index` | (none — public) |
| GET | `timezones` | `TimezoneController@index` | (none — public) — requires `Features::timezones()` |
| POST | `auth/register` | `AuthController@register` | `RegisterRequest` |
| POST | `auth/login` | `AuthController@login` | `LoginRequest` |
| POST | `auth/social/{provider}` | `AuthController@socialLogin` | `SocialLoginRequest` |
| POST | `auth/forgot-password` | `PasswordResetController@sendResetLinkEmail` | `ForgotPasswordRequest` |
| POST | `auth/reset-password` | `PasswordResetController@reset` | `ResetPasswordRequest` |
| POST | `auth/two-factor-challenge` | `TwoFactorChallengeController@store` | `TwoFactorChallengeRequest` — requires `Features::twoFactorAuthentication()` |
| POST | `auth/guest` | `GuestAuthController@login` | `GuestLoginRequest` — requires `Features::guestAuth()` |
| POST | `auth/otp/send` | `OtpController@send` | `SendOtpRequest` — requires `Features::phoneOtp()` |
| POST | `auth/otp/verify` | `OtpController@verify` | `VerifyOtpRequest` — requires `Features::phoneOtp()` |

### Protected Routes

All require `auth:sanctum` middleware.

**Core Auth:**

| Method | URI | Controller@Method |
|:-------|:----|:------------------|
| POST | `auth/logout` | `AuthController@logout` |
| GET | `auth/user` | `AuthController@user` |
| PUT | `user/current-team` | `AuthController@switchTeam` |

**Profile:**

| Method | URI | Controller@Method |
|:-------|:----|:------------------|
| PUT | `user/profile` | `ProfileController@update` |
| PUT | `user/password` | `ProfileController@updatePassword` |
| POST | `user/` | `ProfileController@destroy` |
| DELETE | `user/` | `ProfileController@destroy` |

**Profile Photo** (`Features::profilePhotos()`):

| Method | URI | Controller@Method |
|:-------|:----|:------------------|
| POST | `user/profile-photo` | `ProfilePhotoController@update` |
| DELETE | `user/profile-photo` | `ProfilePhotoController@delete` |

**Teams** (`Features::teams()`):

| Method | URI | Controller@Method |
|:-------|:----|:------------------|
| GET | `teams` | `TeamController@index` |
| POST | `teams` | `TeamController@store` |
| GET | `teams/{team}` | `TeamController@show` |
| PUT | `teams/{team}` | `TeamController@update` |
| DELETE | `teams/{team}` | `TeamController@destroy` |

**Team Members** (`Features::teams()`):

| Method | URI | Controller@Method |
|:-------|:----|:------------------|
| GET | `teams/{team}/members` | `TeamMemberController@index` |
| PUT | `teams/{team}/members/{user}` | `TeamMemberController@update` |
| DELETE | `teams/{team}/members/{user}` | `TeamMemberController@destroy` |
| DELETE | `teams/{team}/leave` | `TeamMemberController@leave` |

**Team Invitations** (`Features::teams()`):

| Method | URI | Controller@Method |
|:-------|:----|:------------------|
| GET | `teams/{team}/invitations` | `TeamInvitationController@index` |
| POST | `teams/{team}/invitations` | `TeamInvitationController@store` |
| DELETE | `teams/{team}/invitations/{invitation}` | `TeamInvitationController@destroy` |
| POST | `invitations/{token}/accept` | `TeamInvitationController@accept` |

**Team Photos** (`Features::teams()` + `Features::profilePhotos()`):

| Method | URI | Controller@Method |
|:-------|:----|:------------------|
| POST | `teams/{team}/profile-photo` | `TeamPhotoController@update` |
| DELETE | `teams/{team}/profile-photo` | `TeamPhotoController@delete` |

**Sessions** (`Features::sessions()`):

| Method | URI | Controller@Method |
|:-------|:----|:------------------|
| GET | `sessions` | `SessionController@index` |
| DELETE | `sessions/other` | `SessionController@destroyOther` |
| DELETE | `sessions/{token}` | `SessionController@destroy` |

**Two-Factor Authentication** (`Features::twoFactorAuthentication()`):

| Method | URI | Controller@Method |
|:-------|:----|:------------------|
| POST | `two-factor-authentication` | `TwoFactorAuthenticationController@store` |
| POST | `two-factor-authentication/confirm` | `TwoFactorAuthenticationController@confirm` |
| DELETE | `two-factor-authentication` | `TwoFactorAuthenticationController@destroy` |
| GET | `two-factor-recovery-codes` | `TwoFactorRecoveryCodeController@index` |
| POST | `two-factor-recovery-codes` | `TwoFactorRecoveryCodeController@store` |

**Email Verification** (`Features::emailVerification()`):

| Method | URI | Controller@Method |
|:-------|:----|:------------------|
| GET | `email/verify/{id}/{hash}` | `EmailVerificationController@verify` |

**Email Verification (Authenticated)** (`Features::emailVerification()`):

| Method | URI | Controller@Method |
|:-------|:----|:------------------|
| POST | `email/verification-notification` | `EmailVerificationController@sendVerificationNotification` |

**Notifications** (`Features::notifications()`):

| Method | URI | Controller@Method |
|:-------|:----|:------------------|
| GET | `notifications` | `NotificationController@index` |
| GET | `notifications/unread-count` | `NotificationController@unreadCount` |
| POST | `notifications/{id}/read` | `NotificationController@markAsRead` |
| POST | `notifications/read-all` | `NotificationController@markAllAsRead` |
| DELETE | `notifications/{id}` | `NotificationController@destroy` |
| GET | `notification-preferences` | `NotificationPreferenceController@show` |
| PUT | `notification-preferences` | `NotificationPreferenceController@update` |

**Newsletter Management** (`Features::newsletterSubscription()`):

| Method | URI | Controller@Method |
|:-------|:----|:------------------|
| GET | `user/newsletter` | `NewsletterController@show` |
| PUT | `user/newsletter` | `NewsletterController@update` |

### Response Shapes

**UserResource:**

```json
{
    "id": "uuid",
    "name": "John Doe",
    "email": "john@example.com",
    "phone": "+1234567890",
    "is_guest": false,
    "phone_country": "US",
    "email_verified_at": "2026-01-01T00:00:00.000000Z",
    "locale": "en",
    "timezone": "UTC",
    "language": "en",
    "profile_photo_url": "https://ui-avatars.com/api/?name=John+Doe",
    "two_factor_enabled": false,
    "current_team": {},
    "all_teams": [],
    "created_at": "2026-01-01T00:00:00.000000Z",
    "updated_at": "2026-01-01T00:00:00.000000Z"
}
```
> [!NOTE]
> The `current_team` and `all_teams` fields are only included when `Features::hasTeamFeatures()` is enabled. When the `teams` feature is disabled, these fields are omitted from the response entirely.

**TeamResource:**

```json
{
    "id": "uuid",
    "name": "My Team",
    "personal_team": true,
    "owner_id": "uuid",
    "user_role": "owner",
    "profile_photo_url": "https://ui-avatars.com/api/?name=My+Team",
    "created_at": "2026-01-01T00:00:00.000000Z",
    "updated_at": "2026-01-01T00:00:00.000000Z"
}
```

**TeamMemberResource:**

```json
{
    "id": "uuid",
    "name": "Jane Doe",
    "email": "jane@example.com",
    "profile_photo_url": "https://...",
    "role": "admin"
}
```

**TeamInvitationResource:**

```json
{
    "id": "uuid",
    "team_id": "uuid",
    "email": "invite@example.com",
    "role": "member",
    "token": "random-token-string",
    "created_at": "2026-01-01T00:00:00.000000Z",
    "updated_at": "2026-01-01T00:00:00.000000Z"
}
```

**SessionResource:**

```json
{
    "id": "uuid",
    "ip_address": "127.0.0.1",
    "user_agent": "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)",
    "is_current_device": true,
    "last_used_at": "2026-01-01T00:00:00.000000Z",
    "created_at": "2026-01-01T00:00:00.000000Z"
}
```

**NotificationResource:**

```json
{
    "id": "uuid",
    "type": "App\\Notifications\\MonitorDown",
    "data": {},
    "read_at": null,
    "created_at": "2026-01-01T00:00:00.000000Z"
}
```

**Auth responses** (register / login):

```json
{
    "data": {
        "user": {},
        "token": "1|abcdef123456..."
    },
    "message": "Login successful"
}
```

**Two-factor challenge response** (login when 2FA is enabled):

```json
{
    "two_factor": true,
    "two_factor_token": "eyJpdiI6..."
}
```

## Action Contracts

All 18 contracts live in `FlutterSdk\MagicStarter\Contracts`. The service provider binds each to its default implementation. Override any contract by binding your own class in `AppServiceProvider` after publishing the stubs.

| Contract | Method Signature | Published Stub |
|:---------|:-----------------|:---------------|
| `CreatesUsers` | `create(array $input): Authenticatable` | `CreateUser.php` |
| `UpdatesUserProfiles` | `update(Authenticatable $user, array $input): void` | `UpdateUserProfile.php` |
| `UpdatesUserPasswords` | `update(Authenticatable $user, array $input): void` | `UpdateUserPassword.php` |
| `DeletesUsers` | `delete(Authenticatable $user): void` | `DeleteUser.php` |
| `CreatesTeams` | `create(Authenticatable $user, array $input): Model` | `CreateTeam.php` |
| `UpdatesTeams` | `update(Authenticatable $user, Model $team, array $input): void` | `UpdateTeam.php` |
| `DeletesTeams` | `delete(Model $team): void` | `DeleteTeam.php` |
| `AddsTeamMembers` | `add(Authenticatable $user, Model $team, string $email, string $role): void` | `AddTeamMember.php` |
| `InvitesTeamMembers` | `invite(Authenticatable $user, Model $team, string $email, string $role): Model` | `InviteTeamMember.php` |
| `RemovesTeamMembers` | `remove(Authenticatable $user, Model $team, Model $teamMember): void` | `RemoveTeamMember.php` |
| `UpdatesTeamMemberRoles` | `update(Authenticatable $user, Model $team, Model $teamMember, string $role): void` | `UpdateTeamMemberRole.php` |
| `EnablesTwoFactorAuthentication` | `__invoke(Authenticatable $user): array` | N/A (internal) |
| `ConfirmsTwoFactorAuthentication` | `__invoke(Authenticatable $user, string $code): void` | N/A (internal) |
| `DisablesTwoFactorAuthentication` | `__invoke(Authenticatable $user): void` | N/A (internal) |
| `GeneratesNewRecoveryCodes` | `__invoke(Authenticatable $user): array` | N/A (internal) |
| `CreatesGuestUsers` | `create(array $input): Authenticatable` | N/A (internal) |
| `SendsOtpCodes` | `send(string $phone, string $code): void` | N/A (internal) |
| `VerifiesOtpCodes` | `verify(string $phone, string $code): bool` | N/A (internal) |

## Models

The package ships with 6 Eloquent models. `Team`, `TeamInvitation`, and `TeamUser` are concrete and ready to use out of the box. To customize, publish model stubs via `vendor:publish --tag=magic-starter-models` and override the model class in the config.

**`Team`** — `FlutterSdk\MagicStarter\Models\Team`

- Fillable: `user_id`, `name`, `personal_team`, `profile_photo_path`
- Appends: `profile_photo_url`
- Casts: `personal_team` → `boolean`
- Relations: `owner()` → BelongsTo (user model), `users()` → BelongsToMany (with `role` pivot), `invitations()` → HasMany
- Attributes: `profilePhotoUrl()` computed attribute with ui-avatars fallback; `defaultProfilePhotoUrl()`

**`TeamInvitation`** — `FlutterSdk\MagicStarter\Models\TeamInvitation`

- Fillable: `email`, `role`, `token`, `expires_at`
- Casts: `expires_at` → `datetime`
- Relations: `team()` → BelongsTo
- Methods: `isExpired(): bool`, `scopeValid(Builder $query): Builder`

**`TeamUser`** — `FlutterSdk\MagicStarter\Models\TeamUser`

- Extends `Pivot`, table: `team_user`

**`PersonalAccessToken`** — `FlutterSdk\MagicStarter\Models\PersonalAccessToken`

- Extends Sanctum's built-in token model
- Additional fields: `ip_address`, `user_agent`
- Registered automatically as the Sanctum token model in the service provider

**`NotificationSetting`** — `FlutterSdk\MagicStarter\Models\NotificationSetting`

- Fillable: `notifiable_id`, `notifiable_type`, `type`, `channel`, `is_enabled`
- Casts: `is_enabled` → `boolean`
- Relations: `notifiable()` → MorphTo
- Stores sparse per-user overrides; rows only exist when the user differs from the registry default

**`NewsletterSubscriber`** — `FlutterSdk\MagicStarter\Models\NewsletterSubscriber`

- Fillable: `email`, `is_active`, `source`
- Casts: `is_active` → `boolean`
- Created automatically during registration when the `newsletter-subscription` feature is enabled and the user opts in

## User Traits

**`HasTeams`** — `FlutterSdk\MagicStarter\Traits\HasTeams`

| Method | Returns | Description |
|:-------|:--------|:------------|
| `ownedTeams()` | HasMany | Teams where this user is the owner |
| `teams()` | BelongsToMany | Teams joined as a member via the `team_user` pivot (includes `role` and timestamps) |
| `personalTeam()` | ?Model | First owned team with `personal_team = true` |
| `currentTeam()` | BelongsTo | Team referenced by `current_team_id` |
| `allTeams()` | Collection | Merged and deduplicated owned and member teams |
| `getCurrentTeamOrPersonal()` | ?Model | Active current team, falling back to the personal team |
| `belongsToTeam(Model $team)` | bool | Whether the user belongs to the given team (owned or as a member) |
| `ownsTeam(Model $team)` | bool | Whether the user owns the given team |
| `hasTeamRole(Model $team, string $role)` | bool | Whether the user has the given role on the given team |

**`HasProfilePhoto`** — `FlutterSdk\MagicStarter\Traits\HasProfilePhoto`

| Method | Returns | Description |
|:-------|:--------|:------------|
| `getProfilePhotoUrlAttribute()` | string | Full storage URL, or `defaultProfilePhotoUrl()` when no photo is set |
| `defaultProfilePhotoUrl()` | string | Avatar URL generated from name initials via the configured `ui_avatars_url` |

**`HasNotifications`** — `FlutterSdk\MagicStarter\Traits\HasNotifications`

| Method | Returns | Description |
|:-------|:--------|:------------|
| `notificationSettings()` | MorphMany | All `NotificationSetting` records for this user |
| `prefers(string $type, string $channel)` | bool | `true` if the user has the channel enabled for the given notification type |
| `notificationPreferenceMatrix()` | array | Full matrix of registered types and channels with user overrides applied |
| `routeNotificationForOneSignal()` | array | Returns `['include_external_user_ids' => ['user_' . $this->id]]` for OneSignal routing |

**`TwoFactorAuthenticatable`** — `FlutterSdk\MagicStarter\Traits\TwoFactorAuthenticatable`

| Method | Returns | Description |
|:-------|:--------|:------------|
| `twoFactorSecret()` | string | Decrypted TOTP secret |
| `recoveryCodes()` | array | Decrypted recovery codes |
| `hasTwoFactorEnabled()` | bool | Whether 2FA is confirmed and active |
| `replaceRecoveryCode(string $code)` | void | Replace a used recovery code with a new one |

**`HasGuestSupport`** — `FlutterSdk\MagicStarter\Traits\HasGuestSupport`

| Method | Returns | Description |
|:-------|:--------|:------------|
| `isGuest()` | bool | Whether the user is a guest (has `is_guest` flag set to `true`) |
| `isRegistered()` | bool | Whether the user has credentials (email+password or phone+password) |

**`MustVerifyEmail`** — `FlutterSdk\MagicStarter\Traits\MustVerifyEmail`

| Method | Returns | Description |
|:-------|:--------|:------------|
| `hasVerifiedEmail()` | bool | Whether `email_verified_at` is set. **Guest users** (those with `isGuest() === true`) always return `true` — they have no email to verify, so the `verified` middleware never blocks them. |
| `markEmailAsVerified()` | bool | Sets email_verified_at to now(), fires Verified event, returns true |
| `sendEmailVerificationNotification()` | void | Dispatches VerifyEmailNotification |
| `getEmailForVerification()` | string | Returns the user's email |

## Form Requests

The package includes 23 form requests. All validation rules are array-style (never pipe-delimited).

| Request | Validation Rules |
|:--------|:-----------------|
| `RegisterRequest` | `name`: required, string, max:255. `email`: required, email, max:255, unique:users. `password`: required, min:8, letters, numbers, mixedCase, confirmed. `locale`: nullable, in:supported_locales. `timezone`: nullable, valid IANA timezone. `subscribe_newsletter`: nullable, boolean. |
| `LoginRequest` | `email`: required, string, email. `password`: required, string. |
| `SocialLoginRequest` | `access_token`: required_without:authorization_code, string. `authorization_code`: required_without:access_token, string. |
| `ForgotPasswordRequest` | `email`: required, email. |
| `ResetPasswordRequest` | `token`: required. `email`: required, email. `password`: required, confirmed, min:8, letters, numbers, mixedCase. |
| `UpdateProfileRequest` | `name`: required, string, min:2, max:255. `phone`: nullable, string, max:20, E164 format. `timezone`: nullable, valid IANA timezone. `language`: nullable, in:supported_locales. |
| `UpdatePasswordRequest` | `current_password`: required, string (verified against current hash). `password`: required, min:8, letters, numbers, mixedCase, confirmed. |
| `UpdateProfilePhotoRequest` | `photo`: required, image, max:1024 (KB). |
| `DeleteAccountRequest` | `password`: required, string (must match current password). |
| `StoreTeamRequest` | `name`: required, string, max:255. |
| `UpdateTeamRequest` | `name`: required, string, max:255. |
| `SwitchTeamRequest` | `team_id`: required, uuid, exists:teams,id. |
| `StoreTeamInvitationRequest` | `email`: required, email, max:255. `role`: required, string, in:admin,editor,member. |
| `UpdateTeamMemberRequest` | `role`: required, string, in:admin,editor,member. |
| `UpdateTeamPhotoRequest` | `photo`: required, image, max:2048 (KB). |
| `UpdateNotificationPreferenceRequest` | Single: `type` (string), `channel` (string), `is_enabled` (boolean). Bulk: `preferences` array where each item has the same three fields. |
| `ConfirmTwoFactorRequest` | `code`: required, string. |
| `DisableTwoFactorRequest` | `password`: required, string (must match current password). |
| `TwoFactorChallengeRequest` | `two_factor_token`: required, string. `code`: required_without:recovery_code. `recovery_code`: required_without:code. |
| `DestroyOtherSessionsRequest` | `password`: required, string (must match current password). |
| `GuestLoginRequest` | `device_id`: required, string, max:255. |
| `SendOtpRequest` | `phone`: required, string, E164 format. |
| `VerifyOtpRequest` | `phone`: required, string, E164 format. `code`: required, string, size:6. |

## Publishable Migrations

18 migration stubs are published with timestamps applied at install time. They are never auto-loaded by the package — you control when they run.

All `create_*` migrations use `Schema::hasTable()` guards — they safely skip table creation if the table already exists. All column types (primary keys, foreign keys) automatically respect the `use_uuids` config setting via `MigrationHelper`.

> [!NOTE]
> You can safely run these migrations against an existing database. Core migrations (`create_users_table`, `create_personal_access_tokens_table`) will skip if the tables already exist. Feature migrations use `add_*` column changes that are idempotent.

## Testing

The package uses PHPUnit with Orchestra Testbench.

```shell
composer install
composer test        # Run PHPUnit (454 tests, 1242 assertions)
composer lint        # Check code style with Pint
composer lint:fix    # Auto-fix code style violations
composer analyse     # Run PHPStan
```

Test coverage includes:

- Feature toggles (`FeaturesTest`)
- Model resolution and route control (`MagicStarterTest`)
- Service provider boot and config merge (`ServiceProviderTest`)
- Conditional route registration (`RouteRegistrationTest`)
- Install command — interactive and non-interactive modes, UUID/integer key strategy (`InstallCommandTest`)
- All 20 controllers with full HTTP tests, including 403, 404, and 422 negative cases
- Model relationships, casts, and scopes (`ModelsTest`)
- User traits — `HasTeamsTest`, `HasProfilePhotoTest`, `HasNotificationsTest`
- All 23 form request validation rules
- Action stub contracts (`ActionStubsTest`)
- Two-factor authentication — trait, actions, controllers, challenge flow (`TwoFactorAuthenticatableTest`, `TwoFactorActionsTest`, `TwoFactorAuthenticationControllerTest`, `TwoFactorChallengeControllerTest`, `TwoFactorRecoveryCodeControllerTest`, `AuthControllerTwoFactorLoginTest`)
- Localized personal team creation — `CreatePersonalTeamListenerTest` (English/Turkish team names, idempotency, owner role)
- Guest personal team creation — `GuestAuthControllerTest` (team creation on first login, skip on returning guest, disabled when teams feature off)
- Guest end-to-end lifecycle — `GuestLifecycleTest` (token works on all protected endpoints, returning guest gets fresh token, device info stored, team data included)
- Email verification guest bypass — `MustVerifyEmailTest` (guests pass `hasVerifiedEmail()` without email)
- UserResource team field gating — `UserResourceTest` (fields present/absent based on team feature toggle)
