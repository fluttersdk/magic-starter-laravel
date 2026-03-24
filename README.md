<p align="center">
  <img src="https://raw.githubusercontent.com/fluttersdk/magic/master/.github/magic-logo.svg" width="120" alt="Magic Logo" />
</p>

<h1 align="center">Magic Starter Laravel</h1>

<p align="center">
  <strong>Pre-built Auth, Profile, Teams & Notifications API for Laravel.</strong><br/>
  12 opt-in features — every action overridable.
</p>

<p align="center">
  <a href="https://packagist.org/packages/fluttersdk/magic-starter-laravel"><img src="https://img.shields.io/packagist/v/fluttersdk/magic-starter-laravel.svg" alt="Packagist Version" /></a>
  <a href="https://github.com/fluttersdk/magic-starter-laravel/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/fluttersdk/magic-starter-laravel/ci.yml?branch=master&label=CI" alt="CI Status" /></a>
  <a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/badge/License-MIT-blue.svg" alt="License: MIT" /></a>
  <a href="https://github.com/fluttersdk/magic-starter-laravel/stargazers"><img src="https://img.shields.io/github/stars/fluttersdk/magic-starter-laravel?style=flat" alt="GitHub Stars" /></a>
</p>

<p align="center">
  <a href="https://magic.fluttersdk.com/starter">Website</a> ·
  <a href="https://magic.fluttersdk.com/packages/starter-laravel/getting-started/installation">Docs</a> ·
  <a href="https://packagist.org/packages/fluttersdk/magic-starter-laravel">Packagist</a> ·
  <a href="https://github.com/fluttersdk/magic-starter-laravel/issues">Issues</a> ·
  <a href="https://github.com/fluttersdk/magic-starter-laravel/discussions">Discussions</a>
</p>

---

> **Alpha** — `magic-starter-laravel` is under active development. APIs may change between minor versions until `1.0.0`.

---

## Why Magic Starter Laravel?

Stop rebuilding authentication, profile management, and team features from scratch in every Laravel project. The same controllers, the same validation, the same service bindings — over and over.

**Magic Starter Laravel** gives you a production-ready JSON API for auth, profile, teams, and notifications out of the box. Everything is config-driven with 12 opt-in feature toggles. Every action is overridable via contract bindings — swap any business logic from your host app without touching the package.

> **Config-driven API starter kit.** Enable only what you need. Override any action. Ship faster.

---

## Features

| | Feature | Description |
|---|---------|-------------|
| :key: | **Authentication** | Login, register, forgot/reset password, social login |
| :shield: | **Two-Factor Auth** | Enable/disable 2FA with QR code, OTP confirm, recovery codes |
| :bust_in_silhouette: | **Profile Management** | Photo upload, email/password change, account deletion |
| :busts_in_silhouette: | **Teams** | Create, switch, invite members, manage roles, team photos |
| :bell: | **Notifications** | Listing, unread count, mark read/unread, preference matrix |
| :iphone: | **OTP Login** | Phone-based authentication with send/verify flow |
| :ghost: | **Guest Auth** | Guest-only login without a registered account |
| :envelope: | **Email Verification** | Signed verification URL, resend notification |
| :newspaper: | **Newsletter** | Subscribe/unsubscribe toggle per user |
| :globe_with_meridians: | **Timezones** | Timezone listing API for extended profile |
| :camera: | **Profile Photos** | Upload and delete for users and teams |
| :desktop_computer: | **Sessions** | Active session listing and revocation |

---

## Quick Start

### 1. Install the package

```bash
composer require fluttersdk/magic-starter-laravel
```

### 2. Publish configuration and run migrations

```bash
php artisan vendor:publish --tag=magic-starter-config
php artisan migrate
```

### 3. Prepare your User model

Add the required traits to your `User` model:

```php
use FlutterSdk\MagicStarter\Traits\HasTeams;
use FlutterSdk\MagicStarter\Traits\HasGuestSupport;
use FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
use FlutterSdk\MagicStarter\Traits\HasNotifications;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;

class User extends Authenticatable
{
    use ConditionallyUsesUuids;
    use HasTeams;
    use HasGuestSupport;
    use HasProfilePhoto;
    use HasNotifications;
}
```

That's it — auth, profile, teams, and notifications API endpoints are ready to use.

---

## Feature Toggles

All 12 features are opt-in. Enable them by uncommenting in `config/magic-starter.php`:

| Toggle Key | Description |
|------------|-------------|
| `teams` | Team creation, switching, member invitations, role management |
| `profile-photos` | Profile photo upload and display for users and teams |
| `sessions` | Active session listing and revocation |
| `social-login` | Social authentication via Socialite providers |
| `newsletter-subscription` | Newsletter subscribe/unsubscribe toggle |
| `extended-profile` | Extended profile fields: phone, timezone, language, locale |
| `notifications` | Notification listing, unread count, read/unread, preferences |
| `two-factor-authentication` | Two-factor auth with QR code, OTP confirmation, recovery codes |
| `email-verification` | Signed email verification URL and resend notification |
| `guest-auth` | Guest-only authentication without a registered account |
| `phone-otp` | Phone-based OTP send/verify login flow |
| `timezones` | Timezone listing API endpoint |

---

## Architecture

```
Request → Route (feature-gated, rate-limited)
  → Controller (thin — injects contract)
    → Contract interface
      → Action (business logic, validator, model resolution)
        → Model (ConditionallyUsesUuids, dynamic resolution)
```

**Key patterns:**

| Pattern | Implementation |
|---------|---------------|
| Contract-Action | Controllers inject interfaces from `Contracts/`, bound in ServiceProvider |
| Feature Toggles | `Features::enabled()` gates routes, logic, and resource fields |
| Dynamic Model Resolution | `MagicStarter::userModel()`, `::teamModel()` — never hardcode classes |
| Service Provider | Contract bindings, route registration, rate limiters, password reset URL |
| Rate Limiters | Per-endpoint throttle groups: auth, register, social, 2FA, OTP, etc. |

---

## Documentation

| Document | Description |
|----------|-------------|
| [Installation](https://magic.fluttersdk.com/packages/starter-laravel/getting-started/installation) | Adding the package, publishing config, running migrations |
| [Configuration](https://magic.fluttersdk.com/packages/starter-laravel/getting-started/configuration) | Config file reference and feature toggles |
| [Authentication](https://magic.fluttersdk.com/packages/starter-laravel/basics/authentication) | Login, register, forgot/reset password, social login, OTP |
| [Teams](https://magic.fluttersdk.com/packages/starter-laravel/basics/teams) | Team CRUD, switching, invitations, member roles |
| [Profile](https://magic.fluttersdk.com/packages/starter-laravel/basics/profile) | Profile updates, photo upload, password change, account deletion |
| [Two-Factor Auth](https://magic.fluttersdk.com/packages/starter-laravel/basics/two-factor-auth) | 2FA enable/disable, QR code, confirm, recovery codes |
| [Notifications](https://magic.fluttersdk.com/packages/starter-laravel/basics/notifications) | Listing, unread count, mark read, preferences |
| [Service Provider](https://magic.fluttersdk.com/packages/starter-laravel/architecture/service-provider) | Contract bindings, route registration, rate limiters |
| [Action Contracts](https://magic.fluttersdk.com/packages/starter-laravel/architecture/action-contracts) | Overriding business logic via singleton binding |
| [Models](https://magic.fluttersdk.com/packages/starter-laravel/architecture/models) | Dynamic resolution, UUID support, traits |

---

## Contributing

Contributions are welcome! Please see the [issues page](https://github.com/fluttersdk/magic-starter-laravel/issues) for open tasks or to report bugs.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Write tests following the TDD flow — red, green, refactor
4. Ensure all checks pass: `composer test`, `composer lint`, `composer analyse`
5. Submit a pull request

---

## License

Magic Starter Laravel is open-sourced software licensed under the [MIT License](LICENSE).

---

<p align="center">
  Built with care by <a href="https://github.com/fluttersdk">FlutterSDK</a><br/>
  <sub>If Magic Starter Laravel helps your project, consider giving it a <a href="https://github.com/fluttersdk/magic-starter-laravel">star on GitHub</a>.</sub>
</p>
