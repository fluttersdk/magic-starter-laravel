# Magic Starter Laravel — LLM Agent Guide

> This guide is designed for AI coding agents. It provides deterministic, step-by-step instructions
> to install and configure `magic-starter-laravel` in a Laravel project. Every command and file
> change is explicit. Follow them in order without skipping steps.

---

## Prerequisites

| Requirement | Version |
|:------------|:--------|
| PHP | ^8.2 |
| Laravel | ^11.0 or ^12.0 |
| Laravel Sanctum | ^4.0 (bundled, but must be in your app) |
| Laravel Socialite | ^5.0 (bundled — do NOT install separately) |

Required PHP extensions: `json`, `openssl`, `mbstring`

The package is installed via path repository (local dev) or Packagist. Both methods are covered below.

---

## Step 1: Install the Package

### Method A — Path Repository (local development, recommended for this project)

The plugin lives at `plugins/magic-starter-laravel` relative to your Laravel project root. Add a path
repository entry to your application's `composer.json`, then require the package.

**Edit `composer.json`:**

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "plugins/magic-starter-laravel"
        }
    ],
    "require": {
        "fluttersdk/magic-starter-laravel-back-end": "@dev"
    }
}
```

**Then run:**

```shell
composer require fluttersdk/magic-starter-laravel-back-end:@dev
```

### Method B — Packagist (production)

```shell
composer require fluttersdk/magic-starter-laravel-back-end
```

The service provider is auto-discovered via `extra.laravel.providers` in the package's `composer.json`.
Do NOT add it manually to `config/app.php`.

---

## Step 2: Run the Install Command

Run the install command in non-interactive mode so it works in CI/agent contexts without prompts:

```shell
php artisan magic-starter:install --all --uuid --route-prefix=api/v1 --frontend-url=http://localhost:3000
```

**Flag reference:**

| Flag | Description |
|:-----|:------------|
| `--all` | Enable all features without prompting |
| `--uuid` | Use UUID primary keys (required for fresh installs — default behavior) |
| `--no-uuid` | Use auto-incrementing integer primary keys instead |
| `--route-prefix=api/v1` | Prefix all package routes with `api/v1` |
| `--frontend-url=http://localhost:3000` | Frontend URL embedded in password reset and invitation emails |
| `--force` | Overwrite previously published files |
| `--features=teams,sessions` | Comma-separated list of specific features (used instead of `--all`) |

**What gets published:**

| Asset | Destination | Publish Tag |
|:------|:------------|:------------|
| Config file | `config/magic-starter.php` | `magic-starter-config` |
| Migration stubs (17 files) | `database/migrations/` | `magic-starter-migrations` |
| Action stubs (12 files) | `app/Actions/MagicStarter/` | `magic-starter-stubs` |
| Model stubs (3 files) | `app/Models/` | `magic-starter-models` |

**Resulting file tree after install:**

```
app/
├── Actions/
│   └── MagicStarter/
│       ├── AddTeamMember.php
│       ├── CreateTeam.php
│       ├── CreateUser.php
│       ├── DeleteTeam.php
│       ├── DeleteUser.php
│       ├── InviteTeamMember.php
│       ├── RemoveTeamMember.php
│       ├── TeamPolicy.php
│       ├── UpdateTeam.php
│       ├── UpdateTeamMemberRole.php
│       ├── UpdateUserPassword.php
│       └── UpdateUserProfile.php
├── Models/
│   ├── Team.php
│   ├── TeamInvitation.php
│   └── TeamUser.php
├── Policies/
│   └── TeamPolicy.php   (also published here from TeamPolicy stub)
config/
└── magic-starter.php
database/
└── migrations/
    ├── *_create_users_table.php
    ├── *_create_personal_access_tokens_table.php
    ├── *_create_teams_table.php
    ├── *_create_team_user_table.php
    ├── *_create_team_invitations_table.php
    ├── *_create_notifications_table.php
    ├── *_create_notification_settings_table.php
    ├── *_create_newsletter_subscribers_table.php
    ├── *_add_current_team_id_to_users_table.php
    ├── *_add_two_factor_columns_to_users_table.php
    ├── *_add_profile_photo_path_to_users_table.php
    ├── *_add_profile_photo_path_to_teams_table.php
    ├── *_add_localization_fields_to_users_table.php
    ├── *_add_profile_fields_to_users_table.php
    ├── *_add_guest_and_phone_fields_to_users_table.php
    ├── *_add_device_info_to_personal_access_tokens_table.php
    └── *_add_expires_at_to_team_invitations_table.php
```

To publish individual asset groups later:

```shell
php artisan vendor:publish --tag=magic-starter-config
php artisan vendor:publish --tag=magic-starter-migrations
php artisan vendor:publish --tag=magic-starter-stubs
php artisan vendor:publish --tag=magic-starter-models
```

---

## Step 3: Configure the User Model

Replace the entire content of `app/Models/User.php` with the following. This is the complete file
— not a diff:

```php
<?php

namespace App\Models;

use FlutterSdk\MagicStarter\Traits\HasGuestSupport;
use FlutterSdk\MagicStarter\Traits\HasNotifications;
use FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
use FlutterSdk\MagicStarter\Traits\HasTeams;
use FlutterSdk\MagicStarter\Traits\TwoFactorAuthenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasGuestSupport;
    use HasNotifications;
    use HasProfilePhoto;
    use HasTeams;
    use HasUuids;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'phone_country',
        'locale',
        'timezone',
        'language',
        'is_guest',
        'device_id',
        'current_team_id',
        'profile_photo_path',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'is_guest' => 'boolean',
        ];
    }
}
```

> [!CRITICAL]
> When `use_uuids` is `true` (the default for fresh installs), the `HasUuids` trait is MANDATORY.
> Without it, user creation fails with a `NOT NULL constraint` error on the `id` column because
> Laravel will not auto-populate the UUID. If you chose `--no-uuid`, remove `HasUuids` from the
> trait list above.

**Trait purpose reference:**

| Trait | Required For |
|:------|:-------------|
| `HasApiTokens` | All Sanctum token operations (always required) |
| `HasUuids` | UUID primary keys — MANDATORY when `use_uuids = true` |
| `HasTeams` | Team relationships, `currentTeam()`, `allTeams()`, etc. |
| `HasProfilePhoto` | `profile_photo_url` computed attribute and photo management |
| `HasNotifications` | Notification preferences and `prefers()` method |
| `TwoFactorAuthenticatable` | TOTP secret storage and recovery code management |
| `HasGuestSupport` | `isGuest()` and `isRegistered()` helper methods |

Only `HasApiTokens` and `HasUuids` are always required. The rest are needed when the corresponding
feature is enabled. Including all of them is safe — unused traits add no overhead.

---

## Step 4: Remove Conflicting Migrations

> [!CRITICAL]
> Laravel 11 and 12 ship with a default `create_users_table` migration. The plugin publishes its
> own version with UUID support and additional columns. Running both will cause a duplicate table
> error. Delete the Laravel default BEFORE running migrations.

**Delete the conflicting default migrations:**

```shell
rm database/migrations/0001_01_01_000000_create_users_table.php
```

Also remove the default personal access tokens migration if it exists:

```shell
rm database/migrations/0001_01_01_000001_create_cache_table.php
```

> [!NOTE]
> The `create_cache_table` migration is not related to the plugin but is sometimes bundled. Check
> which files exist under `database/migrations/` before deleting. The only file you must delete is
> `0001_01_01_000000_create_users_table.php`. The plugin's published version has a timestamp prefix
> and will be named something like `2025_01_01_000000_create_users_table.php`.

**Why:** The plugin's `create_users_table` migration adds UUID support via `MigrationHelper::primaryKey()`
which switches between `uuid()` and `id()` columns based on `config('magic-starter.use_uuids')`. The
Laravel default uses only integer auto-increment `id()`. Running the default first then the plugin's
version breaks the column definition.

The plugin's `create_personal_access_tokens_table` adds `ip_address` and `user_agent` columns needed
for session management. If your app's default version already exists, remove it:

```shell
# Only run this if the file exists
rm database/migrations/0001_01_01_000002_create_personal_access_tokens_table.php
```

---

## Step 5: Bind Action Contracts

All 11 published action stubs must be bound in the IoC container before the app handles requests.
Replace the contents of `app/Providers/AppServiceProvider.php` with:

```php
<?php

namespace App\Providers;

use FlutterSdk\MagicStarter\Contracts\AddsTeamMembers;
use FlutterSdk\MagicStarter\Contracts\CreatesTeams;
use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\Contracts\InvitesTeamMembers;
use FlutterSdk\MagicStarter\Contracts\RemovesTeamMembers;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeamMemberRoles;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeams;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserPasswords;
use FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
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

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
```

**If the `guest-auth` feature is enabled**, also bind `CreatesGuestUsers`:

```php
use FlutterSdk\MagicStarter\Contracts\CreatesGuestUsers;

// inside register():
$this->app->bind(CreatesGuestUsers::class, \App\Actions\MagicStarter\CreateGuestUser::class);
```

You must create `app/Actions/MagicStarter/CreateGuestUser.php` manually (no stub is published for this).

**If the `phone-otp` feature is enabled**, also bind `SendsOtpCodes` and `VerifiesOtpCodes`:

```php
use FlutterSdk\MagicStarter\Contracts\SendsOtpCodes;
use FlutterSdk\MagicStarter\Contracts\VerifiesOtpCodes;

// inside register():
$this->app->bind(SendsOtpCodes::class, \App\Actions\MagicStarter\SendOtpCode::class);
$this->app->bind(VerifiesOtpCodes::class, \App\Actions\MagicStarter\VerifyOtpCode::class);
```

**Register the TeamPolicy** in the same provider's `boot()` method:

```php
use App\Models\Team;
use App\Policies\TeamPolicy;
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::policy(Team::class, TeamPolicy::class);
}
```

---

## Step 6: Run Migrations

```shell
php artisan migrate
```

All 17 migrations are idempotent. Each `create_*` migration is guarded with `Schema::hasTable()`.
Each `add_*` migration is guarded with `Schema::hasColumn()`. Running them against an existing
schema is safe.

After migrations, link the storage disk for profile photos:

```shell
php artisan storage:link
```

This creates `public/storage` -> `storage/app/public` symlink required for the default `public`
disk to serve uploaded photos via HTTP.

---

## Step 7: Configure Features

Open `config/magic-starter.php` and uncomment the features you want. The full set looks like this:

```php
'features' => [
    \FlutterSdk\MagicStarter\Features::twoFactorAuthentication(),
    \FlutterSdk\MagicStarter\Features::teams(),
    \FlutterSdk\MagicStarter\Features::profilePhotos(),
    \FlutterSdk\MagicStarter\Features::sessions(),
    \FlutterSdk\MagicStarter\Features::socialLogin(),
    \FlutterSdk\MagicStarter\Features::newsletterSubscription(),
    \FlutterSdk\MagicStarter\Features::extendedProfile(),
    \FlutterSdk\MagicStarter\Features::notifications(),
    \FlutterSdk\MagicStarter\Features::guestAuth(),
    \FlutterSdk\MagicStarter\Features::phoneOtp(),
],
```

**Feature descriptions:**

| Feature Method | What It Enables |
|:---------------|:----------------|
| `teams()` | Team CRUD, member management, invitation system |
| `profilePhotos()` | User and team photo upload/delete endpoints |
| `sessions()` | Session listing and revocation via token management |
| `socialLogin()` | OAuth login via Socialite (`POST auth/social/{provider}`) |
| `newsletterSubscription()` | `subscribe_newsletter` field on register, `NewsletterSubscriber` record creation |
| `extendedProfile()` | Extended profile fields (phone, language, timezone) |
| `notifications()` | Database notifications list, unread count, preferences matrix |
| `twoFactorAuthentication()` | TOTP-based 2FA enable/confirm/disable and challenge flow |
| `guestAuth()` | Anonymous guest sessions tied to `device_id` |
| `phoneOtp()` | Phone-based OTP send and verify for passwordless login |

When a feature is disabled, its routes are not registered. Calling a disabled route returns 404.

**Required `.env` variables:**

```env
MAGIC_STARTER_FRONTEND_URL=http://localhost:3000
APP_NAME="My App"
```

**All available `.env` keys:**

| Key | Default | Description |
|:----|:--------|:------------|
| `MAGIC_STARTER_FRONTEND_URL` | (empty) | Frontend base URL used in email links |
| `MAGIC_STARTER_ROUTE_PREFIX` | (empty) | Route prefix for all package routes |
| `MAGIC_STARTER_USER_MODEL` | (auth provider model) | Custom User model class |
| `MAGIC_STARTER_TEAM_MODEL` | `FlutterSdk\MagicStarter\Models\Team` | Custom Team model class |
| `MAGIC_STARTER_MEMBERSHIP_MODEL` | `FlutterSdk\MagicStarter\Models\TeamUser` | Custom TeamUser pivot model |
| `MAGIC_STARTER_TEAM_INVITATION_MODEL` | `FlutterSdk\MagicStarter\Models\TeamInvitation` | Custom TeamInvitation model |
| `MAGIC_STARTER_AUTH_EMAIL` | `true` | Allow email-based registration and login |
| `MAGIC_STARTER_AUTH_PHONE` | `false` | Allow phone-based registration and login |
| `MAGIC_STARTER_PROFILE_PHOTO_DISK` | `public` | Storage disk for user photos |
| `MAGIC_STARTER_TEAM_PHOTO_DISK` | `public` | Storage disk for team photos |
| `MAGIC_STARTER_PROFILE_PHOTO_PATH` | `profile-photos` | Directory within the disk for user photos |
| `MAGIC_STARTER_TEAM_PHOTO_PATH` | `team-photos` | Directory within the disk for team photos |
| `MAGIC_STARTER_INVITATION_EXPIRY_DAYS` | `7` | Days until a team invitation token expires |
| `MAGIC_STARTER_TOKEN_EXPIRATION` | `null` | Sanctum token TTL in minutes (null = never expires) |
| `MAGIC_STARTER_DEFAULT_LOCALE` | `en` | Default locale for new users |
| `MAGIC_STARTER_DEFAULT_TIMEZONE` | `UTC` | Default timezone for new users |

---

## Step 8: Implement Action Stubs

Every published stub throws `RuntimeException` by default. You must implement the business logic
before any action is used in production.

**Published stubs that MUST be implemented:**

| File | Contract | Method Signature |
|:-----|:---------|:-----------------|
| `app/Actions/MagicStarter/CreateUser.php` | `CreatesUsers` | `create(array $input): Authenticatable` |
| `app/Actions/MagicStarter/UpdateUserProfile.php` | `UpdatesUserProfiles` | `update(Authenticatable $user, array $input): void` |
| `app/Actions/MagicStarter/UpdateUserPassword.php` | `UpdatesUserPasswords` | `update(Authenticatable $user, array $input): void` |
| `app/Actions/MagicStarter/DeleteUser.php` | `DeletesUsers` | `delete(Authenticatable $user): void` |
| `app/Actions/MagicStarter/CreateTeam.php` | `CreatesTeams` | `create(Authenticatable $user, array $input): Model` |
| `app/Actions/MagicStarter/UpdateTeam.php` | `UpdatesTeams` | `update(Authenticatable $user, Model $team, array $input): void` |
| `app/Actions/MagicStarter/DeleteTeam.php` | `DeletesTeams` | `delete(Model $team): void` |
| `app/Actions/MagicStarter/AddTeamMember.php` | `AddsTeamMembers` | `add(Authenticatable $user, Model $team, string $email, string $role): void` |
| `app/Actions/MagicStarter/InviteTeamMember.php` | `InvitesTeamMembers` | `invite(Authenticatable $user, Model $team, string $email, string $role): Model` |
| `app/Actions/MagicStarter/RemoveTeamMember.php` | `RemovesTeamMembers` | `remove(Authenticatable $user, Model $team, Model $teamMember): void` |
| `app/Actions/MagicStarter/UpdateTeamMemberRole.php` | `UpdatesTeamMemberRoles` | `update(Authenticatable $user, Model $team, Model $teamMember, string $role): void` |

**Complete `CreateUser.php` implementation example:**

```php
<?php

namespace App\Actions\MagicStarter;

use App\Models\User;
use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;

/**
 * Handle new user registration.
 */
class CreateUser implements CreatesUsers
{
    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input  The validated registration data from RegisterRequest.
     * @return Authenticatable The created user instance.
     */
    public function create(array $input): Authenticatable
    {
        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'] ?? null,
            'phone' => $input['phone'] ?? null,
            'password' => Hash::make($input['password']),
            'locale' => $input['locale'] ?? config('magic-starter.defaults.locale', 'en'),
            'timezone' => $input['timezone'] ?? config('magic-starter.defaults.timezone', 'UTC'),
            'language' => $input['language'] ?? config('magic-starter.defaults.locale', 'en'),
            'is_guest' => false,
        ]);

        event(new Registered($user));

        return $user;
    }
}
```

> [!NOTE]
> Firing `event(new Registered($user))` inside `CreateUser` is what triggers the
> `CreatePersonalTeamListener` (creates the personal team) when the `teams` feature is enabled.
> Without this event, the user will have no personal team.

**`TeamPolicy.php`** is published to `app/Policies/TeamPolicy.php` and is ready to use as-is.
Register it in `AppServiceProvider::boot()` as shown in Step 5.

---

## API Quick Reference

All routes are prefixed with `config('magic-starter.route_prefix')`. Examples below assume the
prefix is `api/v1`. Public auth routes are rate-limited at `throttle:5,1` (5 requests per minute).

### Public Auth Routes

| Method | URI | Auth | Feature | Body Fields |
|:-------|:----|:-----|:--------|:------------|
| POST | `/api/v1/auth/register` | No | Always | `name`, `email`, `password`, `password_confirmation`, `locale`?, `timezone`?, `subscribe_newsletter`? |
| POST | `/api/v1/auth/login` | No | Always | `email`, `password` |
| POST | `/api/v1/auth/social/{provider}` | No | `social-login` | `access_token` OR `authorization_code` |
| POST | `/api/v1/auth/forgot-password` | No | Always | `email` |
| POST | `/api/v1/auth/reset-password` | No | Always | `token`, `email`, `password`, `password_confirmation` |
| POST | `/api/v1/auth/two-factor-challenge` | No | `two-factor-authentication` | `two_factor_token`, `code` OR `recovery_code` |
| POST | `/api/v1/auth/guest` | No | `guest-auth` | `device_id` |
| POST | `/api/v1/auth/otp/send` | No | `phone-otp` | `phone` |
| POST | `/api/v1/auth/otp/verify` | No | `phone-otp` | `phone`, `code` |

### Protected Auth Routes

| Method | URI | Auth | Feature | Body Fields |
|:-------|:----|:-----|:--------|:------------|
| POST | `/api/v1/auth/logout` | Yes | Always | (none) |
| GET | `/api/v1/auth/user` | Yes | Always | (none) |
| PUT | `/api/v1/user/current-team` | Yes | `teams` | `team_id` |

### Profile Routes

| Method | URI | Auth | Feature | Body Fields |
|:-------|:----|:-----|:--------|:------------|
| PUT | `/api/v1/user/profile` | Yes | Always | `name`, `phone`?, `timezone`?, `language`? |
| PUT | `/api/v1/user/password` | Yes | Always | `current_password`, `password`, `password_confirmation` |
| DELETE | `/api/v1/user/` | Yes | Always | `password` |
| POST | `/api/v1/user/profile-photo` | Yes | `profile-photos` | `photo` (multipart/form-data) |
| DELETE | `/api/v1/user/profile-photo` | Yes | `profile-photos` | (none) |

### Team Routes

| Method | URI | Auth | Feature | Body Fields |
|:-------|:----|:-----|:--------|:------------|
| GET | `/api/v1/teams` | Yes | `teams` | (none) |
| POST | `/api/v1/teams` | Yes | `teams` | `name` |
| GET | `/api/v1/teams/{team}` | Yes | `teams` | (none) |
| PUT | `/api/v1/teams/{team}` | Yes | `teams` | `name` |
| DELETE | `/api/v1/teams/{team}` | Yes | `teams` | (none) |
| POST | `/api/v1/teams/{team}/profile-photo` | Yes | `teams` + `profile-photos` | `photo` (multipart/form-data) |
| DELETE | `/api/v1/teams/{team}/profile-photo` | Yes | `teams` + `profile-photos` | (none) |

### Team Member Routes

| Method | URI | Auth | Feature | Body Fields |
|:-------|:----|:-----|:--------|:------------|
| GET | `/api/v1/teams/{team}/members` | Yes | `teams` | (none) |
| PUT | `/api/v1/teams/{team}/members/{user}` | Yes | `teams` | `role` |
| DELETE | `/api/v1/teams/{team}/members/{user}` | Yes | `teams` | (none) |
| DELETE | `/api/v1/teams/{team}/leave` | Yes | `teams` | (none) |

### Team Invitation Routes

| Method | URI | Auth | Feature | Body Fields |
|:-------|:----|:-----|:--------|:------------|
| GET | `/api/v1/teams/{team}/invitations` | Yes | `teams` | (none) |
| POST | `/api/v1/teams/{team}/invitations` | Yes | `teams` | `email`, `role` |
| DELETE | `/api/v1/teams/{team}/invitations/{invitation}` | Yes | `teams` | (none) |
| POST | `/api/v1/invitations/{token}/accept` | Yes | `teams` | (none) |

### Session Routes

| Method | URI | Auth | Feature | Body Fields |
|:-------|:----|:-----|:--------|:------------|
| GET | `/api/v1/sessions` | Yes | `sessions` | (none) |
| DELETE | `/api/v1/sessions/other` | Yes | `sessions` | `password` |
| DELETE | `/api/v1/sessions/{token}` | Yes | `sessions` | (none) |

### Two-Factor Authentication Routes

| Method | URI | Auth | Feature | Body Fields |
|:-------|:----|:-----|:--------|:------------|
| POST | `/api/v1/two-factor-authentication` | Yes | `two-factor-authentication` | (none) |
| POST | `/api/v1/two-factor-authentication/confirm` | Yes | `two-factor-authentication` | `code` |
| DELETE | `/api/v1/two-factor-authentication` | Yes | `two-factor-authentication` | `password` |
| GET | `/api/v1/two-factor-recovery-codes` | Yes | `two-factor-authentication` | (none) |
| POST | `/api/v1/two-factor-recovery-codes` | Yes | `two-factor-authentication` | (none) |

### Notification Routes

| Method | URI | Auth | Feature | Body Fields |
|:-------|:----|:-----|:--------|:------------|
| GET | `/api/v1/notifications` | Yes | `notifications` | (none) |
| GET | `/api/v1/notifications/unread-count` | Yes | `notifications` | (none) |
| POST | `/api/v1/notifications/{id}/read` | Yes | `notifications` | (none) |
| POST | `/api/v1/notifications/read-all` | Yes | `notifications` | (none) |
| DELETE | `/api/v1/notifications/{id}` | Yes | `notifications` | (none) |
| GET | `/api/v1/notification-preferences` | Yes | `notifications` | (none) |
| PUT | `/api/v1/notification-preferences` | Yes | `notifications` | `type`, `channel`, `is_enabled` OR `preferences[]` |

### Response Shapes

**Successful auth response (register / login):**

```json
{
    "data": {
        "user": {
            "id": "9d4b1234-abcd-4ef0-8765-000000000001",
            "name": "John Doe",
            "email": "john@example.com",
            "phone": null,
            "email_verified_at": null,
            "locale": "en",
            "timezone": "UTC",
            "language": "en",
            "profile_photo_url": "https://ui-avatars.com/api/?name=John+Doe",
            "current_team": null,
            "all_teams": [],
            "created_at": "2026-01-01T00:00:00.000000Z",
            "updated_at": "2026-01-01T00:00:00.000000Z"
        },
        "token": "1|abcdef1234567890abcdef1234567890"
    },
    "message": "Registration successful"
}
```

**Guest auth response (201 on first call, 200 on subsequent calls with same device_id):**

```json
{
    "data": {
        "user": {
            "id": "9d4b1234-abcd-4ef0-8765-000000000002",
            "name": null,
            "email": null,
            "is_guest": true,
            "device_id": "device-uuid-1234",
            "profile_photo_url": "https://ui-avatars.com/api/?name=",
            "current_team": null,
            "all_teams": []
        },
        "token": "2|xyz789xyz789xyz789xyz789xyz789"
    },
    "message": "Guest session started"
}
```

**2FA challenge response (when user has 2FA enabled and logs in):**

```json
{
    "two_factor": true,
    "two_factor_token": "eyJpdiI6InNvbWVJVg..."
}
```

**2FA enable response:**

```json
{
    "data": {
        "secret": "JBSWY3DPEHPK3PXP",
        "qr_url": "otpauth://totp/MyApp:john%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=MyApp",
        "qr_svg": "<svg>...</svg>",
        "recovery_codes": [
            "abcd-1234",
            "efgh-5678",
            "ijkl-9012",
            "mnop-3456",
            "qrst-7890",
            "uvwx-1234",
            "yzab-5678",
            "cdef-9012"
        ]
    },
    "message": "Two-factor authentication enabled. Please confirm with your authenticator app."
}
```

**Validation error response (422):**

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "email": ["The email field is required."],
        "password": ["The password must contain at least one uppercase and one lowercase letter."]
    }
}
```

**Unauthenticated response (401):**

```json
{
    "message": "Unauthenticated."
}
```

**Authorization failure response (403):**

```json
{
    "message": "This action is unauthorized."
}
```

**Team resource shape:**

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

**Session resource shape:**

```json
{
    "id": "uuid",
    "ip_address": "127.0.0.1",
    "user_agent": "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)",
    "is_current_device": true,
    "last_used_at": "2026-01-01T12:00:00.000000Z",
    "created_at": "2026-01-01T00:00:00.000000Z"
}
```

**Password requirements:** `min:8`, must contain letters, numbers, and mixed case (`mixedCase` rule).
`password_confirmation` must match `password`.

**Team roles (assignable values):** `admin`, `editor`, `member`
The `owner` role is auto-assigned and cannot be set via the API.

---

## Common Patterns

### Registration Flow

```shell
curl -X POST https://app.example.com/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "name": "Jane Doe",
    "email": "jane@example.com",
    "password": "SecretPass1",
    "password_confirmation": "SecretPass1"
  }'
```

Response includes `data.token`. Use this as the Bearer token for all subsequent protected requests:

```shell
Authorization: Bearer 1|abcdef1234567890abcdef1234567890
```

### Login Flow (No 2FA)

```shell
curl -X POST https://app.example.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email": "jane@example.com", "password": "SecretPass1"}'
```

Returns the same auth response shape as registration.

### Login Flow (With 2FA Enabled)

Step 1 — Login returns a challenge token instead of a Sanctum token:

```json
{
    "two_factor": true,
    "two_factor_token": "eyJpdiI6..."
}
```

Step 2 — Submit the challenge token with the TOTP code:

```shell
curl -X POST https://app.example.com/api/v1/auth/two-factor-challenge \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "two_factor_token": "eyJpdiI6...",
    "code": "123456"
  }'
```

Returns the standard auth response with `data.token`.

Alternatively, use a recovery code instead of `code`:

```json
{
    "two_factor_token": "eyJpdiI6...",
    "recovery_code": "abcd-1234"
}
```

### Team Creation Flow

1. Register a user. The `CreatePersonalTeamListener` fires and creates a personal team automatically
   (when `teams` feature is enabled and you fire `Registered` event in `CreateUser`).
2. GET `/api/v1/auth/user` — the `current_team` field shows the personal team.
3. Create an additional team:

```shell
curl -X POST https://app.example.com/api/v1/teams \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"name": "My Company"}'
```

4. Switch to the new team:

```shell
curl -X PUT https://app.example.com/api/v1/user/current-team \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"team_id": "{new-team-uuid}"}'
```

### Invitation Flow

Step 1 — Send invitation (must be team owner or admin):

```shell
curl -X POST https://app.example.com/api/v1/teams/{team-uuid}/invitations \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"email": "colleague@example.com", "role": "editor"}'
```

Step 2 — The invitee receives an email with a link containing the invitation token.

Step 3 — The invitee (must be authenticated) accepts:

```shell
curl -X POST https://app.example.com/api/v1/invitations/{invitation-token}/accept \
  -H "Authorization: Bearer {invitee-token}"
```

### Guest Auth Flow

Step 1 — Send device ID to get a guest token (HTTP 201 on first call, 200 on repeat):

```shell
curl -X POST https://app.example.com/api/v1/auth/guest \
  -H "Content-Type: application/json" \
  -d '{"device_id": "unique-device-identifier-1234"}'
```

Step 2 — Use the returned token to make authenticated requests as a guest.

Step 3 — When the guest registers, their token upgrades to a full user. Implement the upgrade logic
in your `CreateUser` action by checking for an existing guest with the same `device_id`.

### OTP Flow (Phone Login)

Step 1 — Send OTP to the phone number:

```shell
curl -X POST https://app.example.com/api/v1/auth/otp/send \
  -H "Content-Type: application/json" \
  -d '{"phone": "+14155550123"}'
```

The OTP code is cached for 5 minutes. Delivery is handled by your `SendsOtpCodes` implementation.

Step 2 — Verify OTP and receive Sanctum token:

```shell
curl -X POST https://app.example.com/api/v1/auth/otp/verify \
  -H "Content-Type: application/json" \
  -d '{"phone": "+14155550123", "code": "483921"}'
```

Returns the standard auth response. The user must already exist with that phone number.

### Profile Photo Upload

Use `multipart/form-data`. Maximum file size: 1 MB. Accepted types: JPEG, PNG.

```shell
curl -X POST https://app.example.com/api/v1/user/profile-photo \
  -H "Authorization: Bearer {token}" \
  -F "photo=@/path/to/photo.jpg"
```

After upload, `data.user.profile_photo_url` will point to the stored file URL.

### Session Management

List all active sessions (tokens):

```shell
curl -X GET https://app.example.com/api/v1/sessions \
  -H "Authorization: Bearer {token}"
```

Revoke all sessions except the current one:

```shell
curl -X DELETE https://app.example.com/api/v1/sessions/other \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"password": "SecretPass1"}'
```

Revoke a specific session by token ID:

```shell
curl -X DELETE https://app.example.com/api/v1/sessions/{token-id} \
  -H "Authorization: Bearer {token}"
```

---

## Troubleshooting

**"NOT NULL constraint failed: users.id" on registration**

The `HasUuids` trait is missing from the `User` model. Add it:

```php
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class User extends Authenticatable
{
    use HasUuids;
    // ...
}
```

This only applies when `use_uuids = true` (the default). If you ran `--no-uuid`, do not add this trait.

---

**"Column not found: users.phone" or "Column not found: users.is_guest"**

Migrations were not run, or the `add_guest_and_phone_fields_to_users_table` migration was skipped.
Run:

```shell
php artisan migrate
```

If migrations were published but not yet run, they appear in `database/migrations/` but the columns
do not exist yet.

---

**"Target class [FlutterSdk\MagicStarter\Contracts\CreatesUsers] does not exist" or similar**

The contract binding is missing from `AppServiceProvider`. Add the binding in `register()`:

```php
$this->app->bind(
    \FlutterSdk\MagicStarter\Contracts\CreatesUsers::class,
    \App\Actions\MagicStarter\CreateUser::class,
);
```

---

**"CreateUser action not implemented. Publish and implement this stub."**

The published stub at `app/Actions/MagicStarter/CreateUser.php` still contains the default
`RuntimeException`. You must replace the `throw` line with actual user creation logic. See the
complete implementation example in Step 8.

---

**"Route [auth/guest] not defined" or 404 on `/auth/guest`**

The `guest-auth` feature is not enabled in `config/magic-starter.php`. Add it:

```php
'features' => [
    // ... other features ...
    \FlutterSdk\MagicStarter\Features::guestAuth(),
],
```

---

**"SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'users' already exists"**

The Laravel default `create_users_table` migration was not deleted before running migrations. The
package migration is idempotent (`Schema::hasTable()` guard), but if the default migration ran first
and created the table, the package migration skips silently. The real issue is duplicate migration
files. Delete the default:

```shell
rm database/migrations/0001_01_01_000000_create_users_table.php
php artisan migrate:reset
php artisan migrate
```

---

**"Class App\Models\Team not found"**

The Team model stub was not published. Run:

```shell
php artisan vendor:publish --tag=magic-starter-models
```

This publishes `Team.php`, `TeamInvitation.php`, and `TeamUser.php` to `app/Models/`.

---

**"There are no commands defined in the 'magic-starter' namespace"**

The service provider was not auto-discovered. Verify that `composer.json` has the path repository
entry (see Step 1) and that you ran `composer require`. Check `vendor/composer/autoload_classmap.php`
for `FlutterSdk\MagicStarter\MagicStarterServiceProvider`. If missing:

```shell
composer dump-autoload
```

---

**Personal team is not created after registration**

The `Registered` event is not being fired in `CreateUser`. Add:

```php
use Illuminate\Auth\Events\Registered;

event(new Registered($user));
```

After calling `User::create(...)` in your `CreateUser` action. The `CreatePersonalTeamListener`
listens for this event and creates the personal team.

---

**Token never expires even after setting `MAGIC_STARTER_TOKEN_EXPIRATION`**

Configure Sanctum's pruning command to clean up expired tokens. Add to your scheduler in
`app/Console/Kernel.php` or `routes/console.php`:

```php
Schedule::command('sanctum:prune-expired --hours=24')->daily();
```

Setting `MAGIC_STARTER_TOKEN_EXPIRATION` controls the TTL stored on new tokens. Expired tokens are
not automatically deleted — you must run the pruning command.
