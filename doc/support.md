# Support Desk

- [Introduction](#introduction)
- [Enabling](#enabling)
- [Endpoints](#endpoints)
- [Configuration](#configuration)
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

Run `php artisan magic-starter:install --features=support` for the two migrations, then `php artisan migrate`.

---

<a name="endpoints"></a>
## Endpoints

Signed in (`auth:sanctum`, throttle `magic-starter-support`):

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `{prefix}/support/conversations` | The caller's conversations, latest activity first (`per_page`, at most 100) |
| POST | `{prefix}/support/conversations` | Open one: `subject`, `body`, optional `source` (`in_app` or `contact_sales`) |
| GET | `{prefix}/support/conversations/{conversation}` | The thread, without staff notes |
| POST | `{prefix}/support/conversations/{conversation}/messages` | Add a message |
| POST | `{prefix}/support/conversations/{conversation}/seen` | Mark the thread read |

Public, no account (throttle `magic-starter-support-public`). The thread token is the only credential and an unknown, replaced, revoked or expired one answers 404:

| Method | Route | Purpose |
|--------|-------|---------|
| POST | `{prefix}/support/public/conversations` | Open one: `email`, `name`, `subject`, `body`, optional `source` (`public_form` or `contact_sales`) |
| GET | `{prefix}/support/public/thread/{token}` | The thread |
| POST | `{prefix}/support/public/thread/{token}` | Add a message |

The public limits:
- Opening a conversation allows 3 an hour per IP and 3 a day per submitted email.
- Thread reads allow 60 a minute per IP.
- Thread writes allow 10 an hour per token.

The per-email cap bounds how much one address can put in front of staff, but it has a cost. The throttle counts a request before validation runs, so anyone who knows an address can use up its 3 for the day, and the owner of that address cannot open a public conversation until the next day. The owner can still sign in and write from the app, where the per-user limiter applies instead. An app that would rather not carry that cost can rebind `magic-starter-support-public` in its own service provider.

A conversation belongs to its opener alone. When a visitor verifies an address that matches a public conversation, `LinkSupportConversationsOnVerified` hands it to their account.

---

<a name="configuration"></a>
## Configuration

| Key | Default | Meaning |
|-----|---------|---------|
| `support.message_max_length` | `2000` | Characters allowed in one message, a staff reply included |
| `support.public.link_ttl_days` | `14` | Days a mailed thread link lives (`MAGIC_STARTER_SUPPORT_LINK_TTL_DAYS`) |
| `support.public.thread_url` | `null` | The frontend page that opens a thread; `{token}` is replaced (`MAGIC_STARTER_SUPPORT_THREAD_URL`) |
| `support.public.extra_rules` | `[]` | Field of the public form mapped to rule class names, for a captcha or a blocklist |

Replying to a visitor refuses until `support.public.thread_url` is set, because the reply mail carries the link.

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
- **Actions**: Reply, Add note, Assign and Change status or priority. Each calls the bound contract with the signed-in staff user, and a refusal reads as a notification.

A reply reaches the customer once: an opener with an account by a database notification (and push with the OneSignal feature) plus a realtime nudge, a visitor by a mail carrying a newly minted thread link (which retires the link in every earlier mail). A note is stored and audited and goes nowhere. Resolving a conversation revokes its public link.

---

<a name="events"></a>
## Events

| Event | When | Carries |
|-------|------|---------|
| `SupportConversationNeedsStaff` | A customer opened a conversation or wrote again | The conversation and a reason, `opened` or `customer_message` |
| `SupportConversationUpdated` | Staff replied or changed a conversation with an opener account | The conversation id only |
| `AdminActionPerformed` | A panel action succeeded | `support.replied`, `support.note_added`, `support.assigned` or `support.changed` |

The package listens for `SupportConversationNeedsStaff` and alerts every staff recipient by database, mail and, with the OneSignal feature, push. The alert and the broadcast carry ids, never the text of a message; the push leaves the subject out as well.

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

The reply action does not authorize its `$staff` argument: the panel gate is the authorization. A replacement that is called from anywhere else has to check access itself.
