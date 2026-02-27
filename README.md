# Magic Starter Laravel

A modular Laravel backend package providing authentication, team management, profile settings, session management, and social login — inspired by [Laravel Jetstream](https://jetstream.laravel.com)'s architecture with feature toggles, action contracts, and publishable assets.

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
  - [Notifications](#notifications)
  - [Newsletter Subscription](#newsletter-subscription)
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

<a name="requirements"></a>
## Requirements

| Dependency | Version |
|:-----------|:--------|
| PHP | ^8.2 |
| Laravel | ^11.0 \| ^12.0 |
| Laravel Sanctum | ^4.0 |
| Laravel Socialite | ^5.0 (bundled) |

<a name="installation"></a>
## Installation

<a name="composer-setup"></a>
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

<a name="running-the-install-command"></a>
### Running the Install Command

The install command publishes config, migrations, action stubs, and model stubs in one step:

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

The `--features` option accepts: `teams`, `profile-photos`, `sessions`, `social-login`, `newsletter-subscription`, `extended-profile`, `notifications`. When `--all` is passed, omitting `--features` enables everything.

> [!NOTE]
> When neither `--uuid` nor `--no-uuid` is provided, the installer auto-detects your existing `users` table schema. If no `users` table exists (fresh install), UUID is used by default.

The command publishes the following assets, each with its own tag for granular control:

| Asset | Destination | Publish Tag |
|:------|:------------|:------------|
| Config | `config/magic-starter.php` | `magic-starter-config` |
| Migrations | `database/migrations/` | `magic-starter-migrations` |
| Action Stubs | `app/Actions/MagicStarter/` | `magic-starter-stubs` |
| Model Stubs | `app/Models/` | `magic-starter-models` |

You can publish assets individually at any time:

```shell
php artisan vendor:publish --tag=magic-starter-config
php artisan vendor:publish --tag=magic-starter-migrations
php artisan vendor:publish --tag=magic-starter-stubs
php artisan vendor:publish --tag=magic-starter-models
```

<a name="user-model-setup"></a>
### User Model Setup

Add the relevant traits to your `User` model. At a minimum, add `HasTeams` and `HasProfilePhoto`:

```php
use FlutterSdk\MagicStarter\Traits\HasTeams;
use FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
use FlutterSdk\MagicStarter\Traits\HasNotifications;

class User extends Authenticatable
{
    use HasTeams;
    use HasProfilePhoto;
    use HasNotifications;

    protected $appends = [
        'profile_photo_url',
    ];
}
```

Only add `HasNotifications` when the `notifications` feature is enabled. The other two traits are safe to include regardless of enabled features.

<a name="binding-action-contracts"></a>
### Binding Action Contracts

After publishing the action stubs, bind each contract to its concrete implementation in your `AppServiceProvider`:

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

<a name="configuration"></a>
## Configuration

The configuration file is at `config/magic-starter.php`.

<a name="feature-toggles"></a>
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
```

**All 7 features and their toggle methods:**

| Feature Key | Enable Method | Check Method |
|:------------|:--------------|:-------------|
| `teams` | `Features::teams()` | `Features::hasTeamFeatures()` |
| `profile-photos` | `Features::profilePhotos()` | `Features::hasProfilePhotoFeatures()` |
| `sessions` | `Features::sessions()` | `Features::hasSessionFeatures()` |
| `social-login` | `Features::socialLogin()` | `Features::hasSocialLoginFeatures()` |
| `newsletter-subscription` | `Features::newsletterSubscription()` | `Features::hasNewsletterSubscriptionFeatures()` |
| `extended-profile` | `Features::extendedProfile()` | `Features::hasExtendedProfileFeatures()` |
| `notifications` | `Features::notifications()` | `Features::hasNotificationFeatures()` |

<a name="all-config-keys"></a>
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
| `supported_timezones` | Curated IANA identifiers | Timezones accepted by timezone validation rules |
| `profile_photo_disk` | `'public'` | Storage disk for user profile photos |
| `team_photo_disk` | `'public'` | Storage disk for team photos |
| `profile_photo_path` | `'profile-photos'` | Directory within disk for user profile photos |
| `team_photo_path` | `'team-photos'` | Directory within disk for team photos |
| `ui_avatars_url` | `'https://ui-avatars.com/api/'` | Fallback avatar generation service URL |
| `route_prefix` | `''` | Global prefix applied to all package routes |
| `invitation_expiry_days` | `7` | Days until a team invitation token expires |
| `token_expiration_minutes` | `null` | Sanctum personal access token TTL in minutes; `null` means no expiry |

Override model classes via environment variables:

```env
MAGIC_STARTER_USER_MODEL=App\Models\User
MAGIC_STARTER_FRONTEND_URL=https://app.example.com
```

<a name="route-prefix"></a>
### Route Prefix

Prefix all package routes to avoid collisions with your application's existing routes:

```php
'route_prefix' => 'api/v1',
```

```env
MAGIC_STARTER_ROUTE_PREFIX=api/v1
```

<a name="storage-disks"></a>
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

<a name="architecture"></a>
## Architecture

<a name="directory-structure"></a>
### Directory Structure

```
magic-starter-laravel/
├── config/
│   └── magic-starter.php                  # Package configuration
├── database/
│   └── migrations/                        # 15 publishable migration stubs
├── src/
│   ├── Console/
│   │   └── InstallCommand.php             # magic-starter:install
│   ├── Contracts/                         # 11 action interfaces
│   ├── Http/
│   │   ├── Controllers/                   # 11 API controllers
│   │   ├── Requests/                      # 16 form requests
│   │   └── Resources/                     # 6 API resources
│   ├── Listeners/
│   │   ├── CreatePersonalTeamListener.php # Fires on Registered event
│   │   └── GateNotificationChannels.php   # Fires on NotificationSending event
│   ├── Models/                            # Team, TeamInvitation, TeamUser,
│   │   │                                  #   PersonalAccessToken, NotificationSetting,
│   │   │                                  #   NewsletterSubscriber
│   ├── NotificationPreferenceRegistry.php  # Notification type/channel matrix
│   ├── Traits/                            # HasTeams, HasProfilePhoto, HasNotifications
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

<a name="service-provider"></a>
### Service Provider

`MagicStarterServiceProvider` handles the full bootstrap lifecycle:

- Merges the package config with any application overrides.
- Binds all 11 action contracts to their default stub implementations in the IoC container.
- Sets the Sanctum `PersonalAccessToken` model to the package's extended version (adds `ip_address` and `user_agent`).
- Configures the password reset URL to point at the configured `frontend_url`.
- Registers event listeners (`CreatePersonalTeamListener`, `GateNotificationChannels`).
- Loads routes conditionally based on enabled features, unless `ignoreRoutes()` has been called.
- Registers the four publishing groups and the `magic-starter:install` Artisan command.

<a name="magicstarter-class"></a>
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

<a name="action-contract-pattern"></a>
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

<a name="dynamic-model-resolution"></a>
### Dynamic Model Resolution

The package never hardcodes `App\Models\User`. All model references go through the `MagicStarter` class:

```php
MagicStarter::userModel();            // string — configured user model class
MagicStarter::teamModel();            // string — configured team model class
MagicStarter::membershipModel();      // string — configured pivot model class
MagicStarter::teamInvitationModel();  // string — configured invitation model class
```

<a name="event-listeners"></a>
### Event Listeners

The service provider registers two event listeners automatically.

**`CreatePersonalTeamListener`** — listens on `Illuminate\Auth\Events\Registered`

Active when the `teams` feature is enabled. After a user registers, this listener creates a personal team, attaches the user as owner, and sets `current_team_id` on the user record.

**`GateNotificationChannels`** — listens on `Illuminate\Notifications\Events\NotificationSending`

Active when the `notifications` feature is enabled. Before a notification is delivered, this listener checks the `NotificationPreferenceRegistry` to see if the type and channel are registered, then calls `prefers()` on the notifiable model. If the user has disabled that channel for that notification type, the listener blocks delivery by returning `false`.

<a name="notification-preference-registry"></a>
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

<a name="route-control"></a>
### Route Control

To completely disable package route loading and define your own:

```php
// Call this before MagicStarterServiceProvider boots — typically in a service
// provider that is registered earlier in config/app.php.
\FlutterSdk\MagicStarter\MagicStarter::ignoreRoutes();
```

<a name="features"></a>
## Features

<a name="authentication"></a>
### Authentication

Provides registration, login, logout, current user retrieval, and team switching via Sanctum token authentication. These routes are always registered regardless of which optional features are enabled.

- **Register**: Creates the user via the `CreatesUsers` contract, fires the `Registered` event (which triggers personal team creation if the `teams` feature is active), and returns a Sanctum token.
- **Login**: Validates credentials, issues a Sanctum token. The token stores `ip_address` and `user_agent` when the `sessions` feature is enabled.
- **Logout**: Revokes the current personal access token.
- **Current User**: Returns the authenticated user with current team and all teams.
- **Switch Team**: Updates `current_team_id` on the user record. Requires `teams` feature.

<a name="social-login"></a>
### Social Login

> Requires `Features::socialLogin()` to be useful. The route is always registered, but OAuth providers must be configured separately via Socialite.

Accepts either an `access_token` (for mobile OAuth flows) or an `authorization_code` (for server-side flows). If the authenticated social account's email already exists in the database, the user is logged in. Otherwise, a new user is created via the `CreatesUsers` contract.

<a name="password-reset"></a>
### Password Reset

Standard Laravel password reset flow, always active:

- **Forgot Password**: Calls `Password::sendResetLink()`. The reset link points to `config('magic-starter.frontend_url')`.
- **Reset Password**: Validates the token, resets the password, fires the `PasswordReset` event.

<a name="teams"></a>
### Teams

> Requires `Features::teams()` enabled.

Full team CRUD with authorization gates:

- **List**: Returns all teams the authenticated user belongs to (owned and member).
- **Create**: Via `CreatesTeams` contract.
- **Show**: With `view` gate authorization.
- **Update**: Via `UpdatesTeams` contract, protected by the `update` gate.
- **Delete**: Via `DeletesTeams` contract, protected by the `delete` gate. Prevents deleting the personal team or the last team. Auto-switches `current_team_id` to the next available team.
- **Switch Team**: Updates `current_team_id`. The target team must exist and the user must belong to it.

<a name="team-members"></a>
### Team Members

> Requires `Features::teams()` enabled.

- **List**: All current members including the owner, who is listed with the `owner` role.
- **Update Role**: Via `UpdatesTeamMemberRoles` contract. Changes the pivot `role`. Cannot change the owner's role.
- **Remove**: Via `RemovesTeamMembers` contract. Cannot remove the owner.
- **Leave**: Member voluntarily leaves the team. The owner cannot leave — they must transfer ownership or delete the team. Auto-switches `current_team_id` after leaving.

<a name="team-invitations"></a>
### Team Invitations

> Requires `Features::teams()` enabled.

Token-based invitation system with configurable expiry:

- **List**: All pending (non-expired) invitations for a team.
- **Send**: Via `InvitesTeamMembers` contract. Prevents sending duplicate invitations to the same address and prevents inviting users who are already members.
- **Cancel**: Deletes a pending invitation.
- **Accept**: Token-based acceptance endpoint. Attaches the authenticated user to the team with the invitation's role, then deletes the invitation.

Invitations expire after `config('magic-starter.invitation_expiry_days')` days (default: 7).

<a name="profile-management"></a>
### Profile Management

Always active. These routes require `auth:sanctum`:

- **Update Profile**: Via `UpdatesUserProfiles` contract. Accepted fields: `name`, `phone` (E.164 format), `timezone` (from supported list), `language` (from supported locales).
- **Update Password**: Via `UpdatesUserPasswords` contract. Requires current password verification.
- **Delete Account**: Via `DeletesUsers` contract. Requires password confirmation.

<a name="profile-photo"></a>
### Profile Photo

> Requires `Features::profilePhotos()` enabled.

- **Upload**: Accepts a JPEG/PNG image up to 1 MB. Stores it on the configured disk under `profile_photo_path/`. Replaces any previously uploaded photo.
- **Delete**: Removes the file from storage and clears `profile_photo_path` on the user record.

When no photo is set, `getProfilePhotoUrlAttribute()` generates a fallback avatar via the configured `ui_avatars_url` using the user's name initials.

<a name="team-photo"></a>
### Team Photo

> Requires both `Features::profilePhotos()` and `Features::teams()` enabled.

- **Upload**: Accepts an image up to 2 MB. Stored on the configured `team_photo_disk` under `team_photo_path/`. Replaces any previous team photo.
- **Delete**: Removes the file from storage and clears `profile_photo_path` on the team record.

Fallback avatar generation works the same way as user photos, using the team name.

<a name="session-management"></a>
### Session Management

> Requires `Features::sessions()` enabled.

The package treats each Sanctum personal access token as a "session". The extended `PersonalAccessToken` model stores `ip_address` and `user_agent` alongside each token.

- **List**: All active tokens for the authenticated user, with a boolean `is_current_device` flag on the current token.
- **Revoke One**: Deletes a specific token by its ID.
- **Revoke Others**: Deletes all tokens except the currently active one.

<a name="notifications"></a>
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

<a name="newsletter-subscription"></a>
### Newsletter Subscription

> Requires `Features::newsletterSubscription()` enabled.

Adds a `subscribe_newsletter` boolean field to the registration payload. When `true`, the `CreatesUsers` action (or the listener) creates a `NewsletterSubscriber` record tied to the new user's email with `source` set to `register`.

<a name="api-reference"></a>
## API Reference

All routes are prefixed by `config('magic-starter.route_prefix')`. The examples below assume no prefix is configured.

<a name="public-routes"></a>
### Public Routes

Rate-limited at `throttle:5,1` (5 requests per minute):

| Method | URI | Controller@Method | Request |
|:-------|:----|:------------------|:--------|
| POST | `auth/register` | `AuthController@register` | `RegisterRequest` |
| POST | `auth/login` | `AuthController@login` | `LoginRequest` |
| POST | `auth/social/{provider}` | `AuthController@socialLogin` | `SocialLoginRequest` |
| POST | `auth/forgot-password` | `PasswordResetController@sendResetLinkEmail` | `ForgotPasswordRequest` |
| POST | `auth/reset-password` | `PasswordResetController@reset` | `ResetPasswordRequest` |

<a name="protected-routes"></a>
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

<a name="response-shapes"></a>
### Response Shapes

**UserResource:**

```json
{
    "id": "uuid",
    "name": "John Doe",
    "email": "john@example.com",
    "phone": "+1234567890",
    "email_verified_at": "2026-01-01T00:00:00.000000Z",
    "locale": "en",
    "timezone": "UTC",
    "language": "en",
    "profile_photo_url": "https://ui-avatars.com/api/?name=John+Doe",
    "current_team": {},
    "all_teams": [],
    "created_at": "2026-01-01T00:00:00.000000Z",
    "updated_at": "2026-01-01T00:00:00.000000Z"
}
```

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

<a name="action-contracts-reference"></a>
## Action Contracts

All 11 contracts live in `FlutterSdk\MagicStarter\Contracts`. The service provider binds each to its default stub implementation, which you replace with your own logic after publishing.

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

<a name="models-reference"></a>
## Models

The package ships with 6 Eloquent models. `Team`, `TeamInvitation`, and `TeamUser` are abstract — you extend them via the published model stubs in `app/Models/`.

**`Team`** — `FlutterSdk\MagicStarter\Models\Team` (abstract)

- Appends: `profile_photo_url`
- Casts: `personal_team` → `boolean`
- Relations: `owner()` → BelongsTo (user model), `users()` → BelongsToMany (with `role` pivot), `invitations()` → HasMany
- Attributes: `profilePhotoUrl()` computed attribute with ui-avatars fallback; `defaultProfilePhotoUrl()`
- Your stub extends this and adds `fillable`: `user_id`, `name`, `personal_team`, `profile_photo_path`

**`TeamInvitation`** — `FlutterSdk\MagicStarter\Models\TeamInvitation` (abstract)

- Fillable: `email`, `role`, `token`, `expires_at`
- Casts: `expires_at` → `datetime`
- Relations: `team()` → BelongsTo
- Methods: `isExpired(): bool`, `scopeValid(Builder $query): Builder`

**`TeamUser`** — `FlutterSdk\MagicStarter\Models\TeamUser` (abstract)

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

<a name="user-traits"></a>
## User Traits

<a name="has-teams"></a>
**`HasTeams`** — `FlutterSdk\MagicStarter\Traits\HasTeams`

| Method | Returns | Description |
|:-------|:--------|:------------|
| `ownedTeams()` | HasMany | Teams where this user is the owner |
| `teams()` | BelongsToMany | Teams joined as a member via the `team_user` pivot (includes `role` and timestamps) |
| `personalTeam()` | ?Model | First owned team with `personal_team = true` |
| `currentTeam()` | BelongsTo | Team referenced by `current_team_id` |
| `allTeams()` | Collection | Merged and deduplicated owned and member teams |
| `getCurrentTeamOrPersonal()` | ?Model | Active current team, falling back to the personal team |

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

<a name="form-requests"></a>
## Form Requests

The package includes 16 form requests. All validation rules are array-style (never pipe-delimited).

| Request | Validation Rules |
|:--------|:-----------------|
| `RegisterRequest` | `name`: required, string, max:255. `email`: required, email, max:255, unique:users. `password`: required, min:8, letters, numbers, mixedCase, confirmed. `locale`: nullable, in:supported_locales. `timezone`: nullable, in:supported_timezones. `subscribe_newsletter`: nullable, boolean. |
| `LoginRequest` | `email`: required, string, email. `password`: required, string. |
| `SocialLoginRequest` | `access_token`: required_without:authorization_code, string. `authorization_code`: required_without:access_token, string. |
| `ForgotPasswordRequest` | `email`: required, email. |
| `ResetPasswordRequest` | `token`: required. `email`: required, email. `password`: required, confirmed, min:8, letters, numbers, mixedCase. |
| `UpdateProfileRequest` | `name`: required, string, min:2, max:255. `phone`: nullable, string, max:20, E164 format. `timezone`: nullable, in:supported_timezones. `language`: nullable, in:supported_locales. |
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

<a name="publishable-migrations"></a>
## Publishable Migrations

15 migration stubs are published with timestamps applied at install time. They are never auto-loaded by the package — you control when they run.

All `create_*` migrations use `Schema::hasTable()` guards — they safely skip table creation if the table already exists. All column types (primary keys, foreign keys) automatically respect the `use_uuids` config setting via `MigrationHelper`.

> [!NOTE]
> You can safely run these migrations against an existing database. Core migrations (`create_users_table`, `create_personal_access_tokens_table`) will skip if the tables already exist. Feature migrations use `add_*` column changes that are idempotent.

<a name="testing"></a>
## Testing

The package uses PHPUnit with Orchestra Testbench.

```shell
composer install
composer test        # Run PHPUnit (273 tests, 729 assertions)
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
- All 11 controllers with full HTTP tests, including 403, 404, and 422 negative cases
- Model relationships, casts, and scopes (`ModelsTest`)
- User traits — `HasTeamsTest`, `HasProfilePhotoTest`, `HasNotificationsTest`
- All 16 form request validation rules
- Action stub contracts (`ActionStubsTest`)


## Two-Factor Authentication

Magic Starter provides complete support for TOTP-based two-factor authentication (2FA). When enabled, users must provide a 6-digit code from their authenticator app (like Google Authenticator or Authy) to complete the login process.

### Enabling Two-Factor Authentication

To enable 2FA for a user, they must call the store endpoint. The response will include a QR code SVG, URL, and recovery codes. The user must then confirm the setup by providing a valid code.

```http
POST /api/auth/two-factor-authentication
Authorization: Bearer {token}
```

```json
{
    "data": {
        "secret": "...",
        "qr_url": "...",
        "qr_svg": "...",
        "recovery_codes": [...]
    },
    "message": "Two-factor authentication enabled. Please confirm with your authenticator app."
}
```

After scanning the QR code, the user must confirm it:

```http
POST /api/auth/two-factor-authentication/confirm
Authorization: Bearer {token}

{
    "code": "123456"
}
```

### Logging in with Two-Factor Authentication

When a user with 2FA enabled logs in, they will receive a `two_factor` challenge response instead of a Sanctum token.

```json
{
    "two_factor": true,
    "two_factor_token": "eyJpdiI6..."
}
```

The user must then submit this token along with their TOTP code (or a recovery code) to the challenge endpoint:

```http
POST /api/auth/two-factor-challenge

{
    "two_factor_token": "eyJpdiI6...",
    "code": "123456"
}
```

If successful, they will receive the normal authenticated response with their Sanctum token.

### Managing Recovery Codes

Users can view and regenerate their recovery codes:

```http
GET /api/auth/two-factor-recovery-codes
POST /api/auth/two-factor-recovery-codes
```

### Disabling Two-Factor Authentication

Users can disable 2FA by providing their current password:

```http
DELETE /api/auth/two-factor-authentication
Authorization: Bearer {token}

{
    "password": "current-password"
}
```