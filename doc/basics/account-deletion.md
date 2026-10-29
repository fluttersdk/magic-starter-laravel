# Account Deletion

- [Introduction](#introduction)
- [Requesting Deletion](#requesting-deletion)
- [Immediate Deletion](#immediate-deletion)
- [Refusals](#refusals)
- [Grace Period](#grace-period)
- [Purging](#purging)
- [Handing On Shared Teams](#handing-on-shared-teams)
- [Releasing Billing](#releasing-billing)
- [Scheduling the Purge](#scheduling-the-purge)
- [Team Deletion Guard](#team-deletion-guard)
- [Lifecycle Events](#lifecycle-events)
- [Customization](#customization)

---

<a name="introduction"></a>
## Introduction

Deleting an account is a two-step pipeline. The request only schedules the deletion and locks the account; nothing irreversible happens in an HTTP request. Once the grace period is over, the `magic-starter:purge-deleted-users` command deletes the account. A user who asks for an [immediate deletion](#immediate-deletion) skips the wait: the same schedule runs and a queued job purges the account at once.

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
  "message": "Your account will be deleted in 30 days. Sign in again before then to cancel the deletion."
}
```

The sentence tells the user how to cancel because every session was just signed out: signing in again within the grace period is the only way back.

With [`account_deletion.billing`](#releasing-billing) set to `release`, `data` also carries a `billing` block saying what happened to the user's subscriptions. In the default `refuse` mode the key is absent and the body is exactly the one above.

In one transaction, every Sanctum token of the user is revoked and every push device row is dropped, so the account stops signing in and stops ringing at once. The rows that hold the user's data stay until the purge. Scheduling an account that is already scheduled keeps the earlier date.

---

<a name="immediate-deletion"></a>
## Immediate Deletion

App Store Review Guideline 5.1.1(v) accepts a scheduled deletion only beside an option to delete at once. Send `immediately: true` in the same request:

```json
{
  "password": "CurrentP@ssw0rd",
  "immediately": true
}
```

The request runs exactly the scheduled path: the same [refusals](#refusals), the same step-up proof, the same lock and stamp. Only then is a `Jobs\PurgeUserNow` job queued for the account, and the answer is still 202, because the deletion happens on the queue and never inside the request:

```json
{
  "data": {
    "deletion_scheduled_at": "2026-10-03T12:00:00.000000Z",
    "immediate": true
  },
  "message": "Your account is being deleted. This cannot be undone."
}
```

A refusal or a missing proof answers as it does without the flag, and nothing is queued. A request without `immediately`, or with `false`, answers the scheduled body unchanged.

The job runs the purge command's own per-account decision with a cutoff of now, so it re-reads the account under a row lock and re-checks everything the [purge](#purging) does: a schedule cleared by a sign-in in the meantime is left alone, a team that gained a member un-schedules the account (or is [handed on](#handing-on-shared-teams), when that is switched on), a billing subscription holds it, and the rest is deleted through `DeletesUsers`. It carries the account's key rather than the model, so a duplicate that runs after the account is gone does nothing.

> [!WARNING]
> The job is queued after the scheduling transaction commits. With the `sync` queue connection it runs inside the request after all, and a large team cascade can outlast it. Run a real queue worker.

---

<a name="refusals"></a>
## Refusals

A user who owns a team that deletion would damage, or whom a subscription bills directly, is refused before anything is locked. `owns_shared_teams` is the one refusal a host can switch off, with [`hand_on_shared_teams`](#handing-on-shared-teams):

```json
{
  "message": "You own teams that other people belong to. Hand them over or delete them before deleting your account.",
  "code": "owns_shared_teams",
  "team_ids": ["9a8b7c6d-..."],
  "errors": {
    "user": ["..."]
  }
}
```

The status is 422, and the client switches on `code` and uses `team_ids` to point the user at the teams to resolve. For `subscription_active` `team_ids` is empty, since the account itself is the reason.

`team_has_active_subscription` adds `team_providers`, a map from each team id in `team_ids` to the rail billing it, so the client can send the user to the right place:

```json
{
  "message": "One of your teams has an active subscription. Cancel it first.",
  "code": "team_has_active_subscription",
  "team_ids": ["9a8b7c6d-..."],
  "team_providers": {
    "9a8b7c6d-...": "app_store"
  },
  "errors": {
    "user": ["..."]
  }
}
```

A value is `stripe`, `app_store` or `play_store`. A store is named from the team's `plan_provider`; anything else that refused did so on Cashier's own subscription rows and is `stripe`, since the provenance column is written by a webhook and can lag. The key is left out when there is nothing to name. A store subscription counts only while the team holds a paid tier, the same test `HasEntitlement::entitled()` answers: a plan above the free floor on a status that still grants. See [Billing](billing.md#reading-the-entitlement).

| Code | Cause |
|------|-------|
| `owns_shared_teams` | The user owns a team that another person belongs to. Deleting the user would take that person's data with it. Hand the team over or delete it first. A pending invitation is not a member. Never raised while `hand_on_shared_teams` is on. |
| `team_has_active_subscription` | The user owns a team that a store subscription or a valid Stripe subscription is still billing. Cancel it first. |
| `subscription_active` | The user itself is the billable subject (`magic-starter.billing.billable` is `user`) and a store or valid Stripe subscription is billing it. Cancel it first. Never raised while `billing` is `release`. |
| `billing_release_failed` | Only while `billing` is `release`: Stripe refused or could not be reached while the user's subscription was being ended. Nothing was scheduled; the request can be repeated. |

The Stripe check is Cashier's `valid()`, so a trial, a `past_due` dunning period and a subscription cancelled at period end all count as live until the period is over. In the default `refuse` mode this package never cancels a subscription for anyone; [`release`](#releasing-billing) is the opt-in that does, for the user's own subscription.

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
| The user owns a shared team (it gained a member during the grace period), and `hand_on_shared_teams` is off. | **Un-scheduled** and reported. Deleting the user would take the new member's team with them. |
| The account owns a team that a subscription is still billing, or is itself billed by one and `billing` is `refuse`. | **Held** and reported. It is left exactly as it was and retried on the next run. |
| The user signed in after the walk loaded them (the schedule was cleared). | **Cancelled**: skipped without a change. The row is read again under a lock, so a sign-in that lands during the purge waits for it instead of racing the deletion. |
| An orphan owns shared teams, or the user does and `hand_on_shared_teams` is on. | Each team moves to its earliest-joined admin, else its earliest-joined member, who becomes owner. Then the account is deleted. |
| Anything else. | **Deleted.** |

Deletion goes through the `DeletesUsers` contract in one transaction. The user's solo teams are deleted through `DeletesTeams`, so the [billing guard](#team-deletion-guard) runs on every one of them; memberships of other people's teams are detached; every linked social identity and every token is removed, and so are the user's database notifications, notification settings and newsletter subscription, together with the audit rows that describe them. A refusal part way rolls back the teams already deleted. The Apple grants are revoked and the profile photo is deleted only after the outermost transaction commits, because neither can be rolled back: a grant revoked for an account that then survives could not be restored.

Held and un-scheduled accounts are logged as well as printed, because the output of a scheduled run is usually discarded. One account failing is reported and the run moves on, and the exit code is non-zero when any failed. The last line is a tally:

```
Purge finished: 12 deleted, 1 held, 0 un-scheduled, 0 cancelled, 0 failed.
```

---

<a name="handing-on-shared-teams"></a>
## Handing On Shared Teams

By default a user who owns a team another person belongs to cannot delete their account: the request is refused with `owns_shared_teams`, and the purge un-schedules a user whose team gained a member during the grace period. That is the safe answer for an application where a team is somebody's workspace. In an application where a team is a household, it is the wrong one: the person leaving is not taking the household with them.

Switch the refusal off with `config('magic-starter.account_deletion.hand_on_shared_teams')` (env `MAGIC_STARTER_ACCOUNT_DELETION_HAND_ON_SHARED_TEAMS`, default `false`):

```php
'account_deletion' => [
    'grace_days' => 30,
    'hand_on_shared_teams' => true,
],
```

With it on:

- `DELETE {prefix}/user` answers 202 for an owner of a shared team, and nothing changes hands yet: the team keeps its owner and its members for the whole grace period.
- The purge moves each shared team to its earliest-joined admin, else its earliest-joined member, who becomes owner. The rule is the one an orphan's teams already follow, and it goes through `TransfersTeamOwnership`, so a host that rebinds that contract decides the heir's rights too. The leaving user stays on the team as an admin until `DeletesUsers` detaches them.
- A team nobody else belongs to is deleted with the user, through `DeletesTeams`, as before. Every hand-on runs before it, in the same transaction as the deletion: a hand-on that fails rolls back the ones before it, the run reports the account as failed, and the user stays scheduled for the next run.
- A team is read as shared when the purge runs, not when the user asked, so a member who joined during the grace period inherits like any other.

Billing is not affected. A shared team a store or Stripe subscription is still billing refuses the request with `team_has_active_subscription` and holds the purge, on either setting, because handing a team on does not settle who pays for it.

---

<a name="releasing-billing"></a>
## Releasing Billing

By default a subscription that bills the user directly (`magic-starter.billing.billable` is `user`) refuses the request with `subscription_active` and holds the purge: the customer has to cancel first, and nothing is cancelled on their behalf. That is the conservative answer. An application that sells a subscription to the account, and tells the customer on its confirmation screen that deleting the account ends it, wants the other one.

Switch it with `config('magic-starter.account_deletion.billing')` (env `MAGIC_STARTER_ACCOUNT_DELETION_BILLING`, default `refuse`):

```php
'account_deletion' => [
    'grace_days' => 7,
    'billing' => 'release',
],
```

A value that is neither `refuse` nor `release` raises a `RuntimeException` before anything is locked, rather than guessing which way a typo meant.

With `release`:

- `DELETE {prefix}/user` is accepted for a user a subscription bills. Before the account is locked, the `ReleasesUserBilling` contract (default `Actions\ReleaseUserBilling`) ends what it can and reports the rest. It runs after every other refusal, so a refused request cancels nothing, and outside the lock transaction, so no transaction waits on Stripe.
- **Stripe** subscriptions are cancelled at once, with `prorate` and `invoice_now` off: no credit, no final invoice, and the remaining period is not refunded. This is not Cashier's `cancelNow()`, which prorates. Every subscription Stripe could still bill or activate goes, which includes one cancelled at the end of its period, one in dunning and one still waiting on its first payment. The local Cashier row follows through Stripe's own `customer.subscription.deleted` webhook, as it does for every other cancel. A subscription Stripe has no record of counts as ended.
- A **store** subscription (App Store or Play) cannot be cancelled from here: it lives in the customer's store account. It does not stand in the way. The response names it so the client can send the person to the store's cancel screen, and the deletion goes ahead after the grace period either way.
- A **Stripe failure** refuses the request with 422 `billing_release_failed` and locks nothing, so an account is never scheduled with its subscription still charging. The exception is reported and logged through the billing log. When a user has several subscriptions the ones cancelled before the failure stay cancelled, and repeating the request finds them ended.
- An **orphan** is released too, since nobody is left to cancel for them, and a Stripe failure stops its schedule as well, so the identity provider's notification fails and is delivered again.
- The **purge** and `DeletesUsers` no longer hold or refuse an account for the user's own subscription. A team a store or Stripe subscription bills is not affected: it refuses the request with `team_has_active_subscription` and holds the purge on either setting. Under `billing.billable` = `team` the user has nothing to release.

The 202 body gains `data.billing`, on the scheduled and the immediate answer alike:

```json
{
  "data": {
    "deletion_scheduled_at": "2026-10-03T12:00:00.000000Z",
    "billing": {
      "cancelled": [
        { "provider": "stripe", "id": "sub_1Nxyz..." }
      ],
      "store": [
        { "provider": "app_store", "manage_url": "https://apps.apple.com/account/subscriptions" }
      ]
    }
  },
  "message": "Your account will be deleted in 7 days. Sign in again before then to cancel the deletion."
}
```

| Field | Meaning |
|-------|---------|
| `billing.cancelled` | Each subscription ended now: its `provider` (`stripe`) and the rail's `id`. |
| `billing.store` | Each store subscription still billing, which the user has to cancel there: its `provider` (`app_store` or `play_store`) and the `manage_url` the store gave for it, `null` when it gave none. |

Both lists are empty for a user nothing bills. A store subscription counts only while the user holds a paid tier, the test the [team deletion guard](#team-deletion-guard) applies.

Signing in during the grace period cancels the deletion as always, but a subscription cancelled by the release stays cancelled: the customer can subscribe again. Nothing restores the store's.

To end a rail this package does not know, or to add a step (a CRM, a refund), bind your own `ReleasesUserBilling`; the scheduler only reads the report it returns.

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

<a name="lifecycle-events"></a>
## Lifecycle Events

The package locks only what it owns. A host that runs work on the user's behalf (scheduled jobs, monitors, outgoing webhooks) pauses and resumes it on two events:

| Event | Dispatched | Properties |
|-------|------------|------------|
| `Events\UserDeletionScheduled` | After the schedule commits: a user's request, an immediate one, or an orphan's. | `user`, `immediate` (`true` when the purge was queued at once) |
| `Events\UserDeletionCancelled` | When a sign-in during the grace period clears the schedule, and when the purge un-schedules an account whose team gained a member. | `user` |

```php
use FlutterSdk\MagicStarter\Events\UserDeletionCancelled;
use FlutterSdk\MagicStarter\Events\UserDeletionScheduled;
use Illuminate\Support\Facades\Event;

Event::listen(UserDeletionScheduled::class, fn (UserDeletionScheduled $event) => /* pause */);
Event::listen(UserDeletionCancelled::class, fn (UserDeletionCancelled $event) => /* resume */);
```

`UserDeletionScheduled` fires again when an already scheduled account is scheduled once more, such as a provider notification delivered twice, so keep the listener idempotent. A deleted account fires no event of its own: its rows go with it through `DeletesUsers`.

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
| `SchedulesUserDeletion` | `Actions\ScheduleUserDeletion` | Refuses, releases billing when configured, locks and stamps the account, and queues `PurgeUserNow` for an immediate deletion. Returns the billing report. |
| `ReleasesUserBilling` | `Actions\ReleaseUserBilling` | Ends the card rail's subscriptions and reports the store's, when `billing` is `release`. |
| `DeletesUsers` | `Actions\DeleteUser` | The irreversible end, run by the purge. |

See [Action Contracts](../architecture/action-contracts.md) for how bindings work.
