# Profile Management

Magic Starter provides a full suite of profile management endpoints covering user profile updates, password changes, account deletion, profile photos, email verification, session management, newsletter subscriptions, and a public settings API. All endpoints are JSON-only (no views or redirects) and respect the configured route prefix.

## Table of Contents

- <a name="toc-update-profile"></a>[Update Profile](#update-profile)
- <a name="toc-update-password"></a>[Update Password](#update-password)
- <a name="toc-delete-account"></a>[Delete Account](#delete-account)
- <a name="toc-profile-photo"></a>[Profile Photo](#profile-photo)
- <a name="toc-has-profile-photo-trait"></a>[HasProfilePhoto Trait](#has-profile-photo-trait)
- <a name="toc-email-verification"></a>[Email Verification](#email-verification)
- <a name="toc-settings-api"></a>[Settings API](#settings-api)
- <a name="toc-newsletter"></a>[Newsletter Subscription](#newsletter)
- <a name="toc-session-management"></a>[Session Management](#session-management)
- <a name="toc-extended-profile"></a>[Extended Profile](#extended-profile)
- <a name="toc-publishing-your-own-columns"></a>[Publishing Your Own Columns](#publishing-your-own-columns)

---

## <a name="update-profile"></a>Update Profile

Updates the authenticated user's profile information.

| Property | Value |
|----------|-------|
| **Method** | `PUT` |
| **URI** | `user/profile` |
| **Auth** | `auth:sanctum` |
| **Controller** | `ProfileController@update` |
| **Request** | `UpdateProfileRequest` |
| **Response** | `UserResource` |

### Fields

| Field | Type | Rules |
|-------|------|-------|
| `name` | string | Required (nullable for guests), min:2, max:255 |
| `email` | string | Nullable, valid email, max:255, unique per user table |
| `phone` | string | Nullable, max:20, E.164 format (requires `extended-profile` feature) |
| `timezone` | string | Nullable, valid IANA timezone (requires `extended-profile` or `timezones` feature) |
| `locale` | string | Nullable, must be in `magic-starter.supported_locales` (requires `extended-profile` feature) |

Guest users may additionally pass `password` and `password_confirmation` to upgrade their account in a single call. The password must be at least 8 characters with letters, numbers, and mixed case. When a guest provides an email/phone and a password, they are automatically converted to a full user (`is_guest` becomes `false`). The conversion dispatches `FlutterSdk\MagicStarter\Events\GuestConverted` once, after the transaction commits, from this endpoint or from a guest setting a first password; listen to it for anything a host does for a new registered account, such as a welcome message.

When the email changes and the `email-verification` feature is enabled, `email_verified_at` is reset to `null` and a new verification notification is sent.

### Request Example

```http
PUT /user/profile
Authorization: Bearer {token}
Content-Type: application/json

{
    "name": "Jane Doe",
    "email": "jane@example.com",
    "phone": "+15551234567",
    "timezone": "America/New_York",
    "locale": "en"
}
```

### Response Example

```json
{
    "data": {
        "id": 1,
        "name": "Jane Doe",
        "email": "jane@example.com",
        "phone": "+15551234567",
        "is_guest": false,
        "email_verified_at": "2025-01-15T10:30:00.000000Z",
        "locale": "en",
        "timezone": "America/New_York",
        "profile_photo_url": null,
        "two_factor_enabled": false,
        "created_at": "2025-01-10T08:00:00.000000Z",
        "updated_at": "2025-01-15T10:30:00.000000Z"
    }
}
```

---

## <a name="update-password"></a>Update Password

Updates the authenticated user's password.

| Property | Value |
|----------|-------|
| **Method** | `PUT` |
| **URI** | `user/password` |
| **Auth** | `auth:sanctum` |
| **Controller** | `ProfileController@updatePassword` |
| **Request** | `UpdatePasswordRequest` |

### Fields

| Field | Type | Rules |
|-------|------|-------|
| `current_password` | string | Required (optional for guest users without a password) |
| `password` | string | Required, min:8, letters + numbers + mixed case, confirmed |
| `password_confirmation` | string | Required |

The `current_password` is verified against the stored hash via `Hash::check()`. Guest users who have no password set yet can skip the `current_password` field. An account with no password at all (a social-only one) answers 422 `password_not_set`; it sets its first password through [Set a Password](social-login.md#set-a-password).

### Request Example

```http
PUT /user/password
Authorization: Bearer {token}
Content-Type: application/json

{
    "current_password": "OldP@ssw0rd",
    "password": "NewP@ssw0rd",
    "password_confirmation": "NewP@ssw0rd"
}
```

### Response Example

```json
{
    "data": null,
    "message": "Password updated successfully."
}
```

---

## <a name="delete-account"></a>Delete Account

Schedules the authenticated user's account for deletion. Nothing is deleted in the request: every token is revoked now and the account is purged after the grace period, and signing in again before then cancels it. With `immediately: true` the purge is queued at once instead. See [Account Deletion](account-deletion.md) for the pipeline.

| Property | Value |
|----------|-------|
| **Method** | `POST` or `DELETE` |
| **URI** | `user/` |
| **Auth** | `auth:sanctum` |
| **Controller** | `ProfileController@destroy` |
| **Request** | `DeleteAccountRequest` |
| **Response** | `202 Accepted` |

### Fields

| Field | Type | Rules |
|-------|------|-------|
| `password` | string | Required for a user with a password |
| `code` | string | A current TOTP code, for a password-less user with confirmed 2FA |
| `confirmation_token` | string | A step-up token from the social confirm flow, for a password-less user |
| `immediately` | boolean | Optional. `true` queues the purge now instead of after the grace period |

A user with a password confirms with it. A guest without a password needs no proof. Any other password-less user sends `code` or `confirmation_token`, or the request answers 422 `step_up_required`.

### Request Example

```http
DELETE /user/
Authorization: Bearer {token}
Content-Type: application/json

{
    "password": "CurrentP@ssw0rd"
}
```

### Response

`202 Accepted` with `data.deletion_scheduled_at` and a `message`. With `immediately: true` the body also carries `data.immediate: true` and the `deletion_immediate` message; without it the body is exactly the scheduled one. The refusals and the step-up are the same either way, and nothing is queued when one of them answers. An owned team that another member belongs to, or that holds a live subscription, answers 422 with `code` `owns_shared_teams` or `team_has_active_subscription` and the `team_ids` to resolve first; with `account_deletion.hand_on_shared_teams` on, a shared team is no longer a reason to refuse, and the purge [hands it on](account-deletion.md#handing-on-shared-teams). A live subscription billing the user directly answers 422 `subscription_active` with an empty `team_ids`, unless `account_deletion.billing` is `release`: then the request is accepted, the Stripe subscription is cancelled with no proration, and `data.billing` lists what was `cancelled` and which store subscriptions (`store`) the user still has to cancel in the store. See [Releasing Billing](account-deletion.md#releasing-billing).

---

## <a name="profile-photo"></a>Profile Photo

Available when the `profile-photos` feature is enabled in `config/magic-starter.php`.

### Upload Profile Photo

| Property | Value |
|----------|-------|
| **Method** | `POST` |
| **URI** | `user/profile-photo` |
| **Auth** | `auth:sanctum` |
| **Controller** | `ProfilePhotoController@update` |
| **Request** | `UpdateProfilePhotoRequest` |
| **Response** | `UserResource` |

| Field | Type | Rules |
|-------|------|-------|
| `photo` | file | Required, image, max:1024 KB (1 MB) |

The photo is stored on the disk configured by `magic-starter.profile_photo_disk` (falls back to `filesystems.default`, typically `public`) with no visibility or ACL call, so it is as public as that disk's own `visibility` setting: set `'visibility' => 'public'` on an S3 disk that serves its objects by ACL, or serve the bucket by policy. The storage path is controlled by `magic-starter.profile_photo_path` (default: `profile-photos`). If a previous photo exists, it is deleted from the disk before storing the new one.

#### Request Example

```http
POST /user/profile-photo
Authorization: Bearer {token}
Content-Type: multipart/form-data

photo: (binary image file)
```

#### Response Example

```json
{
    "data": {
        "id": 1,
        "name": "Jane Doe",
        "profile_photo_url": "https://your-app.com/storage/profile-photos/abc123.jpg",
        "..."
    }
}
```

### Delete Profile Photo

| Property | Value |
|----------|-------|
| **Method** | `DELETE` |
| **URI** | `user/profile-photo` |
| **Auth** | `auth:sanctum` |
| **Controller** | `ProfilePhotoController@delete` |
| **Response** | `UserResource` |

Removes the photo file from disk and sets `profile_photo_path` to `null`. The `profile_photo_url` in the response is `null` again (see [HasProfilePhoto Trait](#has-profile-photo-trait)).

#### Request Example

```http
DELETE /user/profile-photo
Authorization: Bearer {token}
```

---

## <a name="has-profile-photo-trait"></a>HasProfilePhoto Trait

The `HasProfilePhoto` trait (`FlutterSdk\MagicStarter\Traits\HasProfilePhoto`) provides the `profile_photo_url` accessor used by `UserResource`.

### profilePhotoUrl Accessor

`getProfilePhotoUrlAttribute(): ?string`

If `profile_photo_path` is set, returns the public URL from the configured filesystem disk (`magic-starter.profile_photo_disk`). If the disk driver supports the `url()` method, it generates the full URL; otherwise it returns the raw path.

When no photo is stored it returns `null`, and the client draws its own initials. A generated image would cost a third-party round trip per avatar, send the person's name to that third party, fail offline, arrive in colours the client's design system did not choose, and be impossible to tell apart from a real upload.

### Configuration

| Config Key | Default | Description |
|------------|---------|-------------|
| `magic-starter.profile_photo_disk` | `filesystems.default` | Filesystem disk for photo storage |
| `magic-starter.profile_photo_path` | `profile-photos` | Storage directory within the disk |

---

## <a name="email-verification"></a>Email Verification

Available when the `email-verification` feature is enabled.

### Verify Email Address

| Property | Value |
|----------|-------|
| **Method** | `GET` |
| **URI** | `email/verify/{id}/{hash}` |
| **Middleware** | `signed:relative` (no auth required) |
| **Route Name** | `verification.verify` |
| **Controller** | `EmailVerificationController@verify` |

This is a public endpoint protected by a signed URL (not `auth:sanctum`). The `{hash}` parameter is a SHA-1 of the user's email address. The link is validated to ensure it matches the user's current email, preventing reuse after an email change.

The mailed link depends on `magic-starter.frontend_url`. Without it, the mail carries this signed route and the API answers it with the JSON below. With it, the mail opens the client's verify page, `{frontend}/auth/verify-email?id=&hash=&expires=&signature=`, and that page replays the four values as `GET email/verify/{id}/{hash}?expires=&signature=` against the API. The signature is relative: it covers the path and expiry, not the host, because the mail goes out from whichever host the request came in on (a staff resend comes from the panel host) and the client calls the API host. `magic_starter` 0.0.53 and later ships that page.

| Status | Response |
|--------|----------|
| Success | `200` `{"message": "Email verified successfully."}` |
| Already verified | `200` `{"message": "Email already verified."}` |
| Invalid hash | `403` `{"message": "Invalid verification link."}` |
| User not found | `404` |
| Invalid signature | `403` (Laravel signed middleware) |

### Send Verification Notification

| Property | Value |
|----------|-------|
| **Method** | `POST` |
| **URI** | `email/verification-notification` |
| **Auth** | `auth:sanctum` |
| **Middleware** | `throttle:magic-starter-email-verification` |
| **Route Name** | `verification.send` |
| **Controller** | `EmailVerificationController@sendVerificationNotification` |

Sends a signed verification link to the authenticated user's email address.

| Status | Response |
|--------|----------|
| Sent | `202` `{"message": "Verification link sent."}` |
| Already verified | `200` `{"message": "Email already verified."}` |
| No email address | `400` `{"message": "No email address to verify."}` |

#### Request Example

```http
POST /email/verification-notification
Authorization: Bearer {token}
```

---

## <a name="settings-api"></a>Settings API

A public, unauthenticated endpoint that exposes feature flags and locale/timezone defaults for client bootstrapping.

| Property | Value |
|----------|-------|
| **Method** | `GET` |
| **URI** | `settings` |
| **Middleware** | `throttle:magic-starter-settings` |
| **Controller** | `SettingsController@index` |

This endpoint intentionally does not require authentication. It only exposes allowlisted values and never leaks internal configuration (URLs, token TTLs, model classes, file paths).

### Response Example

```json
{
    "supported_locales": ["en", "tr", "de"],
    "features": {
        "registration": true,
        "teams": true,
        "social_login": false,
        "email_verification": true,
        "guest_auth": false,
        "phone_otp": false,
        "newsletter": true,
        "extended_profile": true,
        "two_factor_authentication": true,
        "sessions": true,
        "profile_photos": true,
        "notifications": true,
        "timezones": true,
        "releases": false
    },
    "auth": {
        "email": true,
        "phone": false
    },
    "defaults": {
        "locale": "en",
        "timezone": "UTC"
    }
}
```

---

## <a name="newsletter"></a>Newsletter Subscription

Available when the `newsletter-subscription` feature is enabled.

### Show Subscription Status

| Property | Value |
|----------|-------|
| **Method** | `GET` |
| **URI** | `user/newsletter` |
| **Auth** | `auth:sanctum` |
| **Controller** | `NewsletterController@show` |

Returns the status of the user's own subscription. The subscription belongs to the user, not to an address, so it keeps working after the user changes their email.

#### Response Example (subscribed)

```json
{
    "subscribed": true,
    "source": "profile",
    "subscribed_at": "2025-03-10T14:00:00.000000Z"
}
```

#### Response Example (not subscribed)

```json
{
    "subscribed": false
}
```

### Update Subscription

| Property | Value |
|----------|-------|
| **Method** | `PUT` |
| **URI** | `user/newsletter` |
| **Auth** | `auth:sanctum` |
| **Controller** | `NewsletterController@update` |

| Field | Type | Rules |
|-------|------|-------|
| `subscribe` | boolean | Required |

Subscribing records the user's [consent](#newsletter-consent) with `source` set to `profile` and sends the [confirmation mail](#newsletter-confirmation). Unsubscribing withdraws the consent without deleting the record. Requires the user to have an email address; returns `400` otherwise. The response shape is the same as `GET user/newsletter`, and `subscribed_at` is the time of the latest consent.

#### Request Example

```http
PUT /user/newsletter
Authorization: Bearer {token}
Content-Type: application/json

{
    "subscribe": true
}
```

#### Response Example

```json
{
    "subscribed": true,
    "source": "profile",
    "subscribed_at": "2025-03-10T14:00:00.000000Z"
}
```

#### Error: No Email

```json
// 400
{
    "message": "Email address required for newsletter subscription."
}
```

### <a name="newsletter-consent"></a>Consent Record

One `newsletter_subscribers` row per user (`user_id` is unique and the row goes with the user). Besides `email` (a copy kept in step with the user's), `is_active` and `source`, the row carries the consent behind it:

| Column | Meaning |
|--------|---------|
| `consented_at` | When the user last gave consent |
| `consent_version` | `magic-starter.newsletter.consent_version`, the version of your consent wording at that moment |
| `consent_source` | Where the consent was given: `profile` or `registration` (the `subscribe_newsletter` flag of `POST auth/register`) |
| `unsubscribed_at` | When the consent was withdrawn, `null` while it stands |
| `unsubscribe_token` | The secret of the [unsubscribe link](#newsletter-unsubscribe), minted once and never rotated; hidden from serialization |

Raise `consent_version` (`MAGIC_STARTER_NEWSLETTER_CONSENT_VERSION`) whenever the wording users agreed to changes, so each row says which text it rests on. Rows written before this record existed read `consent_version` `pre-e5a`, with `consented_at` set to their creation time.

Subscribing again after a withdrawal reactivates the same row: the token stays, `unsubscribed_at` is cleared, and `consented_at`, `consent_version` and `consent_source` are rewritten with the new consent. Subscribing while already subscribed changes nothing but the consent record, and sends no second mail.

### <a name="newsletter-confirmation"></a>Confirmation Mail

Each time a user goes from not subscribed to subscribed, `NewsletterSubscribedNotification` is queued to their address, in their locale. It states the date of the consent and carries the unsubscribe link. It is a notice, not a double opt-in: the subscription is already active when it is sent. The queue worker has to be running, and a transport failure fails the queued job, not the request that recorded the consent. The mail copy lives in `lang/{en,tr}/newsletter.php` under `magic-starter::newsletter.mail`.

### <a name="newsletter-unsubscribe"></a>One-Click Unsubscribe

Every newsletter mail can carry the same link, built from the row's token:

| Method | URI | What it does |
|--------|-----|--------------|
| `GET` | `/newsletter/unsubscribe/{token}` | Shows a confirmation page, or says it is already done. Changes nothing |
| `POST` | `/newsletter/unsubscribe/{token}` | Withdraws the consent and answers `200` with the result page |

The two routes are `magic-starter.newsletter.unsubscribe` and `magic-starter.newsletter.unsubscribe.store`. They are registered with the feature on, outside `magic-starter.route_prefix` and outside every middleware group, because the URL is baked into mail that was already sent. The token is the only credential: there is no session, cookie or CSRF token, and an unknown token answers `404` with a page that names nothing.

`GET` is deliberately read-only, since mail scanners and link previews follow every link. The `POST` is the one a mail provider sends for RFC 8058 one-click unsubscribe, so a newsletter mail should carry both headers:

```
List-Unsubscribe: <https://api.example.com/newsletter/unsubscribe/{token}>
List-Unsubscribe-Post: List-Unsubscribe=One-Click
```

The `POST` accepts any body (`List-Unsubscribe=One-Click` included), is idempotent, and is throttled per token by `magic-starter-newsletter-unsubscribe` (10 per minute); the page is throttled per IP by `magic-starter-newsletter-unsubscribe-page` (60 per minute). The page renders in the owner's locale and sends `Referrer-Policy: no-referrer`.

### <a name="newsletter-audience"></a>Marketing Audience

`NewsletterSubscriber::marketable()` is the only audience a sender may use. It selects rows that are active, have a `consented_at`, have no `unsubscribed_at` and belong to a user:

```php
use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;

NewsletterSubscriber::query()->marketable()->each(function (NewsletterSubscriber $subscriber): void {
    // send, with $subscriber->unsubscribe_token in the footer link
});
```

The package sends no campaigns itself.

### <a name="newsletter-email-changes"></a>Email Changes and Deletion

The subscription follows its user. When a user's email changes through the profile action, any `$user->update()` or the admin panel's user form, the row's address is rewritten to match. Deleting the account deletes the row with its audit rows, see [Account Deletion](account-deletion.md).

A row written by address alone, as the previous release did, is claimed by the user whose address matches the first time that user reads or changes their subscription, and keeps working while old and new code run side by side during a deploy.

---

## <a name="session-management"></a>Session Management

Available when the `sessions` feature is enabled. Sessions are Sanctum personal access tokens.

### List Sessions

| Property | Value |
|----------|-------|
| **Method** | `GET` |
| **URI** | `sessions` |
| **Auth** | `auth:sanctum` |
| **Controller** | `SessionController@index` |
| **Response** | `SessionResource` collection |

Returns all active tokens for the authenticated user.

`agent` is parsed from the stored user agent by `SessionAgent`. **`browser`,
`platform` and `app` are EMPTY strings when it cannot name them**, never a word:
this is a package, and a literal here would be English frozen into every
consumer's UI. They are independent, so a partially readable agent reports the
half it read. Show your own translated label when all of them are empty.

`app` names a NATIVE client and is empty for a browser. `magic`'s network
provider sends `<App Name> (Flutter; <platform>)` by default, and this reads it:
`platform` comes back `iOS`, `browser` stays empty because a native client is not
a browser, and `is_mobile` is decided by the platform token rather than by a
substring sweep. Render `platform` with whichever of `browser` and `app` is
present; they are mutually exclusive by construction.

Before this, a native agent matched none of the browser patterns and none of the
platform patterns, so both came back empty, `is_mobile` came back false, and
`is_desktop` therefore came back TRUE: a phone's own session rendered as a
browser session on an unknown desktop.

#### Response Example

```json
{
    "data": [
        {
            "id": 42,
            "ip_address": "192.168.1.10",
            "user_agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)...",
            "agent": {
                "browser": "Chrome",
                "platform": "Mac",
                "app": "",
                "is_desktop": true,
                "is_mobile": false
            },
            "location": {
                "city": "Istanbul",
                "country": "TR"
            },
            "is_current_device": true,
            "last_used_at": "2025-03-25T09:00:00.000000Z",
            "created_at": "2025-03-20T08:00:00.000000Z"
        },
        {
            "id": 38,
            "ip_address": "10.0.0.5",
            "user_agent": "Acme (Flutter; iOS)",
            "agent": {
                "browser": "",
                "platform": "iOS",
                "app": "Acme",
                "is_desktop": false,
                "is_mobile": true
            },
            "location": null,
            "is_current_device": false,
            "last_used_at": "2025-03-18T15:00:00.000000Z",
            "created_at": "2025-03-15T12:00:00.000000Z"
        }
    ]
}
```

### Revoke a Specific Session

| Property | Value |
|----------|-------|
| **Method** | `DELETE` |
| **URI** | `sessions/{token}` |
| **Auth** | `auth:sanctum` |
| **Controller** | `SessionController@destroy` |
| **Request** | `ConfirmPasswordRequest` |

Requires password confirmation. The `{token}` parameter is the token ID. Returns `404` if the token does not belong to the authenticated user.

#### Request Example

```http
DELETE /sessions/38
Authorization: Bearer {token}
Content-Type: application/json

{
    "password": "CurrentP@ssw0rd"
}
```

#### Response Example

```json
{
    "data": null,
    "message": "Session revoked successfully."
}
```

### Revoke All Other Sessions

| Property | Value |
|----------|-------|
| **Method** | `DELETE` |
| **URI** | `sessions/other` |
| **Auth** | `auth:sanctum` |
| **Controller** | `SessionController@destroyOther` |
| **Request** | `DestroyOtherSessionsRequest` (extends `ConfirmPasswordRequest`) |

Deletes all tokens except the one making the request. Requires password confirmation. Guest users without a password may skip the password field.

#### Request Example

```http
DELETE /sessions/other
Authorization: Bearer {token}
Content-Type: application/json

{
    "password": "CurrentP@ssw0rd"
}
```

#### Response Example

```json
{
    "data": null,
    "message": "Other sessions revoked successfully."
}
```

---

## <a name="extended-profile"></a>Extended Profile

When the `extended-profile` feature is enabled in `config/magic-starter.php`, the profile update endpoint (`PUT user/profile`) accepts additional fields:

| Field | Type | Description |
|-------|------|-------------|
| `phone` | string | Phone number in E.164 format (e.g., `+15551234567`), max 20 chars |
| `locale` | string | Must be one of the values in `magic-starter.supported_locales` |
| `timezone` | string | Valid IANA timezone identifier (e.g., `America/New_York`) |

The `timezone` field is also available when only the `timezones` feature is enabled (without `extended-profile`), as determined by `Features::hasTimezoneOrExtendedProfileFeatures()`.

These fields are validated and stored by the `UpdateUserProfile` action. The `UserResource` always includes `locale`, `timezone`, and `phone` in its response regardless of the feature flag -- the feature flag only controls whether the update endpoint accepts these fields.

### Configuration

Enable the feature in `config/magic-starter.php`:

```php
'features' => [
    Features::extendedProfile(),
    // ...
],

'supported_locales' => ['en', 'tr', 'de'],
```

## <a name="publishing-your-own-columns"></a>Publishing Your Own Columns

`UserResource` is not resolved through the container, so an application that adds a column to `users` cannot swap the resource to expose it. Register the extra fields instead, from `AppServiceProvider::boot()`:

```php
use FlutterSdk\MagicStarter\MagicStarter;

MagicStarter::serializeUserUsing(fn ($user, $request) => [
    'subscription_tier' => $user->subscription_tier,
]);
```

The callback receives the user being serialised and the current request, and its return value is **merged over** the package's fields. Merging rather than replacing is what keeps your payload current: a field this package adds in a later release reaches you with no change on your side.

A key of yours that collides with one of the package's wins, so a value you own is yours to correct. The callback must return an array; anything else is refused by name rather than fataling inside the resource.

`MagicStarter::reset()` clears it, which matters in a test suite.
