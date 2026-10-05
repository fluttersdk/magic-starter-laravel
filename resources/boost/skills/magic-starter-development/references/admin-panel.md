# Admin Panel

## Where to Find It

- Plugin: `src/Filament/MagicStarterPlugin.php`
- Gate: `src/Filament/Concerns/AuthorizesAdminPanel.php`
- Resources: `src/Filament/Resources/` (`MagicStarterResource` is the base of every one)
- Write path: `src/Filament/Support/ContractAction.php`, `src/Filament/Concerns/WritesThroughContracts.php`
- Operations tools: `src/Filament/Ops/OpsAuthorization.php`, `TelescopeRedaction.php`
- Commands: `src/Console/FilamentInstallCommand.php`, `FilamentEjectCommand.php`
- Arch test helper: `src/Testing/AssertsAdminWritesUseContracts.php`
- Event: `src/Events/AdminActionPerformed.php`
- Config: `config/magic-starter.php`, `admin` block

The panel is optional. `filament/filament` (`^4.0|^5.0`), `laravel/horizon`, `laravel/pulse` and `laravel/telescope` are `suggest` and `require-dev` entries; nothing requires them at runtime, and the two Filament commands exist only while Filament is installed.

## Install

1. `composer require filament/filament`
2. `php artisan magic-starter:filament:install`
3. Edit the user model by hand (the command prints these lines and never writes the file):

```php
use Filament\Models\Contracts\FilamentUser;
use FlutterSdk\MagicStarter\Filament\Concerns\AuthorizesAdminPanel;

class User extends Authenticatable implements FilamentUser
{
    use AuthorizesAdminPanel;
}
```

4. Set the env keys. Only the first is required; a blank `MAGIC_STARTER_ADMIN_HOST` serves the panel on `/admin`.

```
MAGIC_STARTER_ADMIN_EMAILS=you@example.com,other@example.com
MAGIC_STARTER_ADMIN_HOST=admin.example.com
HORIZON_DOMAIN=admin.example.com
HORIZON_PATH=horizon
PULSE_DOMAIN=admin.example.com
PULSE_PATH=pulse
TELESCOPE_DOMAIN=admin.example.com
TELESCOPE_PATH=telescope
```

The command writes `app/Providers/Filament/AdminPanelProvider.php` (panel id `admin`, `->domain(config('magic-starter.admin.host'))`, `->path('admin')`, `->login()`, `->plugin(MagicStarterPlugin::make())`) and lists it in `bootstrap/providers.php` once. It is idempotent: an existing provider is kept unless `--force` is given. The user model needs `MustVerifyEmail` (the starter's trait plus the Laravel contract), because the gate checks `hasVerifiedEmail()`.

## Plugin Setters

Set every option BEFORE `->plugin()`: Filament calls `register()` the moment the plugin is passed in, so a setter chained afterwards has no effect.

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

| Setter | Effect |
|---|---|
| `userResource(string $class)` | Replace the Users resource |
| `teamResource(string $class)` | Replace the Teams resource (still behind the teams feature) |
| `resource(string $key, string $class)` | Register or replace a resource; keys `users`, `teams`, `subscriptions`, `newsletter_subscribers`, `audits` keep their feature gate, any other key is unconditional |
| `withoutResources(array $keys)` | Never register these keys |
| `navigationGroup(?string $group)` | Group the package's resources sit under |
| `authorizeUsing(Closure $callback)` | Replace the allowlist; receives `($user, $panel)`, admits only on `true` |
| `horizon()`, `pulse()`, `telescope()` | Put the tool behind the panel gate, link it under "Operations", mount its widget (Horizon, Pulse) |
| `sentryUrl(?string $url)` | Link an external Sentry project under "Operations"; `null` removes it |

`register()` throws `LogicException` when the user model does not implement `FilamentUser`, and `horizon()`, `pulse()` and `telescope()` throw when their package is not installed.

## The Gate

`AuthorizesAdminPanel::canAccessPanel()` fails closed:

1. It answers only for a panel carrying the plugin; any other panel gets `false`.
2. With `authorizeUsing()` set, the callback decides alone.
3. Otherwise the lowercased, trimmed email must be in `magic-starter.admin.emails` and the address must be verified. An empty list admits nobody, and an empty email is refused.

## What the Plugin Mounts

| Surface | Contents | Gate |
|---|---|---|
| Users | Edit profile fields; header actions: reset 2FA, revoke tokens, resend verification, schedule deletion (with `immediately`), cancel deletion; relation managers for tokens, social accounts, push devices, teams, audits | sessions, social-login, onesignal, teams, audit gate their own tab |
| Teams | Name only; delete through `DeletesTeams`; members (add, change role, remove, make owner); invitations (invite, cancel, resend) | `Features::hasTeamFeatures()` |
| Subscriptions | Read-only list and a "Reconcile now" action | `Features::hasBillingFeatures()` |
| Newsletter | Active toggle and CSV export | `Features::hasNewsletterSubscriptionFeatures()` |
| Audits | List and view | `Features::hasAuditFeatures()` |
| Dashboard | `/dashboard` with `StarterStatsWidget`, plus the Horizon and Pulse widgets when enabled | always |

## Adding an App Resource

Extend `MagicStarterResource`, never Filament's `Resource` directly. It resolves the model at call time, skips policies (panel access is the gate) and is never auto-discovered.

1. Implement `resolveModel()`, `form()`, `table()` and `getPages()`.
2. Route every write through a package contract:
   - Header and row actions: `ContractAction::run(?Action $action, Closure $call, string $event, ?Model $subject, array $context = [])`.
   - Edit and create pages: `use WritesThroughContracts;` and implement `updateRecordUsing()` / `createRecordUsing()` on the resource.
3. Navigate with `Action::make()->url()`. A bare `CreateAction`, `EditAction` or `DeleteAction` needs `->using(...)`.
4. Register it with `->resource('key', Class::class)` on the plugin, or leave it to the app panel's own discovery.

```php
use FlutterSdk\MagicStarter\Filament\Concerns\WritesThroughContracts;
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
}

class EditProject extends EditRecord
{
    use WritesThroughContracts;

    protected static string $resource = ProjectResource::class;
}
```

`ContractAction::run()` turns a contract's `ValidationException` (or `SocialSignInRefused`) into a danger notification and halts, rolling back; any other exception propagates. After the call returns it dispatches `AdminActionPerformed`, which the audit feature records as `admin.<event>`. When the contract returns the model (a create), pass `null` as the subject.

## Eject

```bash
php artisan magic-starter:filament:eject Users   # Users|Teams|Subscriptions|NewsletterSubscribers|Audits
```

Copies the resource to `app/Filament/Resources/<Name>` under `App\Filament\Resources\<Name>`, refuses to overwrite without `--force`, and prints the setter line to add (`->userResource()`, `->teamResource()` or `->resource('key', ...)`). The copy still extends `MagicStarterResource` and still calls the contracts.

## Arch Test

Add to the app's suite so a stock write cannot slip in:

```php
use FlutterSdk\MagicStarter\Testing\AssertsAdminWritesUseContracts;

public function test_admin_writes_use_contracts(): void
{
    AssertsAdminWritesUseContracts::assertAdminWritesUseContracts([app_path('Filament')]);
}
```

It fails on a `DeleteAction`, `DeleteBulkAction`, `ForceDeleteAction`, `EditAction` or `CreateAction` made without `->using(`, and on an `EditRecord` or `CreateRecord` child without `WritesThroughContracts`. An empty scan also fails.

## Operations Tools

`horizon()`, `pulse()` and `telescope()` replace each tool's own access rule with the panel gate, so the allowlist (or `authorizeUsing()`) decides for them too. The rule is written in `app()->booted()`, after an app's own `HorizonServiceProvider` or published `TelescopeServiceProvider` has run, so those cannot overwrite it.

- Mount each tool on the admin host with `HORIZON_DOMAIN` / `HORIZON_PATH`, `PULSE_DOMAIN` / `PULSE_PATH`, `TELESCOPE_DOMAIN` / `TELESCOPE_PATH`. Never set a path of `''`: the panel owns the host's root.
- Telescope hides `id_token`, `token`, `code`, `code_verifier`, `recovery_code`, `two_factor_token`, `confirmation_token`, passwords and the `authorization`, `cookie` and `x-xsrf-token` headers in every environment. Outside `local` it also stores only batches holding a reportable exception, a failed job, a scheduled task, a slow query or a monitored tag.
- Each tool keeps one global auth closure, so with several panels the last one to enable a tool wins.

## Rules

- No tenancy. The panel is global staff access; do not scope it with Filament tenancy.
- No bulk delete on Users or Teams. An account leaves through `SchedulesUserDeletion`; a team through `DeletesTeams`.
- No direct write where a contract exists: no `$record->update()`, no stock `DeleteAction`, no `CreateRecord` default save.
- Change a package resource by ejecting it, never by editing it under `vendor/`.

## What to Watch For

- A custom `RemovesTeamMembers` or `UpdatesTeamMemberRoles` binding owns the team-owner guards (`owner_not_removable`, `owner_cannot_leave`, `owner_role_locked`, `role_not_assignable`). The panel calls the contract, so keep the guard or call `RemoveTeamMember::ensureRemovable()` / `UpdateTeamMemberRole::ensureAssignable()` from the override.
- A panel without `MagicStarterPlugin` is denied by `AuthorizesAdminPanel`; another panel must state its own rule.
