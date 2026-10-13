# Support Desk

- [Introduction](#introduction)
- [Enabling](#enabling)
- [Endpoints](#endpoints)
- [Configuration](#configuration)
- [The AI Desk](#the-ai-desk)
- [Staff Recipients](#staff-recipients)
- [Answering from the Panel](#answering-from-the-panel)
- [Events](#events)
- [Overriding the Actions](#overriding-the-actions)

---

<a name="introduction"></a>
## Introduction

With the support feature on, every customer contact becomes a recorded conversation and staff answer it from the [admin panel](basics/admin-panel.md). A conversation has one opener, and only the opener reads it: a team mate cannot, whatever the team. A visitor with no account follows the thread through a link mailed to them.

Throughout this document `{prefix}` is `config('magic-starter.route_prefix')`.

**Feature:** `Features::support()`. No support route, listener or panel resource exists while the feature is off.

---

<a name="enabling"></a>
## Enabling

```php
// config/magic-starter.php
'features' => [
    Features::support(),
],
```

Run `php artisan magic-starter:install --features=support` for the three migrations (`create_support_conversations_table`, `create_support_messages_table` and `add_ai_columns_to_support_tables`), then `php artisan migrate`. A host that installed support earlier publishes `add_ai_columns_to_support_tables.php` by hand from `vendor/fluttersdk/magic-starter-laravel/database/migrations/`; it is additive and guarded by `hasColumn`, so a second run changes nothing. Run it in the same deploy as the package update: opening a conversation and posting a customer message write its columns whether or not a responder is bound.

---

<a name="endpoints"></a>
## Endpoints

Signed in (`auth:sanctum`, throttle `magic-starter-support`):

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `{prefix}/support/conversations` | The caller's conversations, latest activity first, paginated (`page`, `per_page` 1 to 50, default 15) |
| POST | `{prefix}/support/conversations` | Open one: `topic`, `body`, optional `subject`, optional `source` (`in_app` or `contact_sales`) |
| GET | `{prefix}/support/conversations/{conversation}` | The thread, without staff notes |
| POST | `{prefix}/support/conversations/{conversation}/messages` | Add a message |
| POST | `{prefix}/support/conversations/{conversation}/seen` | Mark the thread read |

Public, no account (throttle `magic-starter-support-public`). The thread token is the only credential and an unknown, replaced, revoked or expired one answers 404:

| Method | Route | Purpose |
|--------|-------|---------|
| POST | `{prefix}/support/public/conversations` | Open one: `email`, `name`, `body`, optional `subject` and `topic`, optional `source` (`public_form` or `contact_sales`) |
| GET | `{prefix}/support/public/thread/{token}` | The thread |
| POST | `{prefix}/support/public/thread/{token}` | Add a message |

The public limits:
- Opening a conversation allows 3 an hour per IP and 3 a day per submitted email.
- Thread reads allow 60 a minute per IP.
- Thread writes allow 10 an hour per token.

The per-email cap bounds how much one address can put in front of staff, but it has a cost. The throttle counts a request before validation runs, so anyone who knows an address can use up its 3 for the day, and the owner of that address cannot open a public conversation until the next day. The owner can still sign in and write from the app, where the per-user limiter applies instead. An app that would rather not carry that cost can rebind `magic-starter-support-public` in its own service provider.

Opening in the app now requires a `topic` from `support.topics` (32 characters at most, the width of its column). The server derives a missing `subject` from the first line of the body (80 characters at most; the topic travels beside it, as its own field and as a column and filter in the staff table), and a `contact_sales` conversation is always filed under `sales`, so it needs no topic. A client older than the topic picker, which sends a `subject` and no `topic`, is still accepted and its conversation is filed under `other`. Rules for every door come from one place, `SupportRules`.

What a customer reads back:

- A conversation carries `topic`, `has_unread` (a staff or AI message newer than `opener_seen_at`), `awaiting_ai` (pending, newest customer message not yet answered) and `waiting_on_team` (open and waiting on a person). The handoff reason never leaves the server.
- A list row carries `last_message`: the newest customer-visible message of kind `message` as `sender_type`, `sender_name`, `body_preview` (140 characters at most) and `created_at`. A staff note or an activity line is never the preview.
- A message carries `kind` (`message` or `activity`) and `sender`: for staff the first name and profile photo, for the AI desk `support.ai.name`, otherwise null. A staff id, surname or email, and the `meta` the desk recorded, are never sent.
- The reply notification carries `action_url`: `support.client_thread_path` with `{id}` replaced by the conversation key.

A conversation belongs to its opener alone. When a visitor verifies an address that matches a public conversation, `LinkSupportConversationsOnVerified` hands it to their account. When a guest session is claimed into an existing account (`POST auth/guest/claim`), the guest's conversations move to that account too, and a conversation whose team the account does not belong to is filed under the account's current team.

---

<a name="configuration"></a>
## Configuration

| Key | Default | Meaning |
|-----|---------|---------|
| `support.message_max_length` | `2000` | Characters allowed in one message, a staff reply included |
| `support.public.link_ttl_days` | `14` | Days a mailed thread link lives (`MAGIC_STARTER_SUPPORT_LINK_TTL_DAYS`) |
| `support.public.thread_url` | `null` | The frontend page that opens a thread; `{token}` is replaced (`MAGIC_STARTER_SUPPORT_THREAD_URL`) |
| `support.public.extra_rules` | `[]` | Field of the public form mapped to rule class names, for a captcha or a blocklist |
| `support.topics` | `billing`, `technical`, `account`, `sales`, `other` | What an opener may file under; labels are `magic-starter::support.topics.<topic>` |
| `support.client_thread_path` | `/support/{id}` | The in-app route of a thread, used for `action_url` |
| `support.ai.*` | see [The AI Desk](#the-ai-desk) | The AI desk's queue, timeouts, limits and quotas |

Replying to a visitor refuses until `support.public.thread_url` is set, because the reply mail carries the link.

> **A published config must add the new keys.** The package merges its config shallowly: a host that published `config/magic-starter.php` keeps its own whole `support` block, so `support.topics`, `support.client_thread_path` and the entire `support.ai` block are absent there. Copy them from the package config. Topics fall back to the defaults above, `support.ai.pool_reservation_tokens` to `6000` and `support.ai.quotas.user_daily_tokens` to `60000`, but any other missing quota key reads as `0`, which refuses every turn: with the responder bound, each AI turn is handed to staff as `quota`. The `support.expectation` key and its `magic-starter::support.expectation` line are gone: nothing read them, and the client shows its own copy.

---

<a name="the-ai-desk"></a>
## The AI Desk

The AI desk answers a verified customer in their own thread and hands anything that needs a person to staff. It is off until the application binds a responder; the package ships no model, no prompt and no default implementation.

### The Contracts

| Contract | Bound by the host | Purpose |
|----------|-------------------|---------|
| `RespondsToSupport` | to enable the AI desk | `respond(SupportTurn): SupportAnswer` answers one customer message; `pool(SupportConversation): SupportAiPool` names the daily token pool (`free` or `paid`) the turn draws from |
| `AssistsSupportStaff` | optional | `assist(SupportConversation): SupportStaffAssist` returns a thread summary and a reply draft for the panel |

```php
$this->app->bind(RespondsToSupport::class, MyResponder::class);
$this->app->bind(AssistsSupportStaff::class, MyStaffAssist::class);
```

A host implementation:

- reads only what the turn carries. `SupportTurn` holds the conversation, the trigger message, the earlier customer-visible history (at most `support.ai.history_messages`, oldest first), the reply `locale` and `deadlineSeconds`, the wall-clock budget for all of its model calls together. The constructor refuses a staff note or a message from another conversation. Every body in it is untrusted customer text, never instructions;
- answers with a `SupportAnswer`: `reply`, `confidence` (0 to 1), `usage` (a `SupportUsage` summed over every model call it made) and, when a person is needed, both a `handoffReason` and a `handoffPriority`. A reply is posted even for a handoff, as what the customer reads before a person takes over;
- posts nothing, changes no conversation and sends no notification; the package persists the reply and records the usage;
- may throw. A throw is a failed turn, never an error shown to the customer.

`AssistsSupportStaff` reads the thread through `customerVisible()` as well: staff notes never go into a prompt.

### Eligibility

The AI desk answers a turn only when the responder is bound, the opener has an account that is not a guest and has a verified email, and the conversation's source is `in_app` or `contact_sales`. It is asked on every turn, so an opener who loses eligibility mid-thread is answered by staff from then on. A public-form conversation never reaches it.

### The Pending Lifecycle

An eligible conversation starts `pending` instead of `open`, and `RespondToSupportConversation` is queued once the conversation commits. Staff are not alerted while it is pending.

1. The job re-reads the conversation and acts only while it is `pending` and its trigger is still the newest unanswered customer message. A newer message, a staff reply or a handoff makes the job a no-op.
2. It re-checks eligibility, then runs the deterministic pre-classifier (see below), then asks `pool()` and reserves quota.
3. It calls `respond()` with a deadline of `support.ai.deadline_seconds` minus the seconds spent waiting for the conversation lock.
4. `ReplyGuard` checks the answer. The reply is then written under a row lock, only while the turn is still owed, and `answered_message_id` is set; the conversation stays `pending` for the next customer message. When the answer asks for a person, the handoff (status, `waiting_since`, `handoff_reason`, priority and the handoff line) is written in that same transaction, so a committed answer can never be left pending; staff are alerted and the assist queued after the commit, and the tokens are settled last. An answer handed off as `unavailable` posts no reply of its own, only the handoff line.
5. A new customer message starts its turn with `ai_failures` at `0`; a superseded turn's failures never count against the next one.

An answer that stays with the AI desk tells the opener the way a staff reply does: a database notification (and push with the OneSignal feature) plus the realtime nudge. A failure to notify never fails the stored answer, and an answer that hands off notifies nobody, since the handoff line and the staff reply that follows are how the customer hears.

A customer who writes again on a pending thread queues a new turn and alerts nobody. A staff reply takes a pending conversation from the AI desk: it becomes `open` in the same transaction, and a job mid-turn discards its answer. Staff can no longer set `pending` themselves; the panel does not offer it and `ChangesSupportConversations` refuses it.

### Handoff

A handoff moves a pending conversation to `open`, stamps `waiting_since` and `handoff_reason`, posts a customer-visible activity line ("I have passed this conversation to our support team..."), alerts staff once (`SupportConversationNeedsStaff` with reason `handoff`) and queues the staff assist when `AssistsSupportStaff` is bound. It is one conditional update on `status = pending`, takes no lock and is idempotent: exactly one caller wins, and only the winner posts the line and alerts.

The staff assist queued on a handoff is a model call the customer's writing triggered, so it is metered like a turn: it is charged to the opener's turn, team, new-account and token counters and to the pool `pool()` names, under the turn id `assist:{last_customer_message_id}`. When the charge is refused, no responder is bound or `pool()` throws, the automatic assist is skipped. The panel's Summarize and an empty Draft reply are staff-initiated and stay unmetered. `PrepareSupportStaffAssist` waits up to 10 minutes for a worker, runs once (a run killed at its timeout is not repeated) and defaults to the `redis` connection and the `support` queue.

| Reason | Set when |
|--------|----------|
| `human_requested` | The pre-classifier finds a request for a person |
| `billing` | The pre-classifier finds money moving or a disputed charge |
| `security` | The pre-classifier finds an account takeover, credentials or a breach |
| `outage` | The pre-classifier finds a report that the service is down |
| `anger` | The pre-classifier finds an upset customer |
| `low_confidence` | The host's answer asks for it; the package applies no confidence threshold of its own |
| `failures` | `support.ai.failures_before_handoff` turns failed in a row |
| `quota` | A user, team, new-account or pool limit is spent |
| `unavailable` | The opener lost eligibility, `pool()` threw, the job died for good or the sweeper found the turn unanswered |

The pre-classifier matches a Turkish and English phrase table against a folded form of the text, before any model is called, over every customer message still unanswered. It over-matches on purpose, but `security` and `outage` entries are claims about the customer's account or this service ("got hacked", "hesabım hacklendi", "site down for me", "<app.name> is down", "<app.name> çöktü"), not topics, so "how do I get notified about an outage" or a status page for a hackathon stays with the AI. A host adds phrases, never replaces the table, with `support.ai.handoff_terms`: a map of reason (`security`, `outage`, `billing`, `anger`, `human_requested`) to a list of phrases. A phrase matches from a word start with any suffix; one ending in `$` stops at a word end.

### Reply Guard

A reply that breaks a rule is not posted and counts as a failed turn: it is blank, longer than `support.ai.reply_max_length`, claims a completed action ("I have refunded", "I went ahead and refunded", "the subscription is cancelled", "aboneliğiniz iptal edilmiştir"), links to a host that is neither the app's own nor in `support.ai.allowed_hosts` (compared exactly; a bare `label.tld` on any top-level domain counts, only a file name such as `config.php` does not), asks for a password, code or card number (a negation such as "never" excuses only the sentence it is in), or carries a confidence outside 0 to 1. A failed turn is retried once by the queue and handed off as `failures` at `support.ai.failures_before_handoff`.

### Quotas and Pools

Each turn is charged against five counters, all dated per day: the opener's turns (`quotas.user_daily`), their team's turns (`quotas.team_daily`), a tighter turn count while the account verified its email less than `quotas.new_account_hours` ago (`quotas.new_account_daily`), the opener's own tokens (`quotas.user_daily_tokens`, default `60000`, so a few accounts cannot drain a shared pool) and the tokens of the pool `pool()` named (`quotas.pools.free` or `quotas.pools.paid`). Both token counters are charged `pool_reservation_tokens` (default `6000`, sized for prompt plus completion; `max_tokens_per_turn` caps only the completion) up front and settled to the real `usage` afterwards. A turn is charged once however often it is retried. A refused turn costs nothing and is handed off as `quota`. A turn, team or pool limit that is missing reads as `0` and refuses; the two token keys above fall back to their defaults.

### The Queue Chain

The job runs on `support.ai.connection` and `support.ai.queue` (defaults `redis` and `support`). The timeouts nest, and the host must pin them in this order, longest first:

```
retry_after (connection)  >  lock_seconds  >  supervisor timeout  >  job_timeout  >  deadline_seconds
```

With the defaults: `retry_after` above 120, `lock_seconds` 120, supervisor `timeout` above 90, `job_timeout` 90, `deadline_seconds` 75. Jobs of one conversation run one at a time under the lock `support-ai:{id}`; a job waits up to 15 seconds for it, and that wait comes out of the model's `deadline_seconds`, so waiting plus the call cannot outrun the job timeout. A job that cannot take the lock goes back on the queue for 10 seconds. The job runs two attempts and stops retrying 10 minutes after dispatch, when it hands the turn off as `unavailable`.

### Sweep and Doctor

`php artisan magic-starter:support:sweep` hands every pending conversation whose newest customer message is still unanswered after `support.ai.sweep_after_minutes` to staff as `unavailable`, so a job that died or never reached a worker strands nobody. The package schedules it every minute while support is on. It skips with a line when the AI columns are not migrated.

`php artisan magic-starter:support:doctor` fails when the responder is bound and no Horizon supervisor in any environment lists `support.ai.queue`. A supervisor's options are its environment entry over the `horizon.defaults` entry of the same name, and `queue` may be a list or a comma-separated string. With nothing bound it reports that the AI desk is off. Run it after every deploy.

### New Columns

`add_ai_columns_to_support_tables` adds `topic`, `last_customer_message_id`, `answered_message_id` and `ai_failures` to `support_conversations`, and `meta` to `support_messages`. "Pending" is the two message pointers differing, so a persisted reply is claimed by id rather than by time. The pointers carry no foreign key. The existing `handoff_reason` column is reused.

### Switching It Off

Remove the `RespondsToSupport` binding: every conversation then starts `open` and waits for staff, as before. A pending conversation left behind is handed off by the next sweep. Setting the quotas to `0` hands every turn to staff as `quota` while the binding stays.

---

<a name="staff-recipients"></a>
## Staff Recipients

`SupportStaffRecipients` decides who is told about a conversation and who may be assigned one. A user qualifies when all of these hold:

- the address is in `magic-starter.admin.emails` (`MAGIC_STARTER_ADMIN_EMAILS`);
- the user is not a guest and has a verified address;
- once a panel carries the plugin, `canAccessPanel()` admits the user on one such panel.

An application that replaces the allowlist gate with `MagicStarterPlugin::authorizeUsing()` must still list its staff in `admin.emails`. The callback narrows the list, it never replaces it: the allowlist is what keeps a support alert from reaching an account you did not name.

---

<a name="answering-from-the-panel"></a>
## Answering from the Panel

The plugin mounts the **Support inbox** under the key `support`; `withoutResources(['support'])` drops it. The navigation badge counts open conversations.

- **List**: number, subject, source, status, priority, assignee, opener and last activity. It hides resolved conversations until the status filter is cleared, and filters by source and assignee.
- **View**: the opener, team and source, then the whole thread. Staff notes sit behind an amber rule under an "Internal note" badge, and every body is rendered escaped. Opening a conversation stamps `staff_seen_at`.
- **Actions**: Reply, Add note, Assign and Change status or priority. Each calls the bound contract with the signed-in staff user, and a refusal reads as a notification. The status choices do not include `pending`.
- **Thread**: chat bubbles for the customer, staff and AI messages, an amber dashed bubble for a note and centred lines for activity. The details list the topic and, when they apply, the AI turns, AI failures and the handoff reason.
- **AI assist**: with `AssistsSupportStaff` bound, **Draft reply** opens the Reply form pre-filled with the stored draft and **Summarize** queues a summary shown as a card above the thread. Opening the page or the form never calls a model: both read the cache, and a miss queues `PrepareSupportStaffAssist`, opens no form and tells staff to open it again in a minute (Reply is there for writing from scratch). An assist is stored for three days and belongs to one turn, so a customer writing again makes it a miss. A handoff queues one itself.

A reply to a pending conversation takes it from the AI desk. A reply reaches the customer once: an opener with an account by a database notification (and push with the OneSignal feature) plus a realtime nudge, a visitor by a mail carrying a newly minted thread link (which retires the link in every earlier mail). A note is stored and audited and goes nowhere. Resolving a conversation revokes its public link.

---

<a name="events"></a>
## Events

| Event | When | Carries |
|-------|------|---------|
| `SupportConversationNeedsStaff` | A customer opened a conversation, wrote again, or the AI desk handed it over | The conversation and a reason, `opened`, `customer_message` or `handoff` |
| `SupportConversationUpdated` | Staff replied or changed a conversation with an opener account | The conversation id only |
| `AdminActionPerformed` | A panel action succeeded | `support.replied`, `support.note_added`, `support.assigned` or `support.changed` |

The package listens for `SupportConversationNeedsStaff` and alerts every staff recipient by database, mail and, with the OneSignal feature, push. The alert and the broadcast carry ids, never the text of a message; the push leaves the subject out as well.

Staff are told once per wait. A customer message on a conversation the AI desk still owns alerts nobody, a message, from an opener or a visitor, to a conversation already waiting on staff alerts nobody. A handoff alerts once, for the caller that won it.

---

<a name="overriding-the-actions"></a>
## Overriding the Actions

Staff writes are two contracts, bound in the provider and replaceable like any other action:

| Contract | Default |
|----------|---------|
| `RepliesToSupport` | `ReplyToSupportConversation` |
| `ChangesSupportConversations` | `ChangeSupportConversation` |

```php
$this->app->bind(RepliesToSupport::class, MyReplyToSupport::class);
```

`RespondsToSupport` and `AssistsSupportStaff` are the two AI contracts and ship unbound; see [The AI Desk](#the-ai-desk).

The reply action does not authorize its `$staff` argument: the panel gate is the authorization. A replacement that is called from anywhere else has to check access itself.
