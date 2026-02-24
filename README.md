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
  - [Models](#models)
  - [Route Prefix](#route-prefix)
  - [Profile Photo Disk](#profile-photo-disk)
- [Architecture](#architecture)
  - [Directory Structure](#directory-structure)
  - [Action Contract Pattern](#action-contract-pattern)
  - [Dynamic Model Resolution](#dynamic-model-resolution)
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
  - [Session Management](#session-management)
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
| Laravel Sanctum | Required for token auth |
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

The install command publishes config, migrations, and action stubs in one step:

```shell
php artisan magic-starter:install
```

This publishes:

| Asset | Destination | Publish Tag |
|:------|:------------|:------------|
| Config | `config/magic-starter.php` | `magic-starter-config` |
| Migrations | `database/migrations/` | `magic-starter-migrations` |
| Action Stubs | `app/Actions/MagicStarter/` | `magic-starter-stubs` |

Use `--force` to overwrite existing files:

```shell
php artisan magic-starter:install --force
```

You can also publish assets individually:

```shell
php artisan vendor:publish --tag=magic-starter-config
php artisan vendor:publish --tag=magic-starter-migrations
php artisan vendor:publish --tag=magic-starter-stubs
```

<a name="user-model-setup"></a>
### User Model Setup

Add the package traits to your `User` model:

```php
use FlutterSdk\MagicStarter\Traits\HasTeams;
use FlutterSdk\MagicStarter\Traits\HasProfilePhoto;

class User extends Authenticatable
{
    use HasTeams;
    use HasProfilePhoto;

    protected $appends = [
        'profile_photo_url',
    ];
}
```

`HasTeams` provides: `ownedTeams()`, `teams()`, `personalTeam()`, `currentTeam()`, `allTeams()`, `getCurrentTeamOrPersonal()`.

`HasProfilePhoto` provides: `getProfilePhotoUrlAttribute()` with fallback to [ui-avatars.com](https://ui-avatars.com).

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
}
```

> [!NOTE]
> Published action stubs throw `RuntimeException` by default. You must implement the business logic in each action class.

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
],
```

When a feature is disabled, its routes are not registered and its functionality is unavailable. Authentication and profile management routes are always registered (they are core features).

You can check feature status programmatically:

```php
use FlutterSdk\MagicStarter\Features;

if (Features::hasTeamFeatures()) {
    // Team functionality is enabled
}

Features::enabled('teams');           // bool
Features::hasProfilePhotoFeatures();  // bool
Features::hasSessionFeatures();       // bool
Features::hasSocialLoginFeatures();   // bool
```

<a name="models"></a>
### Models

Override the default model classes used by the package:

```php
'models' => [
    'user' => \App\Models\User::class,
    'team' => \FlutterSdk\MagicStarter\Models\Team::class,
],
```

Or use environment variables:

```env
MAGIC_STARTER_USER_MODEL=App\Models\User
MAGIC_STARTER_TEAM_MODEL=App\Models\Team
```

If `models.user` is not set, the package falls back to `config('auth.providers.users.model')`.

<a name="route-prefix"></a>
### Route Prefix

All package routes can be prefixed:

```php
'route_prefix' => 'api/v1',
```

Or via environment:

```env
MAGIC_STARTER_ROUTE_PREFIX=api/v1
```

<a name="profile-photo-disk"></a>
### Profile Photo Disk

Configure the storage disk for profile photos:

```php
'profile_photo_disk' => 'public',
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
│   └── magic-starter.php              # Package configuration
├── database/
│   └── migrations/                    # 11 publishable migration stubs
├── src/
│   ├── Console/
│   │   └── InstallCommand.php         # magic-starter:install
│   ├── Contracts/                     # 10 action interfaces
│   ├── Http/
│   │   ├── Controllers/               # 8 API controllers
│   │   ├── Requests/                  # 14 form requests
│   │   └── Resources/                 # 5 API resources
│   ├── Models/                        # Team, TeamInvitation, TeamUser, PersonalAccessToken
│   ├── Traits/                        # HasTeams, HasProfilePhoto
│   ├── routes/
│   │   └── api.php                    # Conditional route registration
│   ├── Features.php                   # Feature toggle class
│   ├── MagicStarter.php               # Main facade (model resolution, route control)
│   └── MagicStarterServiceProvider.php
├── stubs/
│   └── actions/                       # 10 publishable action implementations
└── tests/                             # PHPUnit + Orchestra Testbench
```

<a name="action-contract-pattern"></a>
### Action Contract Pattern

Business logic is never hardcoded in controllers. Instead, controllers resolve action contracts from the IoC container:

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
MagicStarter::userModel();  // Returns configured user model class string
MagicStarter::teamModel();  // Returns configured team model class string
```

<a name="route-control"></a>
### Route Control

To completely disable package routes (e.g., to define your own):

```php
// In a service provider boot() method, BEFORE MagicStarterServiceProvider boots:
\FlutterSdk\MagicStarter\MagicStarter::ignoreRoutes();
```

<a name="features"></a>
## Features

<a name="authentication"></a>
### Authentication

Provides registration, login, logout, and current user retrieval via Sanctum token authentication.

- **Register**: Creates user via `CreatesUsers` contract, fires `Registered` event, returns token
- **Login**: Validates credentials, issues Sanctum token with optional device info storage
- **Logout**: Revokes current access token
- **Current User**: Returns authenticated user with teams

<a name="social-login"></a>
### Social Login

> Requires `Features::socialLogin()` to be useful (route is always registered, but providers must be configured).

Supports OAuth via Laravel Socialite. Accepts either `access_token` or `authorization_code` flow. Automatically creates new users through the `CreatesUsers` contract if the email doesn't exist.

<a name="password-reset"></a>
### Password Reset

Standard Laravel password reset flow:

- **Forgot Password**: Sends reset link email via `Password::sendResetLink()`
- **Reset Password**: Resets password, fires `PasswordReset` event

<a name="teams"></a>
### Teams

> Requires `Features::teams()` enabled.

Full team CRUD with authorization gates:

- **List**: Returns all teams the user belongs to (owned + member)
- **Create**: Via `CreatesTeams` contract
- **Show**: With `view` gate authorization
- **Update**: Via `UpdatesTeams` contract with `update` gate
- **Delete**: Via `DeletesTeams` contract with `delete` gate. Prevents deleting the last team. Auto-switches to next team if active team is deleted.
- **Switch Team**: Updates user's `current_team_id`

<a name="team-members"></a>
### Team Members

> Requires `Features::teams()` enabled.

- **List**: Shows all members including owner (with `owner` role)
- **Add**: Via `AddsTeamMembers` contract. Roles: `admin`, `editor`, `member`
- **Update Role**: Changes pivot role. Cannot change owner role.
- **Remove**: Via `RemovesTeamMembers` contract. Cannot remove owner.
- **Leave**: Member voluntarily leaves. Owner cannot leave (must transfer or delete). Auto-switches active team.

<a name="team-invitations"></a>
### Team Invitations

> Requires `Features::teams()` enabled.

Token-based invitation system:

- **List**: Pending invitations for a team
- **Send**: Via `InvitesTeamMembers` contract. Prevents duplicate invitations and inviting existing members.
- **Cancel**: Deletes pending invitation
- **Accept**: Token-based acceptance. Attaches user to team, deletes invitation.

<a name="profile-management"></a>
### Profile Management

- **Update Profile**: Via `UpdatesUserProfiles` contract. Fields: name, phone, timezone, language.
- **Update Password**: Via `UpdatesUserPasswords` contract
- **Delete Account**: Via `DeletesUsers` contract

<a name="profile-photo"></a>
### Profile Photo

> Requires `Features::profilePhotos()` enabled.

- **Upload**: Stores to configurable disk (`profile-photos/` directory), replaces previous
- **Delete**: Removes from storage, clears `profile_photo_path`

Fallback generates avatar via [ui-avatars.com](https://ui-avatars.com) using name initials.

<a name="session-management"></a>
### Session Management

> Requires `Features::sessions()` enabled.

Manages Sanctum personal access tokens as "sessions":

- **List**: All active tokens with device info and `is_current_device` flag
- **Revoke One**: Delete a specific token by ID
- **Revoke Others**: Delete all tokens except the current one

<a name="api-reference"></a>
## API Reference

All routes use the configured `route_prefix`. Examples below assume no prefix.

<a name="public-routes"></a>
### Public Routes

These routes are rate-limited (`throttle:5,1`):

| Method | URI | Action | Request |
|:-------|:----|:-------|:--------|
| POST | `auth/register` | `AuthController@register` | `RegisterRequest` |
| POST | `auth/login` | `AuthController@login` | `LoginRequest` |
| POST | `auth/social/{provider}` | `AuthController@socialLogin` | `SocialLoginRequest` |
| POST | `auth/forgot-password` | `PasswordResetController@sendResetLinkEmail` | `ForgotPasswordRequest` |
| POST | `auth/reset-password` | `PasswordResetController@reset` | `ResetPasswordRequest` |

<a name="protected-routes"></a>
### Protected Routes

All require `auth:sanctum` middleware.

**Auth:**

| Method | URI | Action |
|:-------|:----|:-------|
| POST | `auth/logout` | `AuthController@logout` |
| GET | `auth/user` | `AuthController@user` |

**Teams** (when `Features::teams()` enabled):

| Method | URI | Action |
|:-------|:----|:-------|
| GET | `teams` | `TeamController@index` |
| POST | `teams` | `TeamController@store` |
| GET | `teams/{team}` | `TeamController@show` |
| PUT | `teams/{team}` | `TeamController@update` |
| DELETE | `teams/{team}` | `TeamController@destroy` |
| PUT | `user/current-team` | `AuthController@switchTeam` |

**Team Members** (when `Features::teams()` enabled):

| Method | URI | Action |
|:-------|:----|:-------|
| GET | `teams/{team}/members` | `TeamMemberController@index` |
| PUT | `teams/{team}/members/{user}` | `TeamMemberController@update` |
| DELETE | `teams/{team}/members/{user}` | `TeamMemberController@destroy` |
| DELETE | `teams/{team}/leave` | `TeamMemberController@leave` |

**Team Invitations** (when `Features::teams()` enabled):

| Method | URI | Action |
|:-------|:----|:-------|
| GET | `teams/{team}/invitations` | `TeamInvitationController@index` |
| POST | `teams/{team}/invitations` | `TeamInvitationController@store` |
| DELETE | `teams/{team}/invitations/{invitation}` | `TeamInvitationController@destroy` |
| POST | `invitations/{token}/accept` | `TeamInvitationController@accept` |

**Profile:**

| Method | URI | Action |
|:-------|:----|:-------|
| PUT | `user/profile` | `ProfileController@update` |
| PUT | `user/password` | `ProfileController@updatePassword` |
| POST/DELETE | `user/` | `ProfileController@destroy` |

**Profile Photo** (when `Features::profilePhotos()` enabled):

| Method | URI | Action |
|:-------|:----|:-------|
| POST | `user/profile-photo` | `ProfilePhotoController@update` |
| DELETE | `user/profile-photo` | `ProfilePhotoController@delete` |

**Sessions** (when `Features::sessions()` enabled):

| Method | URI | Action |
|:-------|:----|:-------|
| GET | `sessions` | `SessionController@index` |
| DELETE | `sessions/other` | `SessionController@destroyOther` |
| DELETE | `sessions/{token}` | `SessionController@destroy` |

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
    "profile_photo_url": "https://...",
    "current_team": { "..." },
    "all_teams": [ { "..." } ],
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
    "profile_photo_url": "https://...",
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
    "user_agent": "Mozilla/5.0...",
    "is_current_device": true,
    "last_used_at": "2026-01-01T00:00:00.000000Z",
    "created_at": "2026-01-01T00:00:00.000000Z"
}
```

**Auth Responses** (register/login):

```json
{
    "data": {
        "user": { "...UserResource..." },
        "token": "1|abcdef123456..."
    },
    "message": "Login successful"
}
```

<a name="action-contracts-reference"></a>
## Action Contracts

All contracts live in `FlutterSdk\MagicStarter\Contracts`:

| Contract | Method Signature | Published Stub |
|:---------|:-----------------|:---------------|
| `CreatesUsers` | `create(array $input): mixed` | `CreateUser.php` |
| `UpdatesUserProfiles` | `update(mixed $user, array $input): void` | `UpdateUserProfile.php` |
| `UpdatesUserPasswords` | `update(mixed $user, array $input): void` | `UpdateUserPassword.php` |
| `DeletesUsers` | `delete(mixed $user): void` | `DeleteUser.php` |
| `CreatesTeams` | `create(mixed $user, array $input): mixed` | `CreateTeam.php` |
| `UpdatesTeams` | `update(mixed $user, mixed $team, array $input): void` | `UpdateTeam.php` |
| `DeletesTeams` | `delete(mixed $team): void` | `DeleteTeam.php` |
| `AddsTeamMembers` | `add(mixed $user, mixed $team, string $email, string $role): void` | `AddTeamMember.php` |
| `InvitesTeamMembers` | `invite(mixed $user, mixed $team, string $email, string $role): mixed` | `InviteTeamMember.php` |
| `RemovesTeamMembers` | `remove(mixed $user, mixed $team, mixed $teamMember): void` | `RemoveTeamMember.php` |

<a name="models-reference"></a>
## Models

The package ships with 4 Eloquent models, all using UUIDs (`HasUuids`, non-incrementing string keys):

**`Team`** — `FlutterSdk\MagicStarter\Models\Team`

- Fillable: `user_id`, `name`, `personal_team`, `profile_photo_path`
- Casts: `personal_team` → `boolean` (via `casts()` method)
- Relations: `owner()` → BelongsTo User, `users()` → BelongsToMany User (pivot: `TeamUser`), `invitations()` → HasMany TeamInvitation
- Appends: `profile_photo_url` (with ui-avatars fallback)

**`TeamInvitation`** — `FlutterSdk\MagicStarter\Models\TeamInvitation`

- Fillable: `email`, `role`, `token`
- Relations: `team()` → BelongsTo Team

**`TeamUser`** — `FlutterSdk\MagicStarter\Models\TeamUser`

- Extends `Pivot`, table: `team_user`

**`PersonalAccessToken`** — `FlutterSdk\MagicStarter\Models\PersonalAccessToken`

- Table: `personal_access_tokens`

<a name="user-traits"></a>
## User Traits

**`HasTeams`** — adds to your User model:

| Method | Returns | Description |
|:-------|:--------|:------------|
| `ownedTeams()` | HasMany | Teams owned by this user |
| `teams()` | BelongsToMany | Teams joined as member (via `team_user` pivot) |
| `personalTeam()` | ?Team | First owned team where `personal_team = true` |
| `currentTeam()` | BelongsTo | Team referenced by `current_team_id` |
| `allTeams()` | Collection | Merged owned + member teams, sorted by name |
| `getCurrentTeamOrPersonal()` | ?Team | Current team, falling back to personal team |

**`HasProfilePhoto`** — adds to your User model:

| Method | Returns | Description |
|:-------|:--------|:------------|
| `getProfilePhotoUrlAttribute()` | string | Storage URL or ui-avatars.com fallback |
| `defaultProfilePhotoUrl()` | string | Generated avatar URL from name initials |

<a name="form-requests"></a>
## Form Requests

14 form requests with built-in validation:

| Request | Key Rules |
|:--------|:----------|
| `RegisterRequest` | name (required), email (unique:users), password (min:8, confirmed), locale, timezone |
| `LoginRequest` | email (required, email), password (required) |
| `SocialLoginRequest` | access_token or authorization_code |
| `ForgotPasswordRequest` | email (required, email) |
| `ResetPasswordRequest` | token, email, password (confirmed) |
| `SwitchTeamRequest` | team_id (required) |
| `StoreTeamRequest` | name (required, max:255) |
| `UpdateTeamRequest` | name (required, max:255) |
| `StoreTeamInvitationRequest` | email (required), role (in: admin, editor, member) |
| `UpdateTeamMemberRequest` | role (required, in: admin, editor, member) |
| `UpdateProfileRequest` | name (required, min:2), phone, timezone (valid tz), language |
| `UpdatePasswordRequest` | current_password, password (confirmed) |
| `UpdateProfilePhotoRequest` | photo (required, image) |
| `DeleteAccountRequest` | password (required) |

<a name="publishable-migrations"></a>
## Publishable Migrations

11 migration stubs are published (never auto-loaded):

| Migration | Description |
|:----------|:------------|
| `create_users_table` | Base users table |
| `create_personal_access_tokens_table` | Sanctum tokens |
| `create_teams_table` | Teams with `user_id`, `personal_team` |
| `create_team_user_table` | Pivot with `role` column |
| `create_team_invitations_table` | Invitations with `token`, `email`, `role` |
| `add_current_team_id_to_users_table` | `current_team_id` FK on users |
| `add_profile_photo_path_to_users_table` | `profile_photo_path` on users |
| `add_profile_photo_path_to_teams_table` | `profile_photo_path` on teams |
| `add_localization_fields_to_users_table` | `locale`, `timezone`, `language` on users |
| `add_device_info_to_personal_access_tokens_table` | `ip_address`, `user_agent` on tokens |
| `add_profile_fields_to_users_table` | `phone` and additional profile fields |

> [!WARNING]
> If your application already has these tables (e.g., migrating from a monolith), do not run the `create_*` migrations again. Only publish and run the `add_*` migrations you need.

<a name="testing"></a>
## Testing
The package uses PHPUnit with Orchestra Testbench.
```shell
composer install
composer test        # Run PHPUnit
composer lint        # Check code style (Pint)
composer lint:fix    # Auto-fix code style
composer analyse     # Run PHPStan (Level 6)
```

Test coverage includes:
- Feature toggles (`FeaturesTest`)
- Model resolution and route control (`MagicStarterTest`)
- Service provider boot and config merge (`ServiceProviderTest`)
- Conditional route registration (`RouteRegistrationTest`)
- Install command (`InstallCommandTest`)
- All 8 controllers with HTTP tests (including 403, 404, 422 negative cases)
- Model relationships and casts (`ModelsTest`)
- User traits (`HasTeamsTest`, `HasProfilePhotoTest`)
- Auth and profile form request validation (`AuthRequestsTest`, `ProfileRequestsTest`)
- Action stub contracts (`ActionStubsTest`)
