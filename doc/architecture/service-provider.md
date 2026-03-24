# Service Provider

- [Introduction](#introduction)
- [Bootstrap Lifecycle](#bootstrap-lifecycle)
- [Contract Bindings](#contract-bindings)
- [Route Registration](#route-registration)
- [Rate Limiters](#rate-limiters)
- [Sanctum Customization](#sanctum-customization)
- [Password Reset URL](#password-reset-url)
- [Event Listeners](#event-listeners)
- [Team Policy](#team-policy)
- [Translation Loading](#translation-loading)
- [Configuration Publishing](#configuration-publishing)
- [Install Command](#install-command)

<a name="introduction"></a>
## Introduction

`FlutterSdk\MagicStarter\MagicStarterServiceProvider` is the single entry point for the package. It is registered automatically through Laravel's package auto-discovery mechanism (`extra.laravel.providers` in `composer.json`) — no manual registration is required in `config/app.php`.

The provider is responsible for merging configuration, binding every action contract to its default implementation, suppressing Sanctum's own migrations, registering rate limiters, loading routes, wiring event listeners, and publishing all publishable assets.

> [!NOTE]
> All bindings use `bind()` (transient), not `singleton()`, so a fresh action instance is resolved per request. The single exception is `TwoFactorAuthenticationProvider`, which is registered as a `singleton()` because it wraps a stateless TOTP library that is safe to share.

<a name="bootstrap-lifecycle"></a>
## Bootstrap Lifecycle

`MagicStarterServiceProvider` follows the standard two-phase Laravel provider lifecycle.

### `register()`

Runs before the application fully boots. Only IoC container work happens here:

1. Merges `config/magic-starter.php` under the `magic-starter` key via `mergeConfigFrom()`.
2. Calls `Sanctum::ignoreMigrations()` to prevent Sanctum from auto-loading its own `personal_access_tokens` migration (the package ships a custom version with additional device info columns).
3. Binds all action contracts to their default implementations (see [Contract Bindings](#contract-bindings)).

### `boot()`

Runs after all providers have registered. All side-effectful setup happens here, in this order:

1. `configureRateLimiting()` — registers named `RateLimiter` definitions.
2. Sanctum model swap — `Sanctum::usePersonalAccessTokenModel(Models\PersonalAccessToken::class)`.
3. Password reset URL customization via `ResetPassword::createUrlUsing()`.
4. Team policy and `Registered` event listener registration (when `Features::hasTeamFeatures()`).
5. Notification channel gating listener registration (when `Features::hasNotificationFeatures()`).
6. Translation loading via `loadTranslationsFrom()`.
7. Route loading via `loadRoutesFrom()` (unless `MagicStarter::shouldIgnoreRoutes()`).
8. Console-only: command registration and `publishes()` / `publishesMigrations()` calls.

<a name="contract-bindings"></a>
## Contract Bindings

All bindings are registered in `register()`. Each entry maps an interface from `Contracts/` to a default action in `Actions/`. Consuming applications override any binding with `$this->app->bind(...)` in their own `AppServiceProvider::register()`.

| Contract | Default Implementation | Domain |
|:---------|:-----------------------|:-------|
| `Contracts\CreatesUsers` | `Actions\CreateUser` | Auth |
| `Contracts\UpdatesUserProfiles` | `Actions\UpdateUserProfile` | Auth / Profile |
| `Contracts\UpdatesUserPasswords` | `Actions\UpdateUserPassword` | Auth |
| `Contracts\DeletesUsers` | `Actions\DeleteUser` | Auth |
| `Contracts\CreatesTeams` | `Actions\CreateTeam` | Teams |
| `Contracts\UpdatesTeams` | `Actions\UpdateTeam` | Teams |
| `Contracts\DeletesTeams` | `Actions\DeleteTeam` | Teams |
| `Contracts\AddsTeamMembers` | `Actions\AddTeamMember` | Teams |
| `Contracts\RemovesTeamMembers` | `Actions\RemoveTeamMember` | Teams |
| `Contracts\InvitesTeamMembers` | `Actions\InviteTeamMember` | Teams |
| `Contracts\UpdatesTeamMemberRoles` | `Actions\UpdateTeamMemberRole` | Teams |
| `Contracts\CreatesGuestUsers` | `Actions\CreateGuestUser` | Guest Auth |
| `Contracts\SendsOtpCodes` | `Actions\LogOtpProvider` | OTP |
| `Contracts\VerifiesOtpCodes` | `Actions\CacheOtpVerifier` | OTP |
| `Contracts\EnablesTwoFactorAuthentication` | `Actions\EnableTwoFactorAuthentication` | 2FA |
| `Contracts\ConfirmsTwoFactorAuthentication` | `Actions\ConfirmTwoFactorAuthentication` | 2FA |
| `Contracts\DisablesTwoFactorAuthentication` | `Actions\DisableTwoFactorAuthentication` | 2FA |
| `Contracts\GeneratesNewRecoveryCodes` | `Actions\GenerateNewRecoveryCodes` | 2FA |

In addition to the interface bindings, `Support\TwoFactorAuthenticationProvider` is registered as a **singleton**:

```php
$this->app->singleton(Support\TwoFactorAuthenticationProvider::class);
```

> [!NOTE]
> To replace a default implementation, bind the contract in your `AppServiceProvider::register()` **after** the package provider has run. Because `MagicStarterServiceProvider` uses `bind()`, your later binding wins for all subsequent resolutions in the same request.

**Example override:**

```php
// app/Providers/AppServiceProvider.php
use FlutterSdk\MagicStarter\Contracts\CreatesUsers;

public function register(): void
{
    $this->app->bind(CreatesUsers::class, \App\Actions\MagicStarter\CreateUser::class);
}
```

<a name="route-registration"></a>
## Route Registration

Routes are loaded in `boot()` from `src/routes/api.php`:

```php
if (! MagicStarter::shouldIgnoreRoutes()) {
    $this->loadRoutesFrom(__DIR__ . '/routes/api.php');
}
```

Call `MagicStarter::ignoreRoutes()` in your `AppServiceProvider::register()` to skip route loading entirely and define your own routes manually.

The route prefix is read from `config('magic-starter.route_prefix')` (default: `api/v1`) and applied globally to all package routes. Every route group is additionally gated by its corresponding feature flag — routes for disabled features are never registered.

<a name="rate-limiters"></a>
## Rate Limiters

`configureRateLimiting()` registers nine named `RateLimiter` definitions. All are keyed by a combination of IP address and a request-specific discriminator to prevent credential-stuffing while not unfairly blocking shared NATs.

| Limiter Name | Limit | Key Strategy |
|:-------------|:------|:-------------|
| `magic-starter-auth-login` | 5 / minute | `ip\|email` |
| `magic-starter-auth-register` | 3 / minute | `ip` |
| `magic-starter-auth-social` | 10 / minute | `ip\|provider` |
| `magic-starter-auth-password-reset` | 3 / minute | `ip\|email` |
| `magic-starter-2fa-challenge` | 5 / minute | `ip\|two_factor_token` |
| `magic-starter-guest-auth` | 10 / minute | `ip\|device_id` |
| `magic-starter-otp` (send) | 2 / minute | `ip\|send\|phone` |
| `magic-starter-otp` (verify) | 5 / minute | `ip\|verify\|phone` |
| `magic-starter-settings` | 30 / minute | `ip` |
| `magic-starter-email-verification` | 1 / minute | `user.id` or `ip` |

The `magic-starter-otp` limiter is a single named limiter that branches internally on whether the request path contains `otp/send`.

<a name="sanctum-customization"></a>
## Sanctum Customization

The package performs two distinct Sanctum customizations.

### Migration Suppression (`register()`)

```php
if (class_exists(Sanctum::class) && method_exists(Sanctum::class, 'ignoreMigrations')) {
    Sanctum::ignoreMigrations();
}
```

This prevents Sanctum from publishing or running its own `personal_access_tokens` migration, because the package ships its own version plus an additive migration (`add_device_info_to_personal_access_tokens_table`) that adds:

- `ip_address` — `string(45)`, nullable — stores the client's IPv4 or IPv6 address.
- `user_agent` — `text`, nullable — stores the raw `User-Agent` header.

### Model Swap (`boot()`)

```php
Sanctum::usePersonalAccessTokenModel(Models\PersonalAccessToken::class);
```

`Models\PersonalAccessToken` extends `Laravel\Sanctum\PersonalAccessToken` and applies `ConditionallyUsesUuids` so that token IDs respect the application's UUID/integer PK configuration.

> [!NOTE]
> Both Sanctum customizations are wrapped in `class_exists(Sanctum::class)` guards. If the application does not have `laravel/sanctum` installed, both calls are silently skipped.

<a name="password-reset-url"></a>
## Password Reset URL

The package customizes the password reset notification URL in `boot()` so that it points at the frontend application rather than the backend:

```php
ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
    $frontendUrl = config('magic-starter.frontend_url', config('app.frontend_url'));

    return "{$frontendUrl}/auth/reset-password?token={$token}&email="
        . $notifiable->getEmailForPasswordReset();
});
```

The URL is constructed as:

```
{frontend_url}/auth/reset-password?token={token}&email={email}
```

Config resolution falls back from `magic-starter.frontend_url` → `app.frontend_url`, so either key works.

> [!NOTE]
> This customization is unconditional — it runs regardless of which features are enabled. If your application uses a different reset URL structure, override it after the package provider boots by calling `ResetPassword::createUrlUsing()` again in your own `AppServiceProvider::boot()`.

<a name="event-listeners"></a>
## Event Listeners

The provider registers two event listeners, each guarded by a feature flag check.

### Personal Team Creation

**Condition:** `Features::hasTeamFeatures()` returns `true`.

```php
Event::listen(Registered::class, Listeners\CreatePersonalTeamListener::class);
```

`CreatePersonalTeamListener` handles `Illuminate\Auth\Events\Registered`. When a new user registers, the listener:

1. Performs an idempotency check via a direct DB query (not a cached relationship) to prevent duplicate teams from race conditions or repeated event dispatches.
2. Creates a personal team named using the user's first name and locale (via `magic-starter::teams.personal_team_name` translation key).
3. Attaches the user to the new team's membership pivot with `Role::OWNER`.
4. Clears the `ownedTeams` and `teams` cached relations on the user.
5. Sets `current_team_id` on the user to the new team's ID.

### Notification Channel Gating

**Condition:** `Features::hasNotificationFeatures()` returns `true`.

```php
Event::listen(NotificationSending::class, Listeners\GateNotificationChannels::class);
```

`GateNotificationChannels` handles `Illuminate\Notifications\Events\NotificationSending` (fired per-channel, per-notifiable before delivery). The listener:

1. Resolves the notification class FQCN from the event.
2. Returns `true` (allow) if the notification class is not registered in `NotificationPreferenceRegistry`.
3. Returns `true` if the notifiable does not implement `prefers()`.
4. Returns `true` for unknown or locked channels.
5. Calls `$notifiable->prefers($slug, $logicalChannel)` as the final gate — returning `false` cancels delivery for that channel.

<a name="team-policy"></a>
## Team Policy

When `Features::hasTeamFeatures()` is `true`, the provider auto-registers `Policies\TeamPolicy` for the resolved team model:

```php
Gate::policy(MagicStarter::teamModel(), Policies\TeamPolicy::class);
```

`MagicStarter::teamModel()` resolves dynamically — it checks `config('magic-starter.models.team')` first, then falls back to `App\Models\Team` via `class_exists()`. This ensures the policy is registered against whatever model class the consuming application is using.

> [!NOTE]
> If you publish team model stubs and rename the model class, ensure `config('magic-starter.models.team')` reflects the new FQCN, or the policy will be registered against the wrong class.

<a name="translation-loading"></a>
## Translation Loading

```php
$this->loadTranslationsFrom(__DIR__ . '/../lang', 'magic-starter');
```

Translation files are loaded under the `magic-starter` namespace. All package-owned translatable strings (e.g., team name templates, validation messages) use this namespace prefix, for example `magic-starter::teams.personal_team_name`.

To override translations, publish them with:

```bash
php artisan vendor:publish --tag=magic-starter-lang
```

Published files land in `lang/vendor/magic-starter/`.

<a name="configuration-publishing"></a>
## Configuration Publishing

All publish registrations are wrapped in `if ($this->app->runningInConsole())` to avoid overhead during normal request handling. The following tags are registered:

| Tag | Source | Destination |
|:----|:-------|:------------|
| `magic-starter-config` | `config/magic-starter.php` | `config/magic-starter.php` |
| `magic-starter-migrations` | `database/migrations/` | `database/migrations/` |
| `magic-starter-stubs` | `stubs/actions/` | `app/Actions/MagicStarter/` |
| `magic-starter-policies` | `stubs/policies/` | `app/Policies/` |
| `magic-starter-models` | `stubs/models/Team.php`, `TeamUser.php`, `TeamInvitation.php` | `app/Models/` |
| `magic-starter-lang` | `lang/` | `lang/vendor/magic-starter/` |

Migrations are registered via `publishesMigrations()` (introduced in Laravel 11) rather than `publishes()`, which enables the `--ansi` timestamping behaviour and migration status tracking.

> [!NOTE]
> The `magic-starter-policies` tag publishes a `TeamPolicy` stub to `app/Policies/`. If your application already has a `TeamPolicy`, use `--force` to overwrite it or merge manually.

<a name="install-command"></a>
## Install Command

```php
$this->commands([InstallCommand::class]);
```

`Console\InstallCommand` is registered only when running in the console. The command (`magic-starter:install`) provides an interactive setup wizard (Laravel Prompts) and a non-interactive flag-driven mode for CI pipelines. It publishes config, selectively publishes feature-relevant migrations, optionally publishes team model stubs, and can immediately run `php artisan migrate`.

See the [Installation guide](../getting-started/installation.md) for full usage documentation including all available `--flags`.
