# Sign-in Handoff

- [Introduction](#introduction)
- [Configuration](#configuration)
- [Mint a Code](#mint-a-code)
- [Exchange a Code](#exchange-a-code)
- [Security Properties](#security-properties)
- [Using It from a Browser](#using-it-from-a-browser)
- [Overriding the Actions](#overriding-the-actions)

---

<a name="introduction"></a>
## Introduction

A sign-in handoff moves a session that is already signed in (a marketing site, a web dashboard) to another origin (the Flutter web app, or a mobile app through a deep link) without ever putting a bearer token in a url. The signed-in side mints a short-lived, single-use code; the browser carries the code to the app; the app trades it, unauthenticated, for the same answer a [login](authentication.md#login) gives. Throughout this document `{prefix}` is `config('magic-starter.route_prefix')`.

**Feature:** `Features::signInHandoff()` (`'sign-in-handoff'`). Neither route in this document is registered while the feature is off, and the gate is read when the routes load, so a `route:cache` freezes it.

---

<a name="configuration"></a>
## Configuration

Enable the feature in `config/magic-starter.php`:

```php
'features' => [
    \FlutterSdk\MagicStarter\Features::signInHandoff(),
],
```

The `handoff` block:

| Key | Env | Default | Meaning |
|---|---|---|---|
| `code_ttl` | `MAGIC_STARTER_HANDOFF_CODE_TTL` | `60` | Seconds a code lives. |
| `cache_store` | `MAGIC_STARTER_HANDOFF_CACHE_STORE` | `null` | The cache store that holds the codes; `null` is the app default. Every web node must share it, since the exchange can land on another node than the mint. |
| `max_token_age` | `MAGIC_STARTER_HANDOFF_MAX_TOKEN_AGE` | `null` | Seconds. A mint from a token older than this answers 403 `handoff_forbidden`; `null` is no limit. A leaked token can otherwise mint codes and clone itself into sessions that survive its revocation, so a surface that only hands off a fresh sign-up (a landing page) should set a few minutes. |

---

<a name="mint-a-code"></a>
## Mint a Code

**Endpoint:** `POST {prefix}/auth/handoff`

**Middleware:** `auth:sanctum`, `throttle:magic-starter-auth-handoff` (10 a minute per user and address)

The code is minted for the CALLER; nothing in the body can name another user. Because the exchange issues a full-ability token, the caller must hold one already: a guest user, or a token that cannot do everything (`tokenCan('*')` false), is refused before any code is minted:

**Response (403):**

```json
{
    "message": "Only a fully signed-in account can start a sign-in on another device.",
    "code": "handoff_forbidden"
}
```

**Request:**

```json
{
    "next": "/connect?code=abc"
}
```

`next` is optional: the same-app path the app opens after the sign-in. It is refused with 422 on `next` unless it:

- starts with exactly one `/` (so `//evil.com` and `http://x` are refused),
- holds no backslash (a browser reads `/\evil` as `//evil`),
- holds no whitespace, separator or control character (a browser strips a tab or a newline, so `/<tab>/evil.com` would become `//evil.com`),
- has no `..` path segment, percent-encoded (`%2e%2e`) included,
- is at most 512 characters.

A validation refusal is JSON whatever the request's `Accept` header says.

**Response (201):**

```json
{
    "data": {
        "code": "<64 random characters>",
        "expires_in": 60
    }
}
```

---

<a name="exchange-a-code"></a>
## Exchange a Code

**Endpoint:** `POST {prefix}/auth/handoff/exchange`

**Middleware:** `throttle:magic-starter-auth-handoff-exchange` (10 a minute per address, an IPv6 address collapsed to its /64). No bearer.

**Request:**

```json
{
    "code": "<the code>"
}
```

**Response (200):** the login answer for the user who minted the code, plus `data.next`:

```json
{
    "data": {
        "user": {
            "id": "9b1f...",
            "name": "Ada",
            "email": "ada@example.com"
        },
        "token": "1|abc...",
        "next": "/connect?code=abc"
    },
    "message": "Login successful"
}
```

The token records the exchange's IP address and user agent, and a deletion the user scheduled is cancelled exactly as on a login (`data.deletion_cancelled` and the cancellation message, see [Account Deletion](account-deletion.md)).

**Response (200), two-factor enabled:** the same challenge a login answers, with `data.next` beside it so the app can finish after the [Two-Factor Challenge](authentication.md#two-factor-challenge):

```json
{
    "two_factor": true,
    "two_factor_token": "eyJpdiI6...",
    "data": {
        "next": "/connect?code=abc"
    }
}
```

**Response (422), every refusal:**

```json
{
    "message": "This sign-in link has expired. Sign in again.",
    "code": "handoff_expired"
}
```

An unknown, spent or expired code, a code whose user was deleted since, and a `code` that is missing, not a string or longer than 255 characters all answer this one body: the exchange has no field validation errors. Switch on `code`, never on `message`.

---

<a name="security-properties"></a>
## Security Properties

- **Single use.** The exchange claims a sentinel with the cache's atomic `add` before it reads the code, so two racing consumers cannot both win, and the code is spent even when a later step refuses.
- **Short-lived.** 60 seconds by default (`handoff.code_ttl`).
- **Hashed at rest.** The cache key is `magic-starter:handoff:` plus the sha256 of the code, and the record holds only the user id and `next`. A cache dump replays nothing.
- **Second factor respected.** The exchange finishes through the same decision a login uses, so a confirmed 2FA is challenged, never skipped.
- **No oracle.** Every refusal is the one 422 above.
- **Bound to its minter.** A code signs in only the user whose bearer minted it.
- **No escalation.** A guest or a restricted-ability token cannot mint, so a code never turns a lesser credential into a full one.
- **`next` stays in the app.** See the rules under [Mint a Code](#mint-a-code). The app should still open `next` through its own router, never as a full url.

---

<a name="using-it-from-a-browser"></a>
## Using It from a Browser

1. The signed-in page calls `POST {prefix}/auth/handoff` with its bearer and the `next` it wants. A page on another origin than the API needs the API's CORS configuration to allow it; that is the host application's setting.
2. It sends the browser to the app with the code, for example `https://app.example.com/auth/handoff?code=<code>` or a Universal Link / App Link into the mobile app. Send it at once: the code lives a minute.
3. The app posts the code to `POST {prefix}/auth/handoff/exchange`, signs in with the answer (or runs the 2FA challenge), replaces the url so the spent code leaves the history, and opens `data.next`.

---

<a name="overriding-the-actions"></a>
## Overriding the Actions

| Contract | Default action | Role |
|---|---|---|
| `IssuesHandoffCodes` | `Actions\IssueHandoffCode` | `issue(Authenticatable $user, ?string $next): array{code, expires_in}`. Mints a code for the caller. |
| `ExchangesHandoffCodes` | `Actions\ExchangeHandoffCode` | `exchange(string $code): ?array{user, next}`. Spends the code and resolves its user; null for every refusal. |

Bind your own in a service provider, as for every other [action contract](../architecture/action-contracts.md):

```php
$this->app->bind(ExchangesHandoffCodes::class, MyExchangeHandoffCode::class);
```
