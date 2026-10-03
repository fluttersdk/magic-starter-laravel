# Social Login

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Provider Setup](#provider-setup)
- [Configuration](#configuration)
- [Browser Flow](#browser-flow)
- [Native Token Endpoint](#native-token-endpoint)
- [Accounts and Identity](#accounts-and-identity)
- [Connect a Provider](#connect-a-provider)
- [Disconnect a Provider](#disconnect-a-provider)
- [Set a Password](#set-a-password)
- [Step-Up Confirmation](#step-up-confirmation)
- [Apple Notifications](#apple-notifications)
- [Error Codes](#error-codes)

---

<a name="introduction"></a>
## Introduction

Social login signs users in with Google, Apple, GitHub and Microsoft. There are two ways in. The **browser flow** is hosted by the backend: the app opens the system browser, the provider sends the browser to a fixed callback, and the app trades a one-time code for a session. The **native token endpoint** takes the ID token that the Google and Apple SDKs hand a mobile app.

Every flow ends the way a password sign-in does: a Sanctum token response, or a [two-factor challenge](authentication.md#two-factor-challenge) when the account confirmed 2FA. Throughout this document `{prefix}` is `config('magic-starter.route_prefix')`.

**Feature:** `Features::socialLogin()`. No route in this document is registered while the feature is off.

> [!WARNING]
> A provider access token is never accepted. Asking a provider who owns an access token says nothing about which client it was issued to, so any app the user had signed in to could replay it here. The package accepts an ID token checked against your configured audiences, or an authorization code redeemed with your own client secret.

---

<a name="requirements"></a>
## Requirements

The user model needs two traits:

```php
use FlutterSdk\MagicStarter\Traits\HasSocialAccounts;
use FlutterSdk\MagicStarter\Traits\NormalizesEmail;

class User extends Authenticatable
{
    use HasSocialAccounts;
    use NormalizesEmail;
}
```

`HasSocialAccounts` adds the `socialAccounts()` relation and `hasPassword()`. `NormalizesEmail` lower-cases an email on assignment, so `Bob@x.io` and `bob@x.io` cannot become two accounts. Add a `lower(email)` unique index as well, so the database refuses what the application missed.

Install the feature with `magic-starter:install --features=social-login`. It publishes two migrations: `make_password_nullable_on_users_table.php` (a social-only account has no password) and `create_social_accounts_table.php`. The deletion columns ship with the core migrations, see [Account Deletion](account-deletion.md).

---

<a name="provider-setup"></a>
## Provider Setup

Register the fixed callback URL in every provider console. It does not use the route prefix, so it never moves with a deploy:

```
https://api.example.com/magic-starter/social/{provider}/callback
```

`{provider}` is `google`, `github`, `microsoft` or `apple`. The callback accepts `GET` and, for Apple's `form_post`, `POST`.

### Google, GitHub and Microsoft

Add the keys to `config/services.php`. `redirect` is the absolute callback URL from above:

```php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => 'https://api.example.com/magic-starter/social/google/callback',
],

'github' => [
    'client_id' => env('GITHUB_CLIENT_ID'),
    'client_secret' => env('GITHUB_CLIENT_SECRET'),
    'redirect' => 'https://api.example.com/magic-starter/social/github/callback',
],

'microsoft' => [
    'client_id' => env('MICROSOFT_CLIENT_ID'),
    'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
    'redirect' => 'https://api.example.com/magic-starter/social/microsoft/callback',
    'tenant' => env('MICROSOFT_TENANT', 'common'),
],
```

- **Google:** create a web OAuth client and register the callback as an authorized redirect URI. For native sign-in, put that same web client id in `MAGIC_STARTER_SOCIAL_GOOGLE_AUDIENCES`: an ID token is accepted only when its audience is on that list.
- **GitHub:** create an OAuth app and set its authorization callback URL.
- **Microsoft:** register the app in Entra with the **Web** platform and add the callback as a redirect URI. The package wires the Microsoft Socialite driver itself while the feature is on, so no `SocialiteWasCalled` listener is needed.

### Apple

Apple is configured through `magic-starter.social.apple.*` rather than `services.apple`, because the package signs Apple's client secret itself:

| Config key | Env | Value |
|------------|-----|-------|
| `team_id` | `MAGIC_STARTER_APPLE_TEAM_ID` | Your Apple Developer Team ID. |
| `key_id` | `MAGIC_STARTER_APPLE_KEY_ID` | The ID of the Sign in with Apple key. |
| `private_key` | `MAGIC_STARTER_APPLE_PRIVATE_KEY` | The `.p8` key, as a file path or the PEM text. |
| `bundle_id` | `MAGIC_STARTER_APPLE_BUNDLE_ID` | The app's bundle ID; the native client. |
| `services_id` | `MAGIC_STARTER_APPLE_SERVICES_ID` | The Services ID; the browser flow runs as this client. |

Register the callback URL as a return URL of the Services ID. List the client ids whose ID tokens you accept in `MAGIC_STARTER_SOCIAL_APPLE_AUDIENCES`. Without `services_id`, the browser flow answers `platform_not_configured` for Apple.

---

<a name="configuration"></a>
## Configuration

Everything else lives under `social` in `config/magic-starter.php`:

| Key | Env | Default | Purpose |
|-----|-----|---------|---------|
| `providers` | `MAGIC_STARTER_SOCIAL_PROVIDERS` | `google,apple,github,microsoft` | Allowlist. Others answer `provider_not_supported`. |
| `redirects.native` | `MAGIC_STARTER_SOCIAL_NATIVE_REDIRECT` | `null` | Where the browser lands for `platform=native`, usually the app's custom scheme or universal link. |
| `redirects.web` | `MAGIC_STARTER_SOCIAL_WEB_REDIRECT` | `null` | Where the browser lands for `platform=web`. |
| `audiences.google` | `MAGIC_STARTER_SOCIAL_GOOGLE_AUDIENCES` | empty | Comma-separated client ids accepted as an ID token audience. |
| `audiences.apple` | `MAGIC_STARTER_SOCIAL_APPLE_AUDIENCES` | empty | Same, for Apple. |
| `cache_store` | `MAGIC_STARTER_SOCIAL_CACHE_STORE` | app default | Cache store for flow state, codes and tickets. |
| `state_ttl` | `MAGIC_STARTER_SOCIAL_STATE_TTL` | `600` | Seconds a started flow stays valid. |
| `code_ttl` | `MAGIC_STARTER_SOCIAL_CODE_TTL` | `60` | Seconds the one-time code can be exchanged. |
| `link_ticket_ttl` | `MAGIC_STARTER_SOCIAL_LINK_TICKET_TTL` | `300` | Seconds a link ticket can start a connect. |
| `confirmation_ttl` | `MAGIC_STARTER_SOCIAL_CONFIRMATION_TTL` | `600` | Seconds a step-up confirmation token can be spent. |

A platform with no redirect target is refused with `platform_not_configured`. The target comes only from config, so nothing a request carries can choose where a code is sent.

---

<a name="browser-flow"></a>
## Browser Flow

Three steps: redirect, callback, exchange. All routes carry `throttle:magic-starter-auth-social` (see [Rate Limiters](../architecture/service-provider.md#rate-limiters)).

The app first creates a PKCE pair. `code_verifier` is a random string of 43 to 128 characters from `A-Z a-z 0-9 . _ ~ -`. `challenge` is `base64url(sha256(code_verifier))` without padding, exactly 43 characters. The one-time code the flow ends in is bound to that challenge, so a code intercepted on its way back to the app is worthless without the verifier.

### 1. Redirect

**Endpoint:** `GET {prefix}/auth/social/{provider}/redirect`

| Query | Rules |
|-------|-------|
| `platform` | Required. `native` or `web`. Picks the redirect target. |
| `challenge` | Required. 43 base64url characters. |
| `ticket` | Optional. A [link ticket](#connect-a-provider); makes the flow a connect. |
| `intent` | Optional. Only `confirm`, a [step-up](#step-up-confirmation) re-authentication. Cannot be combined with `ticket`. |

The answer is a `302` to the provider. With neither `ticket` nor `intent` the flow is a sign-in. A refusal answers JSON, because nothing is recorded yet that could send the browser back to the app:

| Status | Code | Cause |
|--------|------|-------|
| 404 | `provider_not_supported` | Unknown provider, or not on the allowlist. |
| 422 | `platform_not_configured` | No redirect target for the platform, or the provider is not configured. |
| 422 | `flow_expired` | The ticket is unknown, expired, already used, or was taken for another provider or challenge. |
| 422 | none | Validation error in the standard `message` and `errors` shape. |

### 2. Callback

**Endpoint:** `GET|POST /magic-starter/social/{provider}/callback`

The provider sends the browser here. The route has no prefix and joins no middleware group (Apple's cross-site `form_post` carries no CSRF token), and the flow it finishes lives in the state recorded at the redirect, not in a session.

The browser is then sent with a `302` to the configured target, with the outcome in the query string and `Referrer-Policy: no-referrer` and `Cache-Control: no-store` on the response:

```
myapp://auth/social?code=Zk3...
myapp://auth/social?error=social_email_taken
```

No token is ever placed in a URL. `error` is one of `flow_expired`, `invalid_identity` (the user declined, or the provider vouched for nobody) or a sign-in refusal: `social_email_taken`, `social_account_taken`, `provider_email_missing`. When there is no target to send the browser to, the same refusal answers as JSON with `code`.

### 3. Exchange

The app trades the code for what the flow concluded.

**Endpoint:** `POST {prefix}/auth/social/exchange`

```json
{
  "code": "Zk3...",
  "code_verifier": "the-43-to-128-char-verifier"
}
```

The code is single use and is spent before anything else. What comes back depends on the intent the flow was started with.

**Sign-in (200).** The same shape as the [Login](authentication.md#login) success response:

```json
{
  "data": {
    "user": { "..." },
    "token": "1|abc123..."
  },
  "message": "Login successful"
}
```

When the account confirmed 2FA the answer is the challenge instead, and the app finishes it at `auth/two-factor-challenge`:

```json
{
  "two_factor": true,
  "two_factor_token": "eyJpdiI6..."
}
```

Signing in while an account deletion is scheduled cancels it, and `data.deletion_cancelled` is `true`. See [Account Deletion](account-deletion.md).

**Connect (200).** Requires the caller's bearer token, and it must belong to the ticket's user:

```json
{
  "data": {
    "provider": "github",
    "email": "user@example.com"
  }
}
```

**Confirm (200).** Requires the caller's bearer token:

```json
{
  "data": {
    "confirmation_token": "c0nf1rm..."
  }
}
```

**Errors:**

| Status | Body | Cause |
|--------|------|-------|
| 422 | `code: flow_expired` | The code is unknown, expired, already used, or the verifier does not match. |
| 401 | `message` | A connect or confirm without a valid bearer, or one for another user. |
| 403 | `code: invalid_identity` | A confirm for an identity that is not linked to the caller, or whose link the provider revoked. |
| 409 | `code` | A connect refused: `social_account_taken`. |

Every coded refusal has the shape `{"message": "...", "code": "..."}`, and `message` is translated.

---

<a name="native-token-endpoint"></a>
## Native Token Endpoint

A mobile app that uses the Google or Apple SDK already holds an ID token, so it skips the browser.

**Endpoint:** `POST {prefix}/auth/social/{provider}/token`

**Providers:** `google` and `apple` only. GitHub and Microsoft answer 404.

```json
{
  "id_token": "eyJhbGciOi...",
  "nonce": "raw-nonce",
  "authorization_code": "c1a2b3...",
  "intent": "signin"
}
```

| Field | Type | Rules |
|-------|------|-------|
| `id_token` | string | Required. Max 8192 characters. |
| `nonce` | string | Required for Apple, prohibited for Google. The raw nonce whose `sha256` the client gave Apple. |
| `authorization_code` | string | Apple only, optional. Redeemed for the refresh token that deleting the account revokes. A failed redemption never blocks the sign-in. |
| `intent` | string | Optional. `signin` (default), `connect` or `confirm`. |

The token is the whole credential, so it is accepted only after its signature, issuer, audience (from `audiences.*`), expiry and, for Apple, nonce check out, and only once: a token or Apple nonce seen before is a replay and is refused.

A `signin` answers like the [exchange](#3-exchange) sign-in, 2FA challenge included. `connect` answers `{data: {provider, email}}` and `confirm` answers `{data: {confirmation_token}}`; both need the caller's bearer token and are refused with 401 without it, before the ID token is spent.

| Status | Code | Cause |
|--------|------|-------|
| 401 | `invalid_identity` | The ID token failed a check. With `APP_DEBUG=true` the response adds `error` with the reason. |
| 503 | `provider_unavailable` | The provider's signing keys could not be fetched. The token is not at fault; retry later. |
| 404 | `provider_not_supported` | The provider is not on the allowlist. |
| 403 | `invalid_identity` | A confirm for an identity not linked to the caller. |
| 409 | `social_email_taken`, `provider_email_missing`, `social_account_taken` | The identity may not sign in or connect, see below. |

---

<a name="accounts-and-identity"></a>
## Accounts and Identity

An identity is the provider's stable subject, never the email: Google `sub`, Apple `sub`, GitHub numeric id, Microsoft `oid` within its `tid`. The resolver works in this order:

1. A linked identity signs in as its user, whatever address the provider reports now.
2. An unlinked identity whose email already belongs to an account is refused with `social_email_taken` and never linked. A provider's email claim does not prove the person owns that account; the owner signs in and connects the provider from their profile.
3. An identity with no email is refused with `provider_email_missing`.
4. Otherwise a new account is created, with no password.

Further rules:

- Microsoft emails are never treated as verified.
- One account links at most one identity per provider, and an identity belongs to one account (`social_account_taken`).
- Apple refresh tokens are stored encrypted, and revoked at Apple on disconnect and on account deletion.
- The user resource gains `has_password`, `social_accounts` (`provider`, `email_at_link`, `created_at`, `revoked_at`) and `deletion_scheduled_at`, so a client can offer "set a password" instead of "change password".

---

<a name="connect-a-provider"></a>
## Connect a Provider

A signed-in user links a provider from their profile. The ticket carries the caller's identity into the browser without the bearer token ever reaching it.

**Endpoint:** `POST {prefix}/user/social-accounts/link-ticket`

**Middleware:** `auth:sanctum`, `throttle:magic-starter-auth-social`

```json
{
  "provider": "github",
  "challenge": "base64url-sha256-of-the-verifier"
}
```

```json
{
  "data": {
    "ticket": "t1ck3t..."
  }
}
```

The ticket is single use, expires after `link_ticket_ttl`, and is bound to the user, the provider and the challenge. It travels in the response body only. The app then starts the [redirect](#1-redirect) with `ticket=<ticket>` and the same `challenge`, and finishes at the [exchange](#3-exchange) with its bearer token. A native app can instead post the provider's ID token to the [token endpoint](#native-token-endpoint) with `intent=connect`.

An unknown provider answers 404 `provider_not_supported`.

---

<a name="disconnect-a-provider"></a>
## Disconnect a Provider

**Endpoint:** `DELETE {prefix}/user/social-accounts/{provider}`

**Middleware:** `auth:sanctum`, `throttle:magic-starter-auth-social`

Answers `204` with no body. The Apple grant is revoked after the unlink commits; a failed revocation is reported and does not undo it.

| Status | Code | Cause |
|--------|------|-------|
| 422 | `last_login_method` | The user has no password and this is their last active provider. The server holds this line whatever the client shows. |
| 404 | none | The user has no such link. |

A link the provider revoked no longer counts as a sign-in method.

---

<a name="set-a-password"></a>
## Set a Password

A social-only account has no current password to change, so it sets its first one here.

**Endpoint:** `POST {prefix}/user/password/set`

**Middleware:** `auth:sanctum`, `throttle:magic-starter-auth-social`

```json
{
  "password": "NewSecret123",
  "password_confirmation": "NewSecret123"
}
```

The password follows the same rules as a [password change](profile.md): at least 8 characters with letters, numbers and mixed case, and it must be confirmed. The answer is `200` with `{"data": null, "message": "..."}`. An account that already has a password is refused with 422 `password_already_set`.

`PUT {prefix}/user/password` on a password-less account answers 422 `password_not_set`. Use this endpoint instead.

---

<a name="step-up-confirmation"></a>
## Step-Up Confirmation

Sensitive endpoints re-confirm the caller. An account with a password sends `password`, exactly as before, and a guest without a password still passes. Any other password-less account sends one of:

| Field | Accepted when |
|-------|---------------|
| `code` | A TOTP code, when 2FA is confirmed on the account. |
| `confirmation_token` | A single-use token from the `confirm` intent, through the [browser flow](#1-redirect) (`intent=confirm`) or the [native endpoint](#native-token-endpoint). |

It applies to enabling and disabling 2FA, showing and regenerating recovery codes, revoking sessions, and `DELETE {prefix}/user`. A code is judged on its own, so a mistyped code is reported as one instead of spending a token sent beside it. A confirmation token is consumed only when nothing else in the request failed.

Without a live proof the answer is 422:

```json
{
  "message": "Please confirm your identity to continue.",
  "code": "step_up_required",
  "accepts": ["code", "confirmation_token"],
  "errors": {
    "code": ["Please confirm your identity to continue."],
    "confirmation_token": ["Please confirm your identity to continue."]
  }
}
```

`accepts` names the proofs this account can send; `code` is absent when 2FA is not confirmed.

---

<a name="apple-notifications"></a>
## Apple Notifications

Apple posts server-to-server notifications when a user stops using Sign in with Apple for your app, deletes their Apple Account, or toggles mail forwarding. Register this URL as the notification endpoint of your Apple app:

**Endpoint:** `POST /magic-starter/social/apple/notifications`

The route has no prefix and no middleware. The JWS signature is the only credential and is verified before anything is read: an unverifiable payload answers 400, and every verified one answers 200, including an unknown subject, so Apple does not retry what no retry can fix.

| Event | Effect |
|-------|--------|
| `consent-revoked` | The link is kept with `revoked_at` set and signs nobody in. The stored refresh token is dropped and the user's sessions are revoked. The next sign-in with the same subject reactivates it. |
| `account-deleted` | As `consent-revoked`, and when Apple was the user's last way in (no password, no other active link) an orphan deletion is scheduled. |
| `email-enabled`, `email-disabled` | `email_at_link` is updated to the relay address. |

---

<a name="error-codes"></a>
## Error Codes

Every code is a key of `lang/en/social.php`, and its sentence is translated. A client switches on `code`, not on `message`.

| Code | Meaning |
|------|---------|
| `social_email_taken` | An account with this email exists. Sign in and connect the provider from the profile. |
| `social_account_taken` | The provider account is linked to another user, or the caller already holds another account of that provider. |
| `last_login_method` | The last sign-in method of a password-less account cannot be removed. |
| `provider_not_supported` | Unknown provider, or not on the allowlist. |
| `platform_not_configured` | No redirect target for the platform, or the provider is not configured. |
| `flow_expired` | The state, code or ticket is unknown, expired or already used. |
| `invalid_identity` | The provider's answer or ID token could not be verified. |
| `provider_email_missing` | The provider returned no email for a new account. |
| `password_already_set` | The account already has a password. |
| `password_not_set` | The account has no password to change; set one instead. |
| `step_up_required` | A sensitive action needs a `code` or `confirmation_token`. |
| `provider_unavailable` | The provider's keys could not be fetched; retry later. |
| `owns_shared_teams` | Account deletion refused, see [Account Deletion](account-deletion.md). |
| `team_has_active_subscription` | Account or team deletion refused while a subscription is live. |
| `deletion_scheduled` | The account is scheduled for deletion. |
| `deletion_cancelled` | A sign-in cancelled a scheduled deletion. |
