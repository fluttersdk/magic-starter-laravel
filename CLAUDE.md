# CLAUDE.md

This file provides guidance to Claude Code when working with code in this repository.

## Commands

| Command | Description |
|---------|-------------|
| `composer test` | PHPUnit test suite (Orchestra Testbench, PHP 8.2-8.4 × Laravel 11-12) |
| `composer lint` | Pint check (dry-run, no changes) |
| `composer lint:fix` | Pint auto-fix |
| `composer analyse` | PHPStan level 6 + Larastan (`--memory-limit=1G`) |

## Architecture

```
src/
├── MagicStarter.php              # Static model resolution registry (never hardcode model classes)
├── MagicStarterServiceProvider.php # Contract binding, route registration, rate limiters
├── Features.php                  # 12 feature flags — gates routes, logic, resources
├── Actions/                      # Business logic (implements Contracts/)
├── Contracts/                    # Action interfaces — consumers override via singleton binding
├── Http/Controllers/             # Thin controllers — inject contracts, no business logic
├── Http/Requests/                # Feature-aware dynamic validation rules
├── Http/Resources/               # Conditional fields via Features checks
├── Models/                       # Eloquent models with ConditionallyUsesUuids trait
├── Traits/                       # User model mixins (HasTeams, HasGuestSupport, TwoFactorAuthenticatable)
├── Support/                      # MigrationHelper, SessionAgent, TwoFactorAuthenticationProvider
├── routes/api.php                # Feature-gated, rate-limited API routes
config/magic-starter.php          # Feature toggles, model classes, auth identity, UUID config
```

**Modules**: Auth, Teams, 2FA, Profile, Sessions, Notifications, Locale/Timezone, Newsletter/OTP

## Key Files

- `src/MagicStarter.php` — Model resolution: `MagicStarter::userModel()`, `::teamModel()`, etc. Never hardcode model classes
- `src/Features.php` — Feature flag registry: `Features::hasTeamFeatures()`, `Features::enabled('feature-name')`
- `config/magic-starter.php` — All feature toggles, model overrides, auth identity strategy, UUID config
- `src/Support/MigrationHelper.php` — UUID-aware migration helpers: `primaryKey()`, `foreignKey()`, `morphColumns()`
- `src/Support/ConditionallyUsesUuids.php` — Runtime UUID/integer PK trait (applied on all models)
- `AGENTS.md` — Detailed code style and architecture patterns guide

## Code Style

- Contract-action pattern: controllers inject interfaces from `Contracts/`, bound in ServiceProvider
- Override actions: `$this->app->bind(CreatesUsers::class, CustomCreateUser::class)` in consumer AppServiceProvider
- Dynamic model resolution: always `MagicStarter::*Model()`, never `App\Models\*` directly
- Feature checks before conditional logic: `Features::hasTeamFeatures()`, `Features::enabled()`
- UUID/integer PK: `MigrationHelper::primaryKey($table)` in migrations, `ConditionallyUsesUuids` on models
- Phone numbers: E.164 format only, validate with `E164Phone` rule
- Resources: use `$this->when(Features::has*(), ...)` for feature-conditional fields

## Testing

- Base class: `FlutterSdk\MagicStarter\Tests\TestCase` (extends Orchestra Testbench)
- Fixtures in `tests/Fixtures/` — ConcreteUser, ConcreteTeam, ConcreteTeamUser, ConcreteTeamInvitation
- CI matrix: PHP 8.2/8.3/8.4 × Laravel 11/12

## Gotchas

- Guest users may have null email AND null password — guard with null checks
- `method_exists()` required when calling optional trait methods on user model
- Sanctum migrations disabled by package — `PersonalAccessToken` model overrides Sanctum's with device info columns
- Password reset URL customized in ServiceProvider `boot()` — don't override separately
- Model auto-resolution tries `App\Models\*` first via `class_exists()` — stale Composer classmap can cause issues
- PHPStan has targeted ignores in `phpstan.neon` for trait/dynamic model patterns — don't add suppressions, fix root cause
- Team membership is a pivot model (`TeamUser`) — use `->using(MagicStarter::membershipModel())`
