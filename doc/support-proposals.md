# Support Proposals

- [Introduction](#introduction)
- [Enabling](#enabling)
- [Registering the Tool](#registering-the-tool)
- [The Three Actions](#the-three-actions)
- [Lifecycle](#lifecycle)
- [Who May Ask and Who May Decide](#who-may-ask-and-who-may-decide)
- [Ordering Guarantee](#ordering-guarantee)
- [What Staff See](#what-staff-see)
- [What the Customer Sees](#what-the-customer-sees)

---

<a name="introduction"></a>
## Introduction

A customer who writes "please give me a few more days on my trial" wants a change to their subscription. The support AI must not make that change: a model that can charge, cancel or extend on a customer's word is a model an attacker can talk into it. Support proposals split the work in three:

1. The AI **records** the request. `propose_billing_change` writes a row and changes nothing else.
2. A staff member **approves or rejects** it in the admin panel, after reading the customer's own words.
3. The package **executes** it on approval, through the same billing contract the operator's panel actions use, with the staff member as the actor.

A proposal is a [support tool](support-tools.md) that writes. Tools never execute: the one write it makes is a row for a person to read, and only that person's approval changes the subscription.

**Feature:** `Features::supportProposals()`. It needs `Features::support()` and the billing features as well, because the billing features supply the owner gate (see [Who May Ask and Who May Decide](#who-may-ask-and-who-may-decide)). Listing `support-proposals` alone turns nothing on.

---

<a name="enabling"></a>
## Enabling

```php
// config/magic-starter.php
'features' => [
    Features::support(),
    Features::billing(),
    Features::supportProposals(),
],
```

Publish the migration, `create_support_proposals_table.php`, with the rest of the support migrations and run it:

```bash
php artisan magic-starter:install --features=support
php artisan migrate
```

The table is part of the `support` migration list, so a host that installed support earlier runs the same command again: a migration already published is skipped and only the new one is added. Proposals point at conversations, messages and users, so when you copy the file by hand, stamp it after theirs.

Two keys bound the feature, under `support.proposals`:

| Key | Default | Env | Meaning |
|-----|---------|-----|---------|
| `expires_hours` | `72` | `MAGIC_STARTER_SUPPORT_PROPOSALS_EXPIRES_HOURS` | How long a request can be approved after it is recorded |
| `max_trial_extension_days` | `14` | `MAGIC_STARTER_SUPPORT_PROPOSALS_MAX_TRIAL_DAYS` | The most days one request may move a trial end |

---

<a name="registering-the-tool"></a>
## Registering the Tool

The package ships the tool and never registers it: the [tool registry](support-tools.md#registering-a-tool) is empty until your code fills it, and your SDK adapter decides what the model is offered. Register `ProposeBillingChangeTool` in a service provider's `boot()`:

```php
namespace App\Providers;

use FlutterSdk\MagicStarter\Support\SupportAi\Tools\ProposeBillingChangeTool;
use FlutterSdk\MagicStarter\Support\SupportAi\Tools\SupportToolRegistry;
use Illuminate\Support\ServiceProvider;

class SupportToolServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (config('services.support.proposals')) {
            app(SupportToolRegistry::class)->register(ProposeBillingChangeTool::class);
        }
    }
}
```

Put the registration behind a switch in your host that defaults to off, as above. A model that can ask for a billing change deserves a staged rollout: turn it on for one environment, read the first proposals in the panel, then widen it. With the switch off the model is never offered the tool, whatever the feature flag says.

The tool is named `propose_billing_change` and takes three arguments:

| Argument | Meaning |
|----------|---------|
| `action` | `extend_trial`, `cancel_at_period_end` or `resume` |
| `until` | A calendar day, `YYYY-MM-DD`, for `extend_trial` only |
| `reason` | Why the customer asked, in the model's words, at most 500 characters |

The account and the owner check come from the conversation, never from an argument. The model names what to ask for, not for whom.

---

<a name="the-three-actions"></a>
## The Three Actions

| Action | Runs on approval | Recorded only when |
|--------|------------------|--------------------|
| `extend_trial` | `AdministersBilling::extendTrial()` | The subscription is on a trial that is not cancelled, and `until` is a real day after the current trial end and at most `max_trial_extension_days` past it |
| `cancel_at_period_end` | `AdministersBilling::cancel()` | The subscription is active and not already cancelled |
| `resume` | `AdministersBilling::resume()` | The subscription is cancelled and still inside its period |

The tool checks these before it writes, with the predicates the operator's own actions apply, so staff are never shown a request that approval would refuse on sight. Every refusal is a sentence addressed to the model ("The subscription is not on a trial, so there is nothing to extend."), which it relays to the customer. A request is also refused for a visitor without an account, a conversation without a billing account, an account without a Stripe subscription, a malformed date, and one that is already waiting: a conversation holds at most one pending request per action.

> [!NOTE]
> The handoff classifier runs before the model does. A customer message containing "cancel", "iptal", "downgrade" or "change plan" (and the refund and charge words, such as "refund", "iade" or "double charge") is handed to staff with the billing reason and never reaches the model (`src/Support/SupportAi/HandoffClassifier.php`). A `cancel_at_period_end` proposal therefore arises only when the customer's wording passes the classifier, and a `resume` request that says "cancellation" is handed off too. Trial extension requests reach the model. `support.ai.handoff_terms` adds phrases to the table and cannot remove the built-in ones: it over-matches on purpose, because a false positive costs one human answer.

---

<a name="lifecycle"></a>
## Lifecycle

| Status | Meaning |
|--------|---------|
| `draft` | Written during an AI turn, hidden from staff |
| `pending` | The turn that wrote it was stored; staff may decide until `expires_at` |
| `executing` | An approval claimed it and is calling billing; the outcome is not yet known |
| `approved` | The change ran |
| `failed` | The change was refused, or the request no longer held at approval |
| `rejected` | A staff member declined it |
| `expired` | The deadline passed with no decision |

A draft stays hidden because the customer has not read the answer that announces it. When `RespondToSupportConversation` stores the turn, it promotes that turn's drafts to `pending` and sends staff a `SupportConversationNeedsStaff` alert with the reason `proposal`. A turn that fails or is superseded never lands, so its draft stays hidden and never blocks a new request: a retried turn finds its own row by trigger message and action, and answers as if it had just recorded it.

Approval reads the world again under a row lock instead of trusting the snapshot taken at recording. It moves a proposal to `failed`, with the reason on the row, when:

- the conversation has no billing account any more (`no_billable`), or the account is another one than the request named (`billable_changed`);
- the customer who asked is no longer the account owner (`owner_changed`);
- an `extend_trial` day no longer fits the trial as it stands (`params_stale`).

A proposal found past `expires_at` is set to `expired` and not run.

---

<a name="who-may-ask-and-who-may-decide"></a>
## Who May Ask and Who May Decide

**The owner gate.** Only the account owner asks. The tool resolves the billing account from the conversation, then asks `Gate::forUser($opener)->allows('manageBilling', $billable)`, the gate the billing endpoints apply. That gate belongs to the billing features, which is why `support-proposals` needs them. A member of a team who is not its owner gets a sentence telling them to write in from the owner's account. Approval asks the gate again, so an owner who was replaced between the request and the decision fails the proposal.

**The billing staff gate.** Only a staff member the admin panel clears for billing decides. `DecidesSupportProposals` requires a `FilamentUser` that can access the panel and passes `BillingAuthorization::allows()`: an empty `magic-starter.admin.billing_emails` lets every panel admin through, a non-empty one admits only the addresses it lists, and an `authorizeBillingUsing()` callback on the plugin replaces both. The decision buttons are hidden from everyone else, and the contract re-checks on its own, so a crafted Livewire call is refused.

`DecidesSupportProposals` is bound only where Filament is installed.

---

<a name="ordering-guarantee"></a>
## Ordering Guarantee

Stripe has no rollback. A charge or a trial change that already happened stays, whatever the database does afterwards. So approval is ordered to keep the database from lying about it:

1. In one short transaction, under a row lock, it re-checks the request and claims the row as `executing`. A second approval finds the row no longer `pending` and stops.
2. With **no database transaction open**, it calls `AdministersBilling`. The Stripe call and the `billing_events` rows it writes commit on their own.
3. In a second short transaction it writes `approved` and posts the customer's line.

Two outcomes follow:

- A `BillingAdministrationRefused` (the subscription is no longer on a trial, Stripe rejected the call) leaves the row `failed` with the refusal's reason.
- Any other fault, such as a process killed mid-call, leaves the row `executing`. **That status means the outcome is unknown.** Open the account's billing events, check whether the change applied, and use **Mark failed** with a reason if it did not. Never approve twice by other means: the claim exists so that a second approval cannot charge again.

> [!WARNING]
> `DecidesSupportProposals::approve()` refuses to run inside an open database transaction, because a rollback would hide a billing call that already happened. The proposals tab opts its Approve action out of the panel's transaction itself (`->databaseTransaction(false)`), so a panel that calls `databaseTransactions()` still approves. Code of your own that calls `approve()` must call it outside a transaction.

---

<a name="what-staff-see"></a>
## What Staff See

A "Billing requests" tab on the support conversation page, shown only while the feature is on. It lists every proposal that is not a draft: the action, its parameters, the status, the requester, and when it was created and expires.

The details of a request carry what a staff member needs to decide:

- the customer's own words, from the message that triggered it;
- the AI's rationale;
- for a trial extension, the current trial end and how many times the account's trial was already extended;
- how many customer messages arrived since the request, which may have changed or withdrawn it.

The customer's words and the rationale are untrusted text. They are shown as escaped plain text, never HTML or Markdown, and labelled unverified: the rationale is the model's reading of the customer, not a fact.

The tab offers **Approve** and **Reject** (with a reason) on a pending request before its deadline, and **Mark failed** (with a reason) on an `executing` one. Each decision is audited as `support.proposal.approve`, `support.proposal.reject` or `support.proposal.mark_failed`.

---

<a name="what-the-customer-sees"></a>
## What the Customer Sees

The customer never sees a proposal row. The AI tells them in its answer that a person will review the request, and the thread then gets a system activity line in the conversation's language, not the staff member's:

| Outcome | Line |
|---------|------|
| Approved | A teammate approved your request: Extend the trial. |
| Rejected | A teammate declined your request: Extend the trial. |
| Billing refused the change | We could not apply your request (Extend the trial). A teammate will follow up here. |

The thread refreshes through `SupportConversationUpdated`. A request that fails the re-check at approval, an expired request and a **Mark failed** post no line, because nothing was decided that the customer can act on; staff follow up in the thread.
