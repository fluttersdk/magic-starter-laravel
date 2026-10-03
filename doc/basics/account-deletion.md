# Account Deletion

- [Introduction](#introduction)
- [Requesting Deletion](#requesting-deletion)
- [Refusals](#refusals)
- [Grace Period](#grace-period)
- [Purging](#purging)
- [Scheduling the Purge](#scheduling-the-purge)
- [Team Deletion Guard](#team-deletion-guard)
- [Customization](#customization)

---

<a name="introduction"></a>
## Introduction

Deleting an account is a two-step pipeline. The request only schedules the deletion and locks the account; nothing irreversible happens in an HTTP request. Once the grace period is over, the `magic-starter:purge-deleted-users` command deletes the account.

Throughout this document `{prefix}` is `config('magic-starter.route_prefix')`. The `users` table needs the `deletion_scheduled_at` and `orphaned_at` columns, published by `add_deletion_columns_to_users_table.php`, which is one of the core migrations `magic-starter:install` always publishes. Scheduling an account on a table without them fails with an exception that names the missing column.

---

<a name="requesting-deletion"></a>
## Requesting Deletion

**Endpoint:** `DELETE {prefix}/user` (`POST` is accepted as well)

**Middleware:** `auth:sanctum`

The caller confirms their identity with `password`. A password-less account uses a [step-up proof](social-login.md#step-up-confirmation) instead: a TOTP `code` or a `confirmation_token`.

### Success Response (202)

```json
{
  "data": {
    "deletion_scheduled_at": "2026-10-03T12:00:00.000000Z"
  },
  "message": "Your account will be deleted in 30 days. You can cancel this anytime."
}
```

In one transaction, every Sanctum token of the user is revoked and every push device row is dropped, so the account stops signing in and stops ringing at once. The rows that hold the user's data stay until the purge. Scheduling an account that is already scheduled keeps the earlier date.

---

<a name="refusals"></a>
## Refusals

A user who still owns a team that deletion would damage is refused before anything is locked:

```json
{
  "message": "You own teams that you cannot delete. Transfer ownership or delete them before deleting your account.",
  "code": "owns_shared_teams",
  "team_ids": ["9a8b7c6d-..."],
  "errors": {
    "user": ["..."]
  }
}
```

The status is 422, and the client switches on `code` and uses `team_ids` to point the user at the teams to resolve.

| Code | Cause |
|------|-------|
| `owns_shared_teams` | The user owns a team that another person belongs to. Deleting the user would take that person's data with it. Transfer ownership or delete the team first. A pending invitation is not a member. |
| `team_has_active_subscription` | The user owns a team that a store subscription or a valid Stripe subscription is still billing. Cancel it first. |

The Stripe check is Cashier's `valid()`, so a trial, a `past_due` dunning period and a subscription cancelled at period end all count as live until the period is over. This package never cancels a subscription for anyone.

---

<a name="grace-period"></a>
## Grace Period

The grace period is `config('magic-starter.account_deletion.grace_days')` (env `MAGIC_STARTER_ACCOUNT_DELETION_GRACE_DAYS`, default `30`). `0` deletes at the next purge run with no grace period, which is not recommended for production.

Signing in during the grace period cancels the deletion, by any method: password, 2FA challenge, [social login](social-login.md), OTP or guest. The sign-in response then carries the flag, and its `message` is the cancellation sentence:

```json
{
  "data": {
    "user": { "..." },
    "token": "2|def456...",
    "deletion_cancelled": true
  },
  "message": "Account deletion cancelled. Your account is active again."
}
```

The user resource exposes `deletion_scheduled_at`, so a client that holds a token can tell that a deletion is pending.

An **orphan** is an account whose identity provider deleted it: Apple's `account-deleted` [notification](social-login.md#apple-notifications) for a user with no password and no other active provider. Its deletion is scheduled without the refusals above, since nobody is left to act on them, and signing in by another method does not cancel it.

---

<a name="purging"></a>
## Purging

```bash
php artisan magic-starter:purge-deleted-users
```

The command selects every account whose `deletion_scheduled_at` is older than the grace period, and checks again what the scheduler checked, because the period is long enough for it to change:

| Situation | Outcome |
|-----------|---------|
| The user owns a shared team (it gained a member during the grace period). | **Un-scheduled** and reported. Deleting the user would take the new member's team with them. |
| The account owns a team that a subscription is still billing. | **Held** and reported. It is left exactly as it was and retried on the next run. |
| An orphan owns shared teams. | Each team moves to its earliest-joined admin, else its earliest-joined member, who becomes owner. Then the account is deleted. |
| Anything else. | **Deleted.** |

Deletion goes through the `DeletesUsers` contract in one transaction. The user's solo teams are deleted through `DeletesTeams`, so the [billing guard](#team-deletion-guard) runs on every one of them; memberships of other people's teams are detached; every linked social identity is removed, after the Apple grant is revoked; tokens and the profile photo are deleted. A refusal part way rolls back the teams already deleted.

Held and un-scheduled accounts are logged as well as printed, because the output of a scheduled run is usually discarded. One account failing is reported and the run moves on, and the exit code is non-zero when any failed. The last line is a tally:

```
Purge finished: 12 deleted, 1 held, 0 un-scheduled, 0 failed.
```

---

<a name="scheduling-the-purge"></a>
## Scheduling the Purge

The package registers the command and does not schedule it. Without a schedule line, nothing is ever deleted. Add one in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('magic-starter:purge-deleted-users')->daily();
```

Daily is enough: the grace period is counted in days.

---

<a name="team-deletion-guard"></a>
## Team Deletion Guard

`DeletesTeams` is bound to `Actions\SubscriptionGuardedDeleteTeam`. It refuses to delete a team while either payment rail is billing it, for both `DELETE {prefix}/teams/{team}` and the purge:

- a **store** subscription lives in the customer's App Store or Play account, which the application cannot cancel, so the owner has to cancel it there;
- a **Stripe** subscription that Cashier reports as `valid()`, through the grace period of a cancelled one.

Each rail refuses with its own sentence, as a 422 validation error on the `team` field. The guard runs before the parent action detaches any member, so a refused team is left whole.

> [!NOTE]
> A host that bound or extended this action under its earlier, store-only class name has to switch to `SubscriptionGuardedDeleteTeam`. See the changelog.

---

<a name="customization"></a>
## Customization

Both halves are contract bindings, so a host can swap either:

| Contract | Default action | Role |
|----------|----------------|------|
| `SchedulesUserDeletion` | `Actions\ScheduleUserDeletion` | Refuses, locks and stamps the account. |
| `DeletesUsers` | `Actions\DeleteUser` | The irreversible end, run by the purge. |

See [Action Contracts](../architecture/action-contracts.md) for how bindings work.
