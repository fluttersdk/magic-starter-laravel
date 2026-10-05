# Admin Panel

## Table of Contents

- [Introduction](#introduction)
- [Installation](#installation)
- [Who Can Enter](#who-can-enter)
- [Configuring the Plugin](#configuring-the-plugin)
- [What the Panel Contains](#what-the-panel-contains)
- [Writes Go Through Contracts](#writes-go-through-contracts)
- [Adding Your Own Resource](#adding-your-own-resource)
- [Customizing a Package Resource](#customizing-a-package-resource)
- [Guarding Your Resources in Tests](#guarding-your-resources-in-tests)
- [Operations Tools](#operations-tools)
- [Limits](#limits)

---

<a name="introduction"></a>
## Introduction

The package ships an optional [Filament](https://filamentphp.com) plugin that gives staff a panel over users, teams, subscriptions, newsletter subscribers and the audit trail. It is a back office for your own team, not part of the mobile API.

Nothing requires Filament at runtime. `filament/filament` (`^4.13.3|^5.8.3`), `laravel/horizon`, `laravel/pulse` and `laravel/telescope` are suggested packages; install only the ones you use. The package declares a composer conflict with the Filament 4 and 5 releases below those floors, which carry multi-factor bypass and XSS advisories; an app whose own panel runs Filament 3 and only uses the API is not blocked.

---

<a name="installation"></a>
## Installation

```bash
composer require filament/filament
php artisan magic-starter:filament:install
```

The command writes `app/Providers/Filament/AdminPanelProvider.php` and lists it in `bootstrap/providers.php`. The panel id is `admin`, it serves from `config('magic-starter.admin.host')` or the `/admin` path, uses Filament's `->login()` and mounts `MagicStarterPlugin::make()`. Running it again changes nothing unless you pass `--force`, which overwrites the provider.

The command never touches your user model, because it is your own file. Add the interface and the trait yourself:

```php
use Filament\Models\Contracts\FilamentUser;
use FlutterSdk\MagicStarter\Filament\Concerns\AuthorizesAdminPanel;

class User extends Authenticatable implements FilamentUser
{
    use AuthorizesAdminPanel;
}
```

The model also needs `MustVerifyEmail` (the package's trait and Laravel's contract), because the gate requires a verified address. Without `FilamentUser`, the plugin refuses to register and throws a `LogicException`, rather than leave Filament's own default in charge.

Then set the environment:

```
MAGIC_STARTER_ADMIN_EMAILS=you@example.com,colleague@example.com
MAGIC_STARTER_ADMIN_HOST=admin.example.com
```

`MAGIC_STARTER_ADMIN_HOST` is optional; leave it empty to serve the panel on `/admin`. The command also prints the `HORIZON_*`, `PULSE_*` and `TELESCOPE_*` keys described under [Operations Tools](#operations-tools).

---

<a name="who-can-enter"></a>
## Who Can Enter

`AuthorizesAdminPanel` answers `true` only when all of these hold:

1. The panel carries the Magic Starter plugin. Any other panel is denied, so it has to state its own rule.
2. The user's email, lowercased and trimmed, is in `magic-starter.admin.emails` (the comma-separated `MAGIC_STARTER_ADMIN_EMAILS`).
3. The email is verified.

An empty list admits nobody, and a user without an email never passes. To use your own rule instead, replace the allowlist:

```php
MagicStarterPlugin::make()->authorizeUsing(
    fn (User $user, Panel $panel): bool => $user->is_staff === true,
)
```

The callback decides alone and admits only on `true`.

### Two-factor authentication at sign in

A user who turned on two-factor authentication through the API is asked for a code after the password, or for one of their recovery codes. The panel reads the same secret and recovery codes as the API and applies the same rule: the `twoFactorAuthentication` feature is on and the user confirmed a code. A user without two-factor signs in with the password alone.

A code is accepted once: after it signs in, through the API or the panel, that code and every earlier one are refused. A recovery code is spent under a row lock, so two concurrent sign-ins cannot both use it.

The panel never writes a secret. Two-factor is turned on in the app. It is turned off there, or by an admin with "Reset two-factor" on the Users resource, which also removes that user's panel challenge.

The plugin mounts this challenge (`FlutterSdk\MagicStarter\Filament\Auth\TwoFactorAuthentication`) only when the panel has no multi-factor setup of its own while it is being built. A Closure provider list counts as a setup only if it returns providers at that point, before any request is authenticated. To combine the challenge with Filament's providers, or to require a second factor, configure the panel yourself before `->plugin()`:

```php
->multiFactorAuthentication([TwoFactorAuthentication::make()], isRequired: true)
->plugin(MagicStarterPlugin::make())
```

With `isRequired: true`, a user who has not turned on two-factor in the app is stopped on Filament's set-up page after signing in and cannot continue until they do: the panel cannot set it up for them.

---

<a name="configuring-the-plugin"></a>
## Configuring the Plugin

Set every option before `->plugin()`. Filament registers the plugin the moment it is passed in, so an option chained afterwards is ignored.

```php
->plugin(
    MagicStarterPlugin::make()
        ->withoutResources(['newsletter_subscribers'])
        ->navigationGroup('Back office')
        ->horizon()
        ->pulse()
        ->telescope()
        ->sentryUrl('https://sentry.io/organizations/acme/projects/api/'),
)
```

| Method | Purpose |
|--------|---------|
| `userResource($class)`, `teamResource($class)` | Replace the Users or Teams resource |
| `resource($key, $class)` | Register or replace a resource. The keys `users`, `teams`, `subscriptions`, `newsletter_subscribers` and `audits` keep their feature gate; any other key is always registered |
| `withoutResources($keys)` | Leave the listed keys out |
| `navigationGroup($group)` | Name the group the package's resources sit under |
| `authorizeUsing($callback)` | Replace the email allowlist |
| `horizon()`, `pulse()`, `telescope()` | Put the tool behind the panel gate and link it |
| `sentryUrl($url)` | Link an external Sentry project; `null` removes the link |

`horizon()`, `pulse()` and `telescope()` throw a `LogicException` when their package is not installed.

---

<a name="what-the-panel-contains"></a>
## What the Panel Contains

Every resource sits behind the feature that owns its data.

| Resource | What staff can do | Needs |
|----------|-------------------|-------|
| Users | Edit profile fields; reset two-factor, revoke API tokens, resend the verification mail, schedule account deletion (optionally `immediately`) or cancel it. Tabs for tokens, social accounts, push devices, teams and audits | each tab needs its own feature: `sessions`, `social-login`, `onesignal`, `teams`, `audit` |
| Teams | Rename; delete; add, re-role and remove members; make a member the owner; invite, resend and cancel invitations | `teams` |
| Subscriptions | Read the list; run "Reconcile now" | `billing` |
| Newsletter subscribers | Toggle active; export CSV | `newsletter-subscription` |
| Audits | List and view | `audit` |

The dashboard lives at `/dashboard` and shows headline counts, plus Horizon and Pulse summaries when those tools are enabled.

---

<a name="writes-go-through-contracts"></a>
## Writes Go Through Contracts

Every write in the panel calls the same action contract the API calls (`UpdatesUserProfiles`, `DeletesTeams`, `TransfersTeamOwnership` and so on). That keeps validation, refusals and side effects identical in both places, and it means an [overridden action](../architecture/action-contracts.md) applies to the panel too.

When a contract refuses with a validation error, the panel shows it as a notification, rolls the change back and stays on the page. Any other exception is a bug and propagates. After a successful write the panel dispatches `AdminActionPerformed`; with the [audit trail](audit.md) enabled it becomes one `admin.*` row naming the staff member.

---

<a name="adding-your-own-resource"></a>
## Adding Your Own Resource

Extend `MagicStarterResource` instead of Filament's `Resource`, then send every write through a contract.

```php
use FlutterSdk\MagicStarter\Filament\Resources\MagicStarterResource;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;

class ProjectResource extends MagicStarterResource
{
    protected static function resolveModel(): string
    {
        return Project::class;
    }

    public static function updateRecordUsing(Model $record, array $data, Authenticatable $actor): Model
    {
        ContractAction::run(
            null,
            static fn () => app(UpdatesProjects::class)->update($actor, $record, $data),
            'project.updated',
            $record,
        );

        return $record->refresh();
    }

    // form(), table() and getPages() as in any Filament resource.
}
```

- Header and row actions call `ContractAction::run($action, $call, $event, $subject, $context)`. Pass `null` as the subject on a create; the model the contract returns becomes the subject.
- An edit or create page uses the `WritesThroughContracts` trait, which sends Filament's save to the resource's `updateRecordUsing()` and `createRecordUsing()`.
- Link to pages with `Action::make()->url()`. A `CreateAction`, `EditAction` or `DeleteAction` needs `->using(...)`.
- Register the resource with `->resource('projects', ProjectResource::class)` on the plugin, or let your own panel discover it.

---

<a name="customizing-a-package-resource"></a>
## Customizing a Package Resource

```bash
php artisan magic-starter:filament:eject Users
```

Accepts `Users`, `Teams`, `Subscriptions`, `NewsletterSubscribers` or `Audits`. The files are copied to `app/Filament/Resources/<Name>` under the `App\Filament\Resources\<Name>` namespace, and the command prints the line to add to the plugin, for example:

```php
->plugin(MagicStarterPlugin::make()->userResource(\App\Filament\Resources\Users\UserResource::class))
```

The copy still extends `MagicStarterResource` and still calls the contracts. An existing copy is kept unless you pass `--force`.

---

<a name="guarding-your-resources-in-tests"></a>
## Guarding Your Resources in Tests

Filament's stock actions write straight to the model and skip the contracts. Add one test to catch them:

```php
use FlutterSdk\MagicStarter\Testing\AssertsAdminWritesUseContracts;

public function test_admin_writes_use_contracts(): void
{
    AssertsAdminWritesUseContracts::assertAdminWritesUseContracts([app_path('Filament')]);
}
```

It fails on a `DeleteAction`, `DeleteBulkAction`, `ForceDeleteAction`, `EditAction` or `CreateAction` without `->using(`, and on an `EditRecord` or `CreateRecord` page that does not use `WritesThroughContracts`. It also fails when the path holds no PHP file, so a typo cannot pass.

---

<a name="operations-tools"></a>
## Operations Tools

`horizon()`, `pulse()` and `telescope()` link each tool under an "Operations" group and replace its access rule with the panel's, so the same allowlist decides. The rule is written after the application has booted, so your own `HorizonServiceProvider` or a published `TelescopeServiceProvider` cannot overwrite it.

The tools keep their own routes. Mount them on the admin host:

```
HORIZON_DOMAIN=admin.example.com
HORIZON_PATH=horizon
PULSE_DOMAIN=admin.example.com
PULSE_PATH=pulse
TELESCOPE_DOMAIN=admin.example.com
TELESCOPE_PATH=telescope
```

Never use an empty path: the panel owns the host's root.

Telescope also masks the starter's secrets (`token`, `id_token`, `code`, `code_verifier`, `recovery_code`, `two_factor_token`, `confirmation_token`, passwords, and the `authorization`, `cookie` and `x-xsrf-token` headers) in every environment, and outside `local` it keeps only batches worth reading: a reportable exception, a failed job, a scheduled task, a slow query or a monitored tag.

Each tool has one global auth closure, so with more than one panel the last to enable a tool wins.

---

<a name="limits"></a>
## Limits

- No tenancy: the panel is global staff access.
- No bulk delete on Users or Teams. An account leaves through scheduled deletion, a team through `DeletesTeams`.
- No direct model writes where a contract exists.
- A custom `RemovesTeamMembers` or `UpdatesTeamMemberRoles` binding must keep the team-owner guards (`RemoveTeamMember::ensureRemovable()`, `UpdateTeamMemberRole::ensureAssignable()`). The API controllers enforce them whatever is bound; the panel calls only the contract.
