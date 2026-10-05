# Audit Trail

## Table of Contents

- [Introduction](#introduction)
- [Enabling](#enabling)
- [What Is Recorded](#what-is-recorded)
- [Excluding Models](#excluding-models)
- [Redaction](#redaction)
- [Recording an Event](#recording-an-event)
- [Silencing the Listener](#silencing-the-listener)
- [Reading the Trail](#reading-the-trail)
- [Pruning](#pruning)
- [Deleting a User](#deleting-a-user)
- [What Is Not Captured](#what-is-not-captured)

---

<a name="introduction"></a>
## Introduction

The audit trail answers who changed what, when, and from where. With the feature on, every Eloquent create, update and delete in the application is written to `magic_starter_audits`, together with the acting user and the request it came from. Writes made through the [admin panel](admin-panel.md) are recorded as one `admin.*` row each.

---

<a name="enabling"></a>
## Enabling

Audit is opt-in:

```php
// config/magic-starter.php
'features' => [
    Features::audit(),
],
```

Run `php artisan magic-starter:install --features=audit` to publish the migration, then `php artisan migrate`. While the feature is off, no listener is registered and model events cost nothing extra.

---

<a name="what-is-recorded"></a>
## What Is Recorded

| Column | Content |
|--------|---------|
| `event` | `created`, `updated`, `deleted`, or an explicit name such as `admin.user.updated` |
| `auditable_type`, `auditable_id` | The record the event is about |
| `actor_type`, `actor_id` | The authenticated user, when there is one |
| `related_user_id` | The user the row concerns: the subject itself for a user, otherwise its `user_id` |
| `old_values`, `new_values` | For an update, only the changed keys, with the raw values they held before |
| `context` | `ip`, `user_agent`, `url` (without its query string) and `route` |

Key columns are strings, so one column holds integer and UUID keys alike. A row is inserted after the surrounding transaction commits; a change that rolls back leaves no row.

---

<a name="excluding-models"></a>
## Excluding Models

```php
'audit' => [
    'exclude' => [
        \Laravel\Sanctum\PersonalAccessToken::class,
        \App\Models\PageView::class,
    ],
],
```

`exclude` lists model classes, subclasses included. The default holds Sanctum's `PersonalAccessToken`, which stamps `last_used_at` on every authenticated request and would otherwise write a row per API call; keep it when you add your own. The package always skips its own `Audit` model, every pivot and the team membership model.

---

<a name="redaction"></a>
## Redaction

A sensitive value is stored as `[redacted]`; the key stays, so a reader can see that the field changed. A value is redacted when:

- the model lists it in `$hidden`;
- it is cast `encrypted`, `encrypted:array` (and the other encrypted casts) or `hashed`;
- the model names it in its own `$auditExclude` property;
- it is `password`, `remember_token`, `two_factor_secret`, `two_factor_recovery_codes`, `token` or `device_id` (a guest's `device_id` signs that guest in through `POST auth/guest`, so it is a credential);
- it is listed in `audit.redact`.

```php
// config/magic-starter.php
'audit' => [
    'redact' => ['api_key'],
],
```

```php
class Invoice extends Model
{
    protected array $auditExclude = ['internal_note'];
}
```

`audit.redact` extends the built-in list and can never shorten it, so a published config cannot start storing a password. Old values are stored raw, as the database held them, so an encrypted column appears as ciphertext, never as plaintext.

---

<a name="recording-an-event"></a>
## Recording an Event

For something that is not a model change, such as an impersonation or an export:

```php
use FlutterSdk\MagicStarter\Audit\Auditor;

Auditor::record('admin.user_impersonated', $user, [
    'reason' => 'support ticket 4411',
]);
```

The signature is `record(string $event, ?Model $subject, array $context = [], ?Authenticatable $actor = null)`. The actor defaults to the authenticated user, and `$context` is merged over the request context. It writes nothing while the feature is off.

---

<a name="silencing-the-listener"></a>
## Silencing the Listener

```php
Auditor::withoutAuditing(function () use ($team): void {
    $team->update(['name' => 'Renamed by a migration script']);
});
```

Only the model listener is silenced, and nested calls are safe. An explicit `Auditor::record()` inside the callback is still written: suppress the noise, then record the one event that matters.

---

<a name="reading-the-trail"></a>
## Reading the Trail

Add the `HasAudits` trait to a model to get an `audits()` relation:

```php
use FlutterSdk\MagicStarter\Audit\HasAudits;

class Team extends Model
{
    use HasAudits;
}
```

Recording never depends on the trait; it only adds the read side. With the admin panel installed, the Audits resource and a tab on Users and Teams show the same rows.

---

<a name="pruning"></a>
## Pruning

```bash
php artisan magic-starter:audit:prune
```

Deletes rows older than `magic-starter.audit.retention_days` (365 by default, and never less than one day), in chunks of 1000. While the feature is on, the package schedules it daily with `withoutOverlapping()`; your application's scheduler has to be running.

---

<a name="deleting-a-user"></a>
## Deleting a User

A deleted account's trail is personal data and goes with it. Deleting the user model records no row of its own. Once the transaction commits, `Auditor::forgetUser()` runs:

- every row whose subject is that user is deleted;
- every row whose `related_user_id` is that user (their teams, linked accounts) is deleted;
- every row where they were the actor stays, with `actor_id` set to null, `actor_type` kept, and `ip` and `user_agent` removed from `context`: what happened to somebody else's record remains, who did it does not.

A deletion that rolls back erases nothing.

The purge finds rows by user key only. A row keyed by an email address elsewhere is not covered: a team invitation (its values hold the invited address and it relates to the team, not to a user) and a newsletter subscriber both keep their audit rows. Delete those yourself when your obligations require it.

---

<a name="what-is-not-captured"></a>
## What Is Not Captured

- Query-builder bulk writes (`Model::query()->update()`, `->delete()`, `DB::table()`), which fire no model events.
- Pivot and team membership rows.
- Writes made with model events muted, such as `saveQuietly()`.
- Models in `audit.exclude`, and anything inside `Auditor::withoutAuditing()`.

When such a write matters, wrap it in `Auditor::record()`.
