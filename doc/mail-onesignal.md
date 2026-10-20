# Mail on OneSignal

- [Introduction](#introduction)
- [The Mailers](#the-mailers)
- [Routing a Mail Class](#routing-a-mail-class)
- [The Failover](#the-failover)
- [Send Records and the Doctor](#send-records-and-the-doctor)
- [Limits](#limits)

---

<a name="introduction"></a>
## Introduction

The package can send your application's mail through OneSignal email. Laravel still renders every mail with your views and theme; the `onesignal` transport only hands the finished subject and HTML to OneSignal.

Nothing moves until you ask for it. The transport is selected by a mailer, and the router sends a mail class through OneSignal only when its switch is on and the class is listed. A class you did not list keeps going through your usual mailer, so you can move one mail at a time and move it back the same way.

Before the first send, OneSignal needs its email channel set up: a sender on a domain you own, the SPF, DKIM and DMARC records, and a verified account. Until then OneSignal accepts test sends to your own addresses only.

---

<a name="the-mailers"></a>
## The Mailers

The package registers two transports. Add the mailers that use them to `config/mail.php`:

```php
'mailers' => [
    // ...

    // OneSignal email on its own. Use it inside a failover, never as the default.
    'onesignal' => [
        'transport' => 'onesignal',
    ],

    // OneSignal first, then your SMTP mailer for anything OneSignal does not send.
    'onesignal-failover' => [
        'transport' => 'failover',
        'mailers' => ['onesignal', 'smtp'],
        'retry_after' => 60,
    ],

    // The router: each mail class goes to its routed mailer or to `default`.
    'routed' => [
        'transport' => 'magic-starter-router',
        'default' => 'smtp',
    ],
],
```

Point `MAIL_MAILER` at `routed` when you want routing. With the switch off, `routed` hands every mail to its `default` mailer, so the switch is the only thing that changes delivery.

The `onesignal` transport reads the same credentials as push: `magic-starter.onesignal.app_id` and the REST API key (`ONESIGNAL_APP_ID`, `ONESIGNAL_REST_API_KEY`).

---

<a name="routing-a-mail-class"></a>
## Routing a Mail Class

The router reads `magic-starter.mail.onesignal`:

| Env key | Default | Meaning |
|---------|---------|---------|
| `MAGIC_STARTER_MAIL_ONESIGNAL_ENABLED` | `false` | The switch. Off, nothing is routed. |
| `MAGIC_STARTER_MAIL_ONESIGNAL_CLASSES` | empty | Comma separated class basenames (`ResetPassword`, `TeamInvitationNotification`); `*` routes every class. |
| `MAGIC_STARTER_MAIL_ONESIGNAL_MAILER` | `onesignal-failover` | The mailer a listed class leaves through. |
| `MAGIC_STARTER_MAIL_ONESIGNAL_RETENTION_DAYS` | `30` | How long a send record is kept. |

```dotenv
MAIL_MAILER=routed
MAGIC_STARTER_MAIL_ONESIGNAL_ENABLED=true
MAGIC_STARTER_MAIL_ONESIGNAL_CLASSES=TeamInvitationNotification
```

The class is the notification or the mailable that sent the mail, matched by basename. A listener stamps it on mail sent through a router mailer only, and the router removes that header before the mail leaves, so a mail on any other mailer keeps exactly the headers it had.

> [!NOTE]
> The application config is merged shallowly. If you published `config/magic-starter.php`, add the `onesignal` array to your own `mail` block, or the package keys never load.

---

<a name="the-failover"></a>
## The Failover

Every OneSignal failure throws a `TransportException`, which is the one exception type Laravel's failover mailer acts on. So the next member (your SMTP mailer) delivers the mail when OneSignal:

- is not provisioned (no app id or REST key);
- gets a mail it has no field for (cc, bcc, attachments, or no HTML body);
- refuses the request or cannot be reached;
- answers without a message id, which is how OneSignal says it sent nothing.

A failed member is skipped for `retry_after` seconds in that worker process, so a burst after one OneSignal error goes to SMTP for that window.

No recipient, subject or body appears in an exception or a log line. The failover logs each failure at ERROR, so the messages carry error keys and counts only.

---

<a name="send-records-and-the-doctor"></a>
## Send Records and the Doctor

Each mail OneSignal accepts writes a row to `magic_starter_mail_sends`: the OneSignal message id, the mail class, a sha256 of the first recipient and the recipient counts. It never stores an address, a subject or a body. A failed insert is reported and does not fail the mail, because the mail has already left.

`magic-starter:mail:prune-sends` runs daily and deletes rows past the retention. OneSignal keeps message data for 30 days, so a longer retention only keeps ids it can no longer answer for.

`magic-starter:mail:doctor` checks the setup and sends nothing:

```bash
php artisan magic-starter:mail:doctor
php artisan magic-starter:mail:doctor --deliveries=20
```

It prints whether the credentials are set, whether OneSignal reports the email channel enabled, the router config and the default mailer. With `--deliveries` it reads OneSignal's counters (successful, failed, errored, received) for the last sends, by class and message id. It exits 1 when the router is on while the email channel is off, since every routed mail would then land on the failover.

---

<a name="limits"></a>
## Limits

- Every send uses `include_unsubscribed`, because your own database is the opt-out of record. OneSignal therefore adds no `List-Unsubscribe` header, and it cannot carry the one your mailable sets: keep the unsubscribe link in the body.
- OneSignal creates an email subscription for every address it sends to. Name OneSignal in your privacy notice before you route mail to people outside your own users.
- Marketing mail is out of scope: the transport sends to explicit addresses only and never targets a segment.
