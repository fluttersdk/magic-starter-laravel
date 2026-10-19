# Support Desk

- [Introduction](#introduction)
- [Enabling](#enabling)
- [Endpoints](#endpoints)
- [Configuration](#configuration)
- [The AI Desk](#the-ai-desk)
- [Knowledge Base](#knowledge-base)
- [Satisfaction Rating](#satisfaction-rating)
- [In-app Feedback](#in-app-feedback)
- [Attachments](#attachments)
- [Client Diagnostics](#client-diagnostics)
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

Five further features sit on top of it and are off by default; each needs `support` on and adds its own routes, fields and panel parts:

| Feature | Adds |
|---------|------|
| `Features::supportCsat()` (`support-csat`) | A thumbs rating of a resolved conversation |
| `Features::supportFeedback()` (`support-feedback`) | The occasional 0-10 feedback card and the staff feedback list |
| `Features::supportAttachments()` (`support-attachments`) | Images and PDFs on a message |
| `Features::supportDiagnostics()` (`support-diagnostics`) | The client info and the screenshot a new conversation carries |
| `Features::supportProposals()` (`support-proposals`) | Billing changes the AI desk records for staff to approve; also needs billing. See [Support Proposals](support-proposals.md) |

---

<a name="enabling"></a>
## Enabling

```php
// config/magic-starter.php
'features' => [
    Features::support(),
    Features::supportCsat(),
    Features::supportFeedback(),
    Features::supportAttachments(),
    Features::supportDiagnostics(),
],
```

Run `php artisan magic-starter:install --features=support` for the seven migrations (`create_support_conversations_table`, `create_support_messages_table`, `add_ai_columns_to_support_tables`, `add_ai_summary_to_support_conversations_table`, `add_feedback_columns_to_support_conversations_table`, `create_support_message_attachments_table` and `create_support_feedback_table`), then `php artisan migrate`. The last three are for the rating, attachment and feedback features and are installed with `support` whichever of those you turn on. A host that installed support earlier publishes the missing ones by hand from `vendor/fluttersdk/magic-starter-laravel/database/migrations/`; they are additive and guarded by `hasColumn` or `hasTable`, so a second run changes nothing. Run it in the same deploy as the package update: opening a conversation and posting a customer message write its columns whether or not a responder is bound.

---

<a name="endpoints"></a>
## Endpoints

Signed in (`auth:sanctum`, throttle `magic-starter-support`):

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `{prefix}/support/conversations` | The caller's conversations, latest activity first, paginated (`page`, `per_page` 1 to 50, default 15) |
| POST | `{prefix}/support/conversations` | Open one: `topic`, `body`, optional `subject`, optional `source` (`in_app` or `contact_sales`), plus `attachments`, `screenshot` and `client_context` with their features |
| GET | `{prefix}/support/conversations/{conversation}` | The thread, without staff notes |
| POST | `{prefix}/support/conversations/{conversation}/messages` | Add a message, with `attachments` under `support-attachments` |
| POST | `{prefix}/support/conversations/{conversation}/seen` | Mark the thread read |
| POST | `{prefix}/support/conversations/{conversation}/rating` | Rate a resolved conversation (`support-csat`) |
| GET | `{prefix}/support/feedback` | Whether the feedback card is due (`support-feedback`) |
| POST | `{prefix}/support/feedback` | Answer or dismiss the card (`support-feedback`) |
| GET | `{prefix}/support/conversations/{conversation}/attachments/{attachment}` | Read one file (`support-attachments`) |
| POST | `{prefix}/support/conversations/{conversation}/attachments/{attachment}/link` | A 60 second signed url to a PDF (`support-attachments`) |
| GET | `{prefix}/support/attachments/{attachment}/download` | The PDF behind a signed link, as a download; the signature is the credential, no bearer (`support-attachments`) |

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
- A message carries `kind` (`message` or `activity`) and `sender`: for staff the first name and profile photo, for the AI desk `support.ai.name`, otherwise null. A staff id, surname or email, and the `meta` the desk recorded, are never sent; the one exception is `articles` on an AI message (see Knowledge Base).
- The reply notification carries `action_url`: `support.client_thread_path` with `{id}` replaced by the conversation key.

Tapping Contact sales again continues the conversation instead of filing another: while the opener's `contact_sales` conversation in their current team is not `resolved`, the body is added to it as a customer message and the same conversation is returned with 201, so the client navigates to its id. A resolved conversation, one filed under another team (the host builds the AI's account context from the conversation's team) and every `in_app` or public conversation are never reused. The open locks the opener's own user row first, so two taps at once cannot both create.

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
| `support.mail.ticket_created` | `false` | Confirms a new conversation to its opener: a visitor gets a freshly minted thread link (needs `support.public.thread_url`), an account holder an in-app link (a user model implementing `MustVerifyEmail` with `Features::emailVerification()` enabled, and a verified address; a guest never gets it). Only the package copy and the conversation number are mailed, never the submitter's text. With it on, every caller of `openPublic()`, not only the package route, mails any address typed into it, so each must carry a captcha (`support.public.extra_rules` for the package route) |
| `support.mail.csat_invitation` | `false` | On a transition to resolved, mails the account holder (a `MustVerifyEmail` user model with `Features::emailVerification()` enabled, and a verified, non-blank address) a link to rate the thread, when `support-csat` is on and the conversation is not yet rated for this resolution. Never a visitor or a guest |
| `support.topics` | `billing`, `technical`, `account`, `sales`, `other` | What an opener may file under; labels are `magic-starter::support.topics.<topic>` |
| `support.client_thread_path` | `/support/{id}` | The in-app route of a thread, used for `action_url` |
| `support.ai.*` | see [The AI Desk](#the-ai-desk) | The AI desk's queue, timeouts, limits and quotas |
| `support.attachments.*` | see [Attachments](#attachments) | The disk, count, size, type and daily limits of a file |
| `support.feedback.*` | see [In-app Feedback](#in-app-feedback) | The pacing and the low-score rules of the feedback card |

Replying to a visitor refuses until `support.public.thread_url` is set, because the reply mail carries the link.

> **A published config must add the new keys.** The package merges its config shallowly: a host that published `config/magic-starter.php` keeps its own whole `support` block, so `support.topics`, `support.client_thread_path`, `support.attachments`, `support.feedback` and the entire `support.ai` block are absent there. The attachment and feedback keys fall back to the defaults in their tables below, `support.ai.image_reservation_tokens` to `1600`, and `support.ai.cache_weights` to read `0.0` and write `1.0`. Copy them from the package config. A missing `support.mail` reads as both mails off, so add `ticket_created` and `csat_invitation` under `mail` before turning either on. With `ticket_created` on, give `support.public.extra_rules` a captcha first: the public form then mails any address typed into it. Topics fall back to the defaults above, `support.ai.pool_reservation_tokens` to `6000` and `support.ai.quotas.user_daily_tokens` to `60000`, but any other missing quota key reads as `0`, which refuses every turn: with the responder bound, each AI turn is handed to staff as `quota`. The `support.expectation` key and its `magic-starter::support.expectation` line are gone: nothing read them, and the client shows its own copy.

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

- reads only what the turn carries. `SupportTurn` holds the conversation, the trigger message, the earlier customer-visible history (at most `support.ai.history_messages`, oldest first), the stored `summary` with its `summaryThroughId` and `historyTruncated` (see History Summary below), the reply `locale` and `deadlineSeconds`, the wall-clock budget for all of its model calls together. The constructor refuses a staff note or a message from another conversation. Every body in it is untrusted customer text, never instructions;
- answers with a `SupportAnswer`: `reply`, `confidence` (0 to 1), `usage` (a `SupportUsage` summed over every model call it made), optionally a `compaction` (see History Summary below), optionally `articleRefs` (see Knowledge Base) and, when a person is needed, both a `handoffReason` and a `handoffPriority`. A reply is posted even for a handoff, as what the customer reads before a person takes over;
- posts nothing, changes no conversation and sends no notification; the package persists the reply and records the usage;
- may throw. A throw is a failed turn, never an error shown to the customer.

`AssistsSupportStaff` reads the thread through `customerVisible()` as well: staff notes never go into a prompt.

With `support-attachments` on, `SupportTurn::images` lists the images the customer uploaded with the trigger message, oldest first, as `disk`, `path` and `mime` (a pointer, never bytes), and `SupportTurn::pdfCount` counts its PDFs. Only that message's own uploads are listed: the auto screenshot, staff files and the files of earlier messages never are. A responder reads an image only when its provider takes images, treats its pixels as data and never as instructions, and tells the model how many PDFs there are without reading them.

### History Summary

A long thread outgrows `support.ai.history_messages`. The conversation can carry a stored summary of its older messages, written by the host's responder and never by the package.

- `ai_summary` holds the text and `ai_summary_through_id` the id of the last message it covers. A turn reads messages strictly after that boundary, in `(created_at, id)` order, or the whole thread when there is no summary, capped at `support.ai.history_messages`. A boundary message that no longer exists counts as no boundary, and the turn then carries no `summary`, since the whole thread it covered is history again.
- The job fetches one message beyond the cap to set `SupportTurn::historyTruncated`: true when messages between the boundary and the shown history were left out, so the responder knows its context is partial and can compact.
- A responder compacts by returning `SupportAnswer(..., compaction: new SupportHistorySummary($text, $throughMessageId))`. A blank `$text` throws. The pointer carries no foreign key, like the other pointers.
- The job stores a compaction inside the transaction that posts the answer, so a compaction never outlives its answer, and only while `ai_summary_through_id` still equals the value this turn read. When another turn compacted first, the later one is dropped. A failed, unavailable or superseded turn stores none. A `$throughMessageId` that is not a message of this conversation sent before the trigger, or is not shaped like a key of this deployment, is dropped with a warning; the answer still posts.
- The summary is untrusted model output derived from customer text. The host puts it in the prompt behind a fence, as data and never as instructions, like every body in the turn.
- Staff see `ai_summary` read-only in the support conversation view; nothing edits it. It is left out of audit rows, so the text never lands in the audit trail.

A host that published its migrations adds `add_ai_summary_to_support_conversations_table.php` (`magic-starter:install` or copy it from `vendor/fluttersdk/magic-starter-laravel/database/migrations/`). It adds `ai_summary` (text, nullable) and `ai_summary_through_id` (nullable pointer, no foreign key), so a second run changes nothing and a conversation without a summary behaves as before.

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
| `low_confidence` | The host's answer asks for it (the package applies no confidence threshold of its own), or the trigger is files with no text, which gives the model nothing to answer and goes to a person at normal priority |
| `failures` | `support.ai.failures_before_handoff` turns failed in a row |
| `quota` | A user, team, new-account or pool limit is spent |
| `unavailable` | The opener lost eligibility, `pool()` threw, the job died for good or the sweeper found the turn unanswered |

The pre-classifier matches a Turkish and English phrase table against a folded form of the text, before any model is called, over every customer message still unanswered. It over-matches on purpose, but `security` and `outage` entries are claims about the customer's account or this service ("got hacked", "hesabım hacklendi", "site down for me", "<app.name> is down", "<app.name> çöktü"), not topics, so "how do I get notified about an outage" or a status page for a hackathon stays with the AI. A host adds phrases, never replaces the table, with `support.ai.handoff_terms`: a map of reason (`security`, `outage`, `billing`, `anger`, `human_requested`) to a list of phrases. A phrase matches from a word start with any suffix; one ending in `$` stops at a word end.

### Reply Guard

A reply that breaks a rule is not posted and counts as a failed turn: it is blank, longer than `support.ai.reply_max_length`, claims a completed action ("I have refunded", "I went ahead and refunded", "the subscription is cancelled", "aboneliğiniz iptal edilmiştir"), links to a host that is neither the app's own nor in `support.ai.allowed_hosts` (compared exactly; a bare `label.tld` on any top-level domain counts, only a file name such as `config.php` does not), asks for a password, code or card number (a negation such as "never" excuses only the sentence it is in), or carries a confidence outside 0 to 1. A failed turn is retried once by the queue and handed off as `failures` at `support.ai.failures_before_handoff`.

### Quotas and Pools

Each turn is charged against five counters, all dated per day: the opener's turns (`quotas.user_daily`), their team's turns (`quotas.team_daily`), a tighter turn count while the account verified its email less than `quotas.new_account_hours` ago (`quotas.new_account_daily`), the opener's own tokens (`quotas.user_daily_tokens`, default `60000`, so a few accounts cannot drain a shared pool) and the tokens of the pool `pool()` named (`quotas.pools.free` or `quotas.pools.paid`). Both token counters are charged `pool_reservation_tokens` (default `6000`, sized for prompt plus completion; `max_tokens_per_turn` caps only the completion) up front, plus `image_reservation_tokens` (default `1600`) for each image the trigger carries, and settled to the real `usage` afterwards: uncached input plus completion, plus cache writes times `cache_weights.write` (default `1.0`) plus cache reads times `cache_weights.read` (default `0.0`), each weighted bucket rounded up, so a cached prompt prefix re-read every turn does not drain the pool. A responder reports `SupportUsage(promptTokens: <uncached input>, completionTokens, cacheReadTokens, cacheWriteTokens)`, where `promptTokens` excludes both cache buckets; the two cache counts are optional and default to `0`. A turn is charged once however often it is retried. A refused turn costs nothing and is handed off as `quota`. A turn, team or pool limit that is missing reads as `0` and refuses; the two token keys above fall back to their defaults.

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

<a name="knowledge-base"></a>
## Knowledge Base

Staff write articles in the panel; the AI desk, the public FAQ page and in-app suggestions read them. It is off until `support.articles.enabled` is true, and needs the `create_support_articles_table` migration.

- **Articles.** `SupportArticle` is global to the app: a `slug`, a `section`, a `sort`, a `visibility` (`public`, `ai_only` or `staff_only`), a `published_at` (unpublished while null; a future date goes live on time with no write), and `question` (plain text), `body` (Markdown, never HTML) and `locale_state` (`approved` or `draft`) keyed by locale. A locale is readable only while approved. The **Knowledge base** resource (`support_articles`, `src/Filament/Resources/SupportArticles`) edits them. `SupportArticleFeed` is the cached corpus every reader goes through, per audience and locale.
- **Audience.** `SupportAudience` decides what a reader may see: `page` (the public FAQ) only `public`, `customer` (the AI agent) `public` and `ai_only`, `staff` everything. `SupportToolContext::forConversation()` reads as the customer, `forStaff()` as staff and `none()` as the page; `audience()` and `opener()` expose what a tool may read, and a tool argument never chooses either.
- **Search.** `SearchesSupportArticles` ranks articles for one audience and locale; the bound `SupportArticleSearch` scores token overlap in PHP, and a host with an embedding index binds its own. A locale without approved text falls back to `magic-starter.defaults.locale`. The AI tools `search_faq` and `get_faq_article` read through it. The package registers none: a host adds them with `SupportToolRegistry::register()`, see [Support Tools](support-tools.md).
- **Suggestions.** `GET {prefix}/support/articles?search=&locale=` (signed in) returns up to five public matches as `id`, `slug`, `question`, an `excerpt` and a `url`, which is `support.articles.public_url` plus `#slug`, or null while that is unset. The route answers 404 while the knowledge base is off.
- **Images.** An editor upload is stored on `support.articles.disk`. `RemoveSupportArticleImages` deletes an article's images when it is deleted or leaves `public`, and `php artisan magic-starter:support-articles:prune-images` (scheduled daily) removes uploads no article references.

### Articles on an answer

A responder points the customer at articles by returning ids: `new SupportAnswer(..., articleRefs: [$id, ...])`. The model's pick is untrusted, so `RespondToSupportConversation` re-checks every id where it stores the answer: the article must be public, published and approved in the turn's locale, falling back to the default locale as the search does. Anything else (staff only, AI only, a draft, unpublished, unknown, or not shaped like a key of this deployment) is dropped without failing the turn. At most three remain, in the responder's order, and they are stored in the AI message's `meta.articles` as `id`, `slug` and `question`. Nothing is read while `support.articles.enabled` is off.

The customer API shows them as `articles` on an AI message only, each `slug`, `question` and `url` (the public page plus `#slug`, null while `support.articles.public_url` is unset); no other `meta` key is sent.

### Daily spend cap

The quota counts tokens, but only the host knows what they cost. A host that wants a ceiling for the whole desk binds `CapsSupportSpend`:

```php
$this->app->bind(CapsSupportSpend::class, MyDailyCap::class);
```

`exceeded(): bool` is read once per turn, right before the quota reserves anything. When it answers true the conversation is handed to staff as `quota` and no model is called, so nothing is left unsettled. With nothing bound there is no cap. A throw is a failed turn, not a handoff.

---

<a name="satisfaction-rating"></a>
## Satisfaction Rating

With `support-csat` on, the opener rates a resolved conversation with a thumbs up or down:

```
POST {prefix}/support/conversations/{conversation}/rating
{"rating": "down", "comment": "optional, up to 1000 characters"}
```

- The answer is 422 unless the conversation is `resolved`.
- The conversation resource gains `rating` (`value`, `comment`, `rated_at`, or `null` before any) and `can_rate`, true while the conversation is resolved and not yet rated for this resolution. Neither field is sent with the feature off, nor ever on the public thread link.
- Rating again within one resolution overwrites the first rating. Reopening and resolving again opens a new resolution that can be rated anew. The row keeps who held the conversation when it was rated (`csat_assignee_id`).
- A thumbs down tells staff once per resolution, under a row lock, with `SupportConversationNeedsStaff` and the reason `negative_rating`; changing the vote inside the same resolution never alerts a second time. The alert carries no comment.

---

<a name="in-app-feedback"></a>
## In-app Feedback

With `support-feedback` on, the app can ask a signed-in customer for a 0-10 score now and then.

- `GET {prefix}/support/feedback` answers `{"eligible": bool}`. An account is due when it is verified, not a guest, at least `support.feedback.min_account_days` old and has neither answered nor dismissed the card within `interval_days`.
- `POST {prefix}/support/feedback` takes `score` (0 to 10, or `null` for a dismissal) and an optional `comment` (2000 characters at most), and answers `{"conversation_id": string|null}`. A guest or an unverified email gets 403. Every answer, a dismissal included, is stored in `support_feedback` and paces the card; the write itself is not paced, so a stale card still lands.
- A score up to `low_score_max` continues the account's open feedback conversation, or opens one at high priority with the source `feedback`, a subject like `Feedback: 3/10` and, for an empty comment, the body `Score 3/10, no comment`. At most `low_score_daily` new ones open per user a day; past that the score is kept with no conversation unless one is still open to continue. The AI desk never answers a feedback conversation, and staff are told once with the reason `feedback` (a continued thread alerts as any customer message does).

| Key | Default | Meaning |
|-----|---------|---------|
| `support.feedback.min_account_days` | `14` | Age of the account before the card is due |
| `support.feedback.interval_days` | `90` | Days between two cards for one account |
| `support.feedback.low_score_max` | `6` | The highest score that files a conversation |
| `support.feedback.low_score_daily` | `1` | New low-score conversations per user a day |
| `support.feedback.response_hours` | `48` | The time staff have to answer a feedback conversation; the panel shows a respond-by |

---

<a name="attachments"></a>
## Attachments

With `support-attachments` on, a customer message carries images and PDFs. Send `multipart/form-data` to `POST support/conversations` or `POST support/conversations/{conversation}/messages` with one part per file, `attachments[0]` to `attachments[2]`. `body` is required only when there is no file, so a message of files alone is allowed; JSON still works when nothing is attached. A conversation opened with files and no text is titled with the first file's name.

### Limits and Validation

| Key | Default | Meaning |
|-----|---------|---------|
| `support.attachments.disk` | `local` | The private disk the files are stored on |
| `support.attachments.max_files` | `3` | Files per message |
| `support.attachments.max_kb` | `5120` | Kilobytes per file |
| `support.attachments.types` | `jpg`, `jpeg`, `png`, `webp`, `pdf` | Allowed types and extensions |
| `support.attachments.max_edge` | `8000` | Longest side of an image, in pixels |
| `support.attachments.max_pixels` | `40000000` | Pixels in an image |
| `support.attachments.stored_max_edge` | `2048` | The long edge an image is stored at |
| `support.attachments.daily_files` | `30` | Files one user may store a day |
| `support.attachments.daily_kb` | `102400` | Kilobytes one user may store a day |
| `support.attachments.link_ttl_seconds` | `60` | Life of a signed PDF link |

`SupportAttachmentRules` is the one source of the checks, and `StoreSupportAttachments` the one write path, for the customer and for staff. The type is sniffed from the bytes and the extension must be an allowed one, so an SVG, an HTML page or an Office file never passes under a `.png` name; the two must also name the same family, so a PDF named `.png`, or an image named `.pdf`, is refused. An image's size is read from its header with `getimagesize()` before any pixel is decoded, and refused past `max_edge` or `max_pixels`. Every file is validated and every image re-encoded before the message exists, so a refused file posts nothing and alerts nobody. Errors come back as 422 keyed `attachments.N` (or `attachments`, for the daily allowance).

An image has its long edge capped at `stored_max_edge` first, so the rest works on the small copy, then is turned upright from its EXIF orientation and re-encoded with GD in its own format, which drops every metadata block (GPS included). A PDF is kept byte for byte. A file lands on `support.attachments.disk` under a generated name; the sender's name is display text only. The daily allowance is a cache counter checked before anything is stored and raised once the message commits. It counts only the files the customer picked: the app's `auto_screenshot` and staff files are exempt.

### Serving

A message in the customer's thread (never in the public thread) carries `attachments`: `id`, `name`, `mime`, `size`, `width`, `height`, `kind` (`image` or `pdf`) and `url`, which is `support/conversations/{c}/attachments/{a}` and relative to the API base.

- `GET support/conversations/{c}/attachments/{a}` streams the file with the bearer token as the read credential. An image is sent inline and a PDF with `Content-Disposition: attachment`; both carry the stored `Content-Type`, `X-Content-Type-Options: nosniff` and `Cache-Control: private, no-store`. The opener's own conversation is the only way in: a team mate, a stranger, a file of another conversation or a file on a staff note answers 404.
- `POST support/conversations/{c}/attachments/{a}/link` answers `{"url": ...}`, a signed url that lives `link_ttl_seconds`, for a client that must hand a PDF to a system viewer. An image has no link (404). A signed url is never the customer's read credential.
- The url points at `GET support/attachments/{a}/download` (named `magic-starter.support.attachment-download`), behind Laravel's `signed` middleware and no bearer: the signature is the credential. It streams the PDF with `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff` and `Cache-Control: private, no-store`, so it never opens inline. A tampered or expired signature answers 403; an image, or a file on a staff note, 404.
- The link is the package's own signed route, not the disk's `temporaryUrl()`, so a `local` disk needs `'serve' => true` only if the host calls `temporaryUrl()` on it elsewhere.

### Cleanup

Deleting an account removes the files of its support conversations after the transaction commits. The rows go by database cascade, which fires no model event, so `DeleteUser` reads the paths first. The attachment and feedback models are skipped by the model audit listener, so their rows are never copied into the audit trail.

---

<a name="client-diagnostics"></a>
## Client Diagnostics

With `support-diagnostics` on, `POST support/conversations` also accepts:

- `client_context[route|app_version|build_number|platform|os_version|locale|timezone]`, strings up to 120 characters. Only those keys are kept, and `route` is stored without its query and fragment, which may carry a token. Staff read them beside the thread.
- `screenshot`, one PNG file named `screenshot.png`, stored with the origin `auto_screenshot`. It is a create-only part and is never listed in `SupportTurn::images`, so the model never sees it.

The screenshot is stored and read through the attachment pipeline, so turn `support-attachments` on with it: with attachments off the capture is stored but no endpoint serves it, and nobody can read it.

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

With the extra features on, the panel also carries:

- **Rating**: a column and a filter for the thumbs rating of a resolved conversation (`support-csat`).
- **Attachments**: files in the thread, images shown and PDFs offered as downloads, the screenshot the app captured captioned "Screenshot captured by the app" so it is not mistaken for a file the customer chose, and a staff upload on a reply that goes through the same validation and storage as the customer's, with the origin `staff`. The attach control is hidden when the conversation has no opener (`support-attachments`).
- **Feedback**: a feedback list of every score and dismissal, and a respond-by on a feedback conversation from `support.feedback.response_hours` (`support-feedback`).
- **Customer context**: a panel beside the thread with the client info the app sent, the opener's latest 20 audit rows and, when the host binds `DescribesSupportCustomer`, the host's own section. Push device rows are left out of the audit list: their heartbeat updates would fill it on their own.

`DescribesSupportCustomer` is optional and ships with no default implementation and no binding, like `AssistsSupportStaff`. `describe(SupportConversation): array` returns label to value pairs (a plan, usage, a region) for staff to read. It reads only what the opener's own account holds, returns nothing secret, and may throw: the panel then shows no host section and every other part works.

```php
$this->app->bind(DescribesSupportCustomer::class, MyCustomerDescription::class);
```

A reply to a pending conversation takes it from the AI desk. A reply reaches the customer once: an opener with an account by a database notification (and push with the OneSignal feature) plus a realtime nudge, a visitor by a mail carrying a newly minted thread link (which retires the link in every earlier mail). A note is stored and audited and goes nowhere. Resolving a conversation revokes its public link.

---

<a name="events"></a>
## Events

| Event | When | Carries |
|-------|------|---------|
| `SupportConversationNeedsStaff` | A customer opened a conversation, wrote again, the AI desk handed it over, a thumbs down came in or a low feedback score was filed | The conversation and a reason, `opened`, `customer_message`, `handoff`, `negative_rating` or `feedback` |
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

`RespondsToSupport` and `AssistsSupportStaff` are the two AI contracts and ship unbound; see [The AI Desk](#the-ai-desk). `DescribesSupportCustomer` ships unbound too; see [Answering from the Panel](#answering-from-the-panel).

The reply action does not authorize its `$staff` argument: the panel gate is the authorization. A replacement that is called from anywhere else has to check access itself.
