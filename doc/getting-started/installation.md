# Installation

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Installing the Package](#installing-the-package)
- [Service Provider Auto-Discovery](#service-provider-auto-discovery)
- [Running the Install Command](#running-the-install-command)
- [Frontend URL for Non-Localhost Deployments](#frontend-url-non-localhost)
- [Publishable Assets](#publishable-assets)
- [User Model Setup](#user-model-setup)
- [Binding Action Contracts](#binding-action-contracts)
- [Next Steps](#next-steps)

<a name="introduction"></a>
## Introduction

Magic Starter Laravel is a modular backend package that provides authentication, team management, user profiles, session tracking, notifications, and more for Laravel applications. Inspired by Jetstream's architecture, it uses a contract-action pattern where every piece of business logic is bound through an interface, making every default replaceable without touching vendor code.

The package ships feature-gated routes, migrations, and resources — you enable only the modules your application needs via a single configuration file.

<a name="requirements"></a>
## Requirements

| Dependency | Version |
|:-----------|:--------|
| PHP | ^8.2 |
| Laravel | ^11.0 \| ^12.0 \| ^13.0 |
| Laravel Sanctum | ^4.0 |
| Laravel Socialite | ^5.0 (bundled) |
| pragmarx/google2fa | ^8.0 \| ^9.0 |
| bacon/bacon-qr-code | ^3.0 |

> [!TIP]
> For IP geolocation in session management, the optional `geoip2/geoip2` package is suggested but not required.

<a name="installing-the-package"></a>
## Installing the Package

Install the package via Composer:

```bash
composer require fluttersdk/magic-starter-laravel
```

<a name="service-provider-auto-discovery"></a>
## Service Provider Auto-Discovery

The package registers `FlutterSdk\MagicStarter\MagicStarterServiceProvider` automatically through Laravel's package auto-discovery mechanism (via `extra.laravel.providers` in the package's `composer.json`). No manual provider registration is needed.

The service provider handles:

- **Configuration merging** — merges the package config under the `magic-starter` key.
- **Action contract binding** — binds all action interfaces to default implementations (see [Binding Action Contracts](#binding-action-contracts)).
- **Sanctum setup** — uses a custom `PersonalAccessToken` model with device info columns and disables Sanctum's default migrations.
- **Password reset URL** — customizes the reset link to point at your frontend URL from config.
- **Rate limiting** — registers named rate limiters for all package endpoints (auth, 2FA, OTP, guest, settings, email verification).
- **Event listeners** — auto-creates a personal team on user registration (when teams enabled) and gates notification channels (when notifications enabled).
- **Route loading** — loads the package's API routes unless explicitly ignored via `MagicStarter::ignoreRoutes()`.

<a name="running-the-install-command"></a>
## Running the Install Command

The package ships an Artisan command that publishes configuration and feature-relevant migrations:

```bash
php artisan magic-starter:install
```

### Interactive Mode

When run without flags, the command uses [Laravel Prompts](https://laravel.com/docs/prompts) to walk you through setup:

1. **Features** — a `multiselect` prompt with all 12 features pre-selected.
2. **Route prefix** — a `text` input (defaults to `api/v1`).
3. **Frontend URL** — a `text` input (defaults to `http://localhost:3000`), used for email verification, password reset, and other email links. **Important:** Set this to your frontend base URL when its host or scheme differs from `APP_URL` (see [Frontend URL](#frontend-url-non-localhost) below).
4. **Run migrations** — a `confirm` prompt to run `php artisan migrate` immediately.

UUID vs. integer primary keys are auto-detected from your existing `users` table schema. If no `users` table exists (fresh install), UUID is used by default.

### Non-Interactive Mode

Pass flags to skip all prompts. Useful in CI/CD pipelines:

```bash
php artisan magic-starter:install \
    --all \
    --uuid \
    --route-prefix=api/v1 \
    --frontend-url=https://app.example.com
```

### Available Options

| Option | Description |
|:-------|:------------|
| `--all` | Install all features without prompting |
| `--features=*` | Features to install (comma-separated, repeatable) |
| `--uuid` | Use UUID primary keys |
| `--no-uuid` | Use auto-incrementing integer primary keys |
| `--route-prefix=` | Route prefix for package routes |
| `--frontend-url=` | Frontend application URL for email links |
| `--force` | Overwrite existing published files |

The `--features` option accepts: `teams`, `profile-photos`, `sessions`, `social-login`, `newsletter-subscription`, `extended-profile`, `notifications`, `two-factor-authentication`, `guest-auth`, `phone-otp`, `email-verification`, `timezones`.

When `--all` is passed, all 12 features are enabled regardless of `--features`.

> [!NOTE]
> When neither `--uuid` nor `--no-uuid` is provided, the installer auto-detects your existing `users` table schema. If no `users` table exists (fresh install), UUID is used by default.

<a name="frontend-url-non-localhost"></a>
## Frontend URL for Non-Localhost Deployments

The backend signs email links (verification, password reset, and other email links) using `APP_URL` as the base. When your email links should open a frontend whose host or scheme differs from `APP_URL`, configure `frontend_url` to rewrite the link base to your frontend URL. Without it, email links point at the backend host (e.g. `https://api.example.com/email/verify/{id}/{hash}`) instead of opening the intended frontend app or deep link.

**Solution:** Configure the `frontend_url` setting to point to your frontend's base URL. This rewrites the base for all package email links.

### Setting the Frontend URL

You can provide the frontend URL in three ways:

1. **During install with the flag:**
   ```bash
   php artisan magic-starter:install --all --frontend-url=https://app.example.com
   ```

2. **During interactive install:**
   When prompted for "Frontend URL", enter your frontend app URL (e.g. `https://app.example.com`).

3. **Via environment variable:**
   Set `MAGIC_STARTER_FRONTEND_URL` in your `.env`:
   ```env
   MAGIC_STARTER_FRONTEND_URL=https://app.example.com
   ```

### Localhost Development

For localhost development (backend at `http://localhost:8000`, frontend at `http://localhost:3000`), the hosts differ so you should keep `frontend_url` set. The installer defaults the prompt to `http://localhost:3000`, which covers this common case:

```bash
php artisan magic-starter:install --all --frontend-url=http://localhost:3000
```

> [!IMPORTANT]
> Set `MAGIC_STARTER_FRONTEND_URL` (or `--frontend-url`) whenever your frontend's host or scheme differs from `APP_URL`. `APP_URL` sets the backend base and is correct for API routes, but email links need the frontend base. A backend and frontend sharing the same origin do not need a separate `frontend_url`.

<a name="publishable-assets"></a>
## Publishable Assets

The install command automatically publishes config and migrations. Action stubs and model stubs are **not** published by default — the package ships with working implementations out of the box.

### Published by the Install Command

| Asset | Destination | Publish Tag |
|:------|:------------|:------------|
| Config | `config/magic-starter.php` | `magic-starter-config` |
| Migrations | `database/migrations/` | `magic-starter-migrations` |
| Model stubs | `app/Models/` (only when teams feature selected) | `magic-starter-models` |

### Published Manually

To customize default implementations, publish additional assets with `vendor:publish`:

| Asset | Destination | Publish Tag |
|:------|:------------|:------------|
| Action stubs | `app/Actions/MagicStarter/` | `magic-starter-stubs` |
| Model stubs | `app/Models/` | `magic-starter-models` |
| Policy stubs | `app/Policies/` | `magic-starter-policies` |
| Translations | `lang/vendor/magic-starter/` | `magic-starter-lang` |

```bash
php artisan vendor:publish --tag=magic-starter-stubs
php artisan vendor:publish --tag=magic-starter-models
php artisan vendor:publish --tag=magic-starter-policies
php artisan vendor:publish --tag=magic-starter-lang
```

<a name="user-model-setup"></a>
## User Model Setup

Add the relevant traits to your `User` model. `HasApiTokens` (from Sanctum) is required for token authentication:

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
    use HasNotifications;          // when notifications feature is enabled
    use TwoFactorAuthenticatable;  // when two-factor-authentication feature is enabled
    use HasGuestSupport;           // when guest-auth feature is enabled

    protected $appends = [
        'profile_photo_url',
    ];
}
```

### Required Traits

| Trait | Source | Purpose |
|:------|:-------|:--------|
| `HasApiTokens` | `laravel/sanctum` | Token-based API authentication |
| `HasUuids` | `illuminate/database` | UUID primary keys (only when `use_uuids` is `true`) |
| `HasTeams` | `FlutterSdk\MagicStarter\Traits` | Team membership, current team, personal team |
| `HasProfilePhoto` | `FlutterSdk\MagicStarter\Traits` | Profile photo URL with ui-avatars.com fallback |

### Optional Traits

| Trait | Source | When to Add |
|:------|:-------|:------------|
| `HasNotifications` | `FlutterSdk\MagicStarter\Traits` | `notifications` feature is enabled |
| `TwoFactorAuthenticatable` | `FlutterSdk\MagicStarter\Traits` | `two-factor-authentication` feature is enabled |
| `HasGuestSupport` | `FlutterSdk\MagicStarter\Traits` | `guest-auth` feature is enabled |

> [!IMPORTANT]
> When `use_uuids` is `true` (the default for fresh installs), you **must** add the `HasUuids` trait to your User model. Without it, user creation will fail with a `NOT NULL constraint` error on the `id` column. If you opted for auto-incrementing integers (`--no-uuid`), omit `HasUuids`.

<a name="binding-action-contracts"></a>
## Binding Action Contracts

The service provider binds all action contracts to default implementations — **no manual binding is required** for the package to work out of the box.

To customize behavior, publish the action stubs and override the bindings in your `AppServiceProvider`:

```bash
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

<a name="next-steps"></a>
## Next Steps

Now that Magic Starter is installed:

- **[Configuration](https://magic.fluttersdk.com/packages/starter-laravel/getting-started/configuration)** — Customize feature flags, authentication identity strategy, model overrides, and UUID settings.
- **[Features](https://magic.fluttersdk.com/packages/starter-laravel/getting-started/features)** — Learn about the 12 available feature flags and what each one enables.
- **[Authentication](https://magic.fluttersdk.com/packages/starter-laravel/basics/authentication)** — Set up login, registration, password reset, and social OAuth flows.
- **[Teams](https://magic.fluttersdk.com/packages/starter-laravel/basics/teams)** — Configure team management, invitations, and member roles.
