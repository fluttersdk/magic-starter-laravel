# Action-Contracts Architecture

## Table of Contents

- [Introduction](#introduction)
- [How It Works](#how-it-works)
  - [Controllers](#controllers)
  - [Default Bindings](#default-bindings)
  - [Consumer Override](#consumer-override)
- [All Contracts](#all-contracts)
  - [User Management](#user-management)
  - [Team Management](#team-management)
  - [Two-Factor Authentication](#two-factor-authentication)
  - [OTP / Phone Verification](#otp--phone-verification)
- [Override Pattern](#override-pattern)
- [Published Stubs](#published-stubs)
- [Action Implementation Guide](#action-implementation-guide)

---

## <a name="introduction"></a>Introduction

Magic Starter uses a **contract-action pattern** inspired by Laravel Jetstream. Every unit of
business logic is expressed as a PHP interface (a _contract_) in `src/Contracts/` and shipped with
a working default implementation (an _action_) in `src/Actions/`. Controllers depend exclusively on
contracts — they never reference concrete action classes.

This separation gives consuming applications full control: swap any action by binding a custom class
in their own `AppServiceProvider` without touching package source or forking controllers.

> [!NOTE]
> The pattern follows Laravel's service-container philosophy. `$this->app->bind(Contract, Action)`
> means every `app(Contract::class)` call inside the package resolves to your custom class once you
> register the override.

---

## <a name="how-it-works"></a>How It Works

### <a name="controllers"></a>Controllers

Controllers resolve contracts at call time using `app()`:

```php
public function store(RegisterRequest $request): JsonResponse
{
    $user = app(CreatesUsers::class)->create($request->validated());
    // ...
}
```

No constructor injection, no direct references to `CreateUser::class`. The controller does not know
— and does not care — which implementation is active.

### <a name="default-bindings"></a>Default Bindings

`MagicStarterServiceProvider::register()` binds every contract to its default action:

```php
$this->app->bind(Contracts\CreatesUsers::class,           Actions\CreateUser::class);
$this->app->bind(Contracts\UpdatesUserProfiles::class,    Actions\UpdateUserProfile::class);
$this->app->bind(Contracts\UpdatesUserPasswords::class,   Actions\UpdateUserPassword::class);
$this->app->bind(Contracts\DeletesUsers::class,           Actions\DeleteUser::class);
$this->app->bind(Contracts\CreatesTeams::class,           Actions\CreateTeam::class);
$this->app->bind(Contracts\UpdatesTeams::class,           Actions\UpdateTeam::class);
$this->app->bind(Contracts\DeletesTeams::class,           Actions\DeleteTeam::class);
$this->app->bind(Contracts\AddsTeamMembers::class,        Actions\AddTeamMember::class);
$this->app->bind(Contracts\RemovesTeamMembers::class,     Actions\RemoveTeamMember::class);
$this->app->bind(Contracts\InvitesTeamMembers::class,     Actions\InviteTeamMember::class);
$this->app->bind(Contracts\UpdatesTeamMemberRoles::class, Actions\UpdateTeamMemberRole::class);
$this->app->bind(Contracts\CreatesGuestUsers::class,      Actions\CreateGuestUser::class);
$this->app->bind(Contracts\SendsOtpCodes::class,          Actions\LogOtpProvider::class);
$this->app->bind(Contracts\VerifiesOtpCodes::class,       Actions\CacheOtpVerifier::class);
$this->app->bind(Contracts\EnablesTwoFactorAuthentication::class,  Actions\EnableTwoFactorAuthentication::class);
$this->app->bind(Contracts\ConfirmsTwoFactorAuthentication::class, Actions\ConfirmTwoFactorAuthentication::class);
$this->app->bind(Contracts\DisablesTwoFactorAuthentication::class, Actions\DisableTwoFactorAuthentication::class);
$this->app->bind(Contracts\GeneratesNewRecoveryCodes::class,       Actions\GenerateNewRecoveryCodes::class);
```

All bindings use `bind()` (not `singleton()`), so a fresh instance is created per resolution.
`TwoFactorAuthenticationProvider` is the only singleton in the package.

### <a name="consumer-override"></a>Consumer Override

Override any action by calling `bind()` **after** the package provider has run. Because
`AppServiceProvider` registers last, your binding wins:

```php
// app/Providers/AppServiceProvider.php
use App\Actions\MagicStarter\CreateUser;
use FlutterSdk\MagicStarter\Contracts\CreatesUsers;

public function register(): void
{
    $this->app->bind(CreatesUsers::class, CreateUser::class);
}
```

> [!TIP]
> You only need to override the contracts where your business logic differs. Every other contract
> continues to use the package default silently.

---

## <a name="all-contracts"></a>All Contracts

### <a name="user-management"></a>User Management

#### `CreatesUsers`

Namespace: `FlutterSdk\MagicStarter\Contracts\CreatesUsers`
Default action: `Actions\CreateUser`

```php
public function create(array $input): Authenticatable;
```

Creates a newly registered user. `$input` contains the raw registration payload; the action is
responsible for validation, hashing, feature-gated fields (locale, timezone, newsletter), and
sending email verification when enabled.

---

#### `CreatesGuestUsers`

Namespace: `FlutterSdk\MagicStarter\Contracts\CreatesGuestUsers`
Default action: `Actions\CreateGuestUser`

```php
public function create(array $input): Authenticatable;
```

Creates **or retrieves** a guest user keyed by `device_id`. Guest users have `null` email and
`null` password. Use `firstOrCreate` semantics — calling this twice with the same device ID must
return the same user.

> [!NOTE]
> Always null-check email and password fields on users that may be guests. The `HasGuestSupport`
> trait provides helper methods for this.

---

#### `UpdatesUserProfiles`

Namespace: `FlutterSdk\MagicStarter\Contracts\UpdatesUserProfiles`
Default action: `Actions\UpdateUserProfile`

```php
public function update(Authenticatable $user, array $input): void;
```

Validates and persists profile changes (name, email, avatar, locale, timezone) for the given user.

---

#### `UpdatesUserPasswords`

Namespace: `FlutterSdk\MagicStarter\Contracts\UpdatesUserPasswords`
Default action: `Actions\UpdateUserPassword`

```php
public function update(Authenticatable $user, array $input): void;
```

Validates `current_password` / `password` / `password_confirmation` and persists the new hashed
password.

---

#### `DeletesUsers`

Namespace: `FlutterSdk\MagicStarter\Contracts\DeletesUsers`
Default action: `Actions\DeleteUser`

```php
public function delete(Authenticatable $user): void;
```

Permanently deletes the user and all associated data. Foreign key `cascadeOnDelete()` constraints
handle relational cleanup; any additional teardown (revoke tokens, delete files) belongs here.

---

### <a name="team-management"></a>Team Management

All team contracts are only exercised when `Features::hasTeamFeatures()` returns `true`. Routes and
controllers for teams are conditionally registered.

#### `CreatesTeams`

Namespace: `FlutterSdk\MagicStarter\Contracts\CreatesTeams`
Default action: `Actions\CreateTeam`

```php
public function create(Authenticatable $user, array $input): Model;
```

Validates and creates a new team owned by `$user`. Returns the persisted team model.

---

#### `UpdatesTeams`

Namespace: `FlutterSdk\MagicStarter\Contracts\UpdatesTeams`
Default action: `Actions\UpdateTeam`

```php
public function update(Authenticatable $user, Model $team, array $input): void;
```

Validates and applies name/settings changes to `$team`. `$user` is the authenticated actor for
authorization checks.

---

#### `DeletesTeams`

Namespace: `FlutterSdk\MagicStarter\Contracts\DeletesTeams`
Default action: `Actions\DeleteTeam`

```php
public function delete(Model $team): void;
```

Permanently deletes the team. Cascade constraints handle membership and invitation records.

---

#### `AddsTeamMembers`

Namespace: `FlutterSdk\MagicStarter\Contracts\AddsTeamMembers`
Default action: `Actions\AddTeamMember`

```php
public function add(Authenticatable $user, Model $team, string $email, string $role): void;
```

Attaches an existing application user (looked up by `$email`) directly to the team pivot with the
given `$role`. Does not send an invitation — use `InvitesTeamMembers` for email-based flows.

---

#### `RemovesTeamMembers`

Namespace: `FlutterSdk\MagicStarter\Contracts\RemovesTeamMembers`
Default action: `Actions\RemoveTeamMember`

```php
public function remove(Authenticatable $user, Model $team, Model $teamMember): void;
```

Detaches `$teamMember` from `$team`. `$user` is the actor for authorization. The implementation
must prevent team owners from removing themselves.

---

#### `InvitesTeamMembers`

Namespace: `FlutterSdk\MagicStarter\Contracts\InvitesTeamMembers`
Default action: `Actions\InviteTeamMember`

```php
public function invite(Authenticatable $user, Model $team, string $email, string $role): Model;
```

Creates a `TeamInvitation` record with a random token and sends `TeamInvitationNotification` to
`$email`. Returns the invitation model. Acceptance is handled by a separate route that calls
`AddsTeamMembers`.

---

#### `UpdatesTeamMemberRoles`

Namespace: `FlutterSdk\MagicStarter\Contracts\UpdatesTeamMemberRoles`
Default action: `Actions\UpdateTeamMemberRole`

```php
public function update(Authenticatable $user, Model $team, Model $teamMember, string $role): void;
```

Changes the pivot `role` for `$teamMember` on `$team`. `$user` is the actor for authorization.

---

### <a name="two-factor-authentication"></a>Two-Factor Authentication

2FA contracts work against the `TwoFactorAuthenticatable` trait on the user model. The
`TwoFactorAuthenticationProvider` singleton (`Support\TwoFactorAuthenticationProvider`) handles
TOTP secret/QR generation and code verification underneath.

#### `EnablesTwoFactorAuthentication`

Namespace: `FlutterSdk\MagicStarter\Contracts\EnablesTwoFactorAuthentication`
Default action: `Actions\EnableTwoFactorAuthentication`

```php
public function enable(mixed $user): array;
```

Generates a TOTP secret and recovery codes, stores them on the user (encrypted), and returns
`['secret' => ..., 'qr_code' => ..., 'recovery_codes' => [...]]`. 2FA is not active until
`ConfirmsTwoFactorAuthentication` succeeds.

---

#### `ConfirmsTwoFactorAuthentication`

Namespace: `FlutterSdk\MagicStarter\Contracts\ConfirmsTwoFactorAuthentication`
Default action: `Actions\ConfirmTwoFactorAuthentication`

```php
public function confirm(mixed $user, string $code): void;
```

Verifies the TOTP `$code` against the user's unconfirmed secret. On success, marks 2FA as
confirmed. Throws a validation exception if the code is invalid.

---

#### `DisablesTwoFactorAuthentication`

Namespace: `FlutterSdk\MagicStarter\Contracts\DisablesTwoFactorAuthentication`
Default action: `Actions\DisableTwoFactorAuthentication`

```php
public function disable(mixed $user): void;
```

Clears the TOTP secret, recovery codes, and confirmed-at timestamp from the user model.

---

#### `GeneratesNewRecoveryCodes`

Namespace: `FlutterSdk\MagicStarter\Contracts\GeneratesNewRecoveryCodes`
Default action: `Actions\GenerateNewRecoveryCodes`

```php
public function generate(mixed $user): array;
```

Generates a fresh set of recovery codes, stores them (encrypted) on the user, and returns the
plain-text codes to be displayed once to the user.

---

### <a name="otp--phone-verification"></a>OTP / Phone Verification

These contracts are **intentionally thin stubs** in the default package — the default
implementations log to disk or use a cache. Production deployments must override both contracts with
real SMS/OTP-service integrations.

#### `SendsOtpCodes`

Namespace: `FlutterSdk\MagicStarter\Contracts\SendsOtpCodes`
Default action: `Actions\LogOtpProvider` _(logs to Laravel log, development only)_

```php
public function send(string $phone, string $code): void;
```

Delivers `$code` to `$phone` (E.164 format). Override with Twilio, Vonage, AWS SNS, or any other
SMS gateway.

---

#### `VerifiesOtpCodes`

Namespace: `FlutterSdk\MagicStarter\Contracts\VerifiesOtpCodes`
Default action: `Actions\CacheOtpVerifier` _(cache-backed, suitable for single-server setups)_

```php
public function verify(string $phone, string $code): bool;
```

Returns `true` when `$code` is the valid, unexpired code for `$phone`. The default implementation
stores codes in the Laravel cache. Override for distributed environments or third-party OTP
verification services.

> [!NOTE]
> Phone numbers must always be passed in **E.164 format** (e.g. `+14155552671`). Use the
> `E164Phone` validation rule in form requests to enforce this before the contract is called.

---

## <a name="override-pattern"></a>Override Pattern

To replace any action, create your class, implement the corresponding contract, then bind it in
`AppServiceProvider::register()`:

```php
<?php

namespace App\Providers;

use App\Actions\MagicStarter\CreateUser;
use App\Actions\MagicStarter\SendsOtpViaTwilio;
use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use FlutterSdk\MagicStarter\Contracts\SendsOtpCodes;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CreatesUsers::class, CreateUser::class);
        $this->app->bind(SendsOtpCodes::class, SendsOtpViaTwilio::class);
    }
}
```

Your custom `CreateUser` must implement `FlutterSdk\MagicStarter\Contracts\CreatesUsers` — that
is the only contract. Method signatures must match exactly.

> [!TIP]
> If your custom action only needs to extend the default behavior (e.g., fire an extra event after
> user creation), inject `Actions\CreateUser` via constructor and delegate to it rather than
> re-implementing validation from scratch.

---

## <a name="published-stubs"></a>Published Stubs

The package ships pre-generated stub files for the most commonly customized actions. Publish them
with:

```bash
php artisan vendor:publish --tag=magic-starter-stubs
```

This copies the following files into `app/Actions/MagicStarter/`:

| Stub file | Contract |
|---|---|
| `CreateUser.php` | `CreatesUsers` |
| `CreateTeam.php` | `CreatesTeams` |
| `UpdateUserProfile.php` | `UpdatesUserProfiles` |
| `UpdateUserPassword.php` | `UpdatesUserPasswords` |
| `DeleteUser.php` | `DeletesUsers` |
| `AddTeamMember.php` | `AddsTeamMembers` |
| `RemoveTeamMember.php` | `RemovesTeamMembers` |
| `InviteTeamMember.php` | `InvitesTeamMembers` |
| `UpdateTeam.php` | `UpdatesTeams` |
| `UpdateTeamMemberRole.php` | `UpdatesTeamMemberRoles` |
| `DeleteTeam.php` | `DeletesTeams` |

After publishing, register each stub in `AppServiceProvider::register()` as shown in the
[Override Pattern](#override-pattern) section above. Stubs are skipped for `SendsOtpCodes` and
`VerifiesOtpCodes` because those require an external service and cannot be stubbed generically.

---

## <a name="action-implementation-guide"></a>Action Implementation Guide

Follow these conventions when writing custom action classes to stay consistent with the package
patterns.

### 1. Implement exactly one contract

```php
class CreateUser implements CreatesUsers
{
    public function create(array $input): Authenticatable { ... }
}
```

One class, one contract. Do not implement multiple contracts in a single class.

### 2. Validate inline with `Validator::make`

Validation belongs inside the action, not in the caller:

```php
$validated = Validator::make($input, [
    'name'     => ['required', 'string', 'max:255'],
    'email'    => ['required', 'string', 'email', 'max:255'],
    'password' => ['required', 'string', 'min:8'],
])->validate();
```

`validate()` throws `ValidationException` automatically, which Laravel converts to a 422 response.

### 3. Resolve models via `MagicStarter::*Model()`

Never hardcode `App\Models\User` or `App\Models\Team`:

```php
$userModel = MagicStarter::userModel();   // resolves configured user model
$teamModel = MagicStarter::teamModel();   // resolves configured team model
$user = $userModel::query()->create($attributes);
```

This ensures the action works regardless of which model class the consumer has configured.

### 4. Gate feature-specific logic with `Features`

```php
if (Features::hasTeamFeatures()) {
    // team-related side effects
}

if (Features::hasNewsletterSubscriptionFeatures()) {
    // newsletter side effects
}
```

Never assume a feature is active. Guard every feature-specific branch.

### 5. Use numbered inline comments

Document flow steps with sequential inline comments so the action reads like a recipe:

```php
public function create(array $input): Authenticatable
{
    // 1. Validate input.
    $validated = Validator::make($input, $rules)->validate();

    // 2. Build attributes.
    $attributes = [...];

    // 3. Persist the user.
    $user = MagicStarter::userModel()::query()->create($attributes);

    // 4. Handle side effects.
    if (Features::hasNewsletterSubscriptionFeatures() && ...) { ... }

    return $user;
}
```

### 6. Use `Arr::get` for optional fields

```php
use Illuminate\Support\Arr;

$locale = Arr::get($validated, 'locale', 'en');
```

`Arr::get` is null-safe and accepts a default, avoiding `isset` chains.

### 7. Return types must match the contract exactly

If the contract declares `array`, return `array`. If it declares `Model`, return a `Model` (or
subclass). Do not widen or narrow the return type.
