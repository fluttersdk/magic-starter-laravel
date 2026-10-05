# Audit Trail

## Where to Find It

- Core: `src/Audit/Auditor.php` (write, record, `withoutAuditing`, `forgetUser`)
- Listener: `src/Audit/ModelAuditListener.php`
- Redaction: `src/Audit/Redactor.php`
- Admin bridge: `src/Audit/RecordAdminAction.php`, `src/Events/AdminActionPerformed.php`
- Model and read side: `src/Audit/Audit.php`, `src/Audit/HasAudits.php`
- Prune: `src/Console/PruneAuditsCommand.php`
- Migration: `database/migrations/create_magic_starter_audits_table.php`
- Config: `config/magic-starter.php`, `audit` block

## Enable It

Audit is opt-in. Add `Features::audit()` to `magic-starter.features` (or `php artisan magic-starter:install --features=audit`, which publishes the migration), then migrate. While the feature is off the listener is never registered, `Auditor::record()` writes nothing and `magic-starter:audit:prune` does not exist, because the table exists only once the migration ran.

```php
// config/magic-starter.php
'features' => [
    Features::audit(),
],
```

## What Is Recorded

A wildcard listener on `eloquent.created`, `eloquent.updated` and `eloquent.deleted` records every model in the application, not only the starter's. Each row in `magic_starter_audits` holds the event (`created`, `updated`, `deleted`, or an explicit name), the subject (`auditable_type`, `auditable_id`), the actor, `related_user_id`, `old_values`, `new_values` and a `context` (`ip`, `user_agent`, `url` without its query string, `route`).

- Key columns are strings, so integer and UUID keys share one column whatever `use_uuids` says.
- An update stores only the changed keys, with the RAW values they held before (`getRawOriginal()`), so an encrypted cast never leaks plaintext into `old_values`.
- A row is inserted through `DB::afterCommit()`: a rolled-back change leaves nothing, and outside a transaction the row lands at once.
- `related_user_id` is the subject's key when the subject is the user model, otherwise its raw `user_id` attribute.
- Admin panel writes are recorded as `admin.<event>` (for example `admin.user.updated`) through `AdminActionPerformed`, with the panel user as actor.

## Exclude and Redact

```php
'audit' => [
    'exclude' => [
        \Laravel\Sanctum\PersonalAccessToken::class,
        \App\Models\PageView::class,
    ],
    'redact' => ['api_key'],
    'retention_days' => 365,
],
```

- `audit.exclude` lists model classes, subclasses included, that are never recorded. The default holds Sanctum's `PersonalAccessToken`, which stamps `last_used_at` on every API call. Keep it when you add your own entries. The package also always skips `Audit` itself, every `Pivot` and the membership model.
- A value is stored as `[redacted]` (the key stays) when the model lists it in `$hidden`, casts it `encrypted*` or `hashed`, names it in the model's own `$auditExclude`, or when it is `password`, `remember_token`, `two_factor_secret`, `two_factor_recovery_codes`, `token`, `device_id` (a guest's sign-in credential for `POST auth/guest`) or listed in `audit.redact`. `audit.redact` extends the built-in list and can never shorten it.

```php
class Invoice extends Model
{
    protected array $auditExclude = ['internal_note'];
}
```

## Ignore Attributes

```php
'audit' => [
    'ignore' => [
        \App\Models\Monitor::class => ['last_checked_at'],
    ],
],
```

```php
class Monitor extends Model
{
    protected array $auditIgnore = ['last_checked_at'];
}
```

- `audit.ignore` maps a model class (subclasses included, matched like `exclude`) to attributes; the model's `$auditIgnore` property adds to it (`Redactor::ignoredKeys()`).
- An update whose changes are all ignored, apart from `updated_at`, writes no row. A mixed update is recorded without the ignored keys.
- A model with no ignore list is audited in full, including a bare `touch()`. On a model with one, the skip needs an ignored key to have moved, so its bare `touch()` is recorded too.
- Credential columns (`Redactor::ALWAYS`) are dropped from any ignore list: their change is always recorded, redacted.

## Recording and Suppressing

```php
use FlutterSdk\MagicStarter\Audit\Auditor;

Auditor::record('admin.user_impersonated', $user, ['reason' => 'support ticket 4411']);

Auditor::withoutAuditing(function () use ($team): void {
    $team->update(['name' => 'Renamed in a migration script']);
});
```

- `Auditor::record(string $event, ?Model $subject, array $context = [], ?Authenticatable $actor = null)`: `$actor` falls back to the authenticated user; `$context` is merged over the request context.
- `Auditor::withoutAuditing(callable)` silences the model listener only, nests safely and returns the callback's result. An explicit `record()` inside it is still written, so the usual shape is: suppress the model noise, record one meaningful event.
- `HasAudits` adds `audits()` (a `MorphMany`) for reading. Recording does not depend on the trait.

## Pruning

`magic-starter:audit:prune` deletes rows older than `audit.retention_days` (365 by default, never less than one day) in chunks of 1000 keys. The service provider schedules it daily with `withoutOverlapping()` while the feature is on; the application's scheduler must run for it to fire.

## Deleting a User (GDPR)

Deleting the user model records no row about it. After the transaction commits, `Auditor::forgetUser()` deletes every row whose subject is that user or whose `related_user_id` is that user (their teams, linked accounts). On rows they acted in it nulls `actor_id`, keeps `actor_type`, and removes `ip` and `user_agent` from `context` (rewritten in PHP, chunk by chunk, so it runs the same on sqlite, pgsql and mysql). What happened to someone else's record stays; who did it does not. The purge uses query-builder writes, so it does not re-enter the listener, and a rolled-back deletion erases nothing.

The purge matches user keys only. Rows keyed by an email address elsewhere are not covered: team invitations (their values hold the invited address, and they relate to the team) and newsletter subscribers keep their audit rows.

## What Is Not Captured

- Query-builder bulk writes (`Model::query()->update()`, `->delete()`, `DB::table()`): they fire no model events. `RevokeApiTokens` is one, which is why the panel's admin action records it explicitly.
- Pivot and membership rows, because a pivot has no key of its own in integer mode.
- Any write made with model events muted (`saveQuietly()`, `withoutEvents()`).
- Models in `audit.exclude`, and anything inside `Auditor::withoutAuditing()`.

When a bulk write matters, wrap it in `Auditor::record()` yourself.
