# Authentication

- [Introduction](#introduction)
- [Login](#login)
- [Registration](#registration)
- [Logout](#logout)
- [Current User](#current-user)
- [Social Login](#social-login)
- [Password Reset](#password-reset)
- [Guest Authentication](#guest-authentication)
- [Phone OTP](#phone-otp)
- [Two-Factor Challenge](#two-factor-challenge)
- [Switch Team](#switch-team)

---

<a name="introduction"></a>
## Introduction

Magic Starter uses [Laravel Sanctum](https://laravel.com/docs/sanctum) for token-based API authentication. Every authenticated response returns a plain-text Bearer token that must be included in subsequent requests via the `Authorization` header:

```
Authorization: Bearer {token}
```

All authentication routes are registered under the configurable route prefix (`config('magic-starter.route_prefix')`). Throughout this document, `{prefix}` refers to that value. If no prefix is configured, routes are registered at the root.

The identity strategy is configurable. Magic Starter supports **email-based**, **phone-based**, or **dual-identity** login and registration. This is controlled by `config('magic-starter.auth.email')` and `config('magic-starter.auth.phone')`. When both are enabled, users may authenticate with either identifier.

Device information (IP address and user agent) is automatically stored on every issued token for session management purposes.

---

<a name="login"></a>
## Login

Authenticates a user by email or phone and password. When two-factor authentication is enabled for the user, returns a challenge token instead of the full auth response.

**Endpoint:** `POST {prefix}/auth/login`

**Middleware:** `throttle:magic-starter-auth-login`

**Rate Limit:** 5 requests per minute, keyed by IP + email.

### Request Body

The identity field varies based on the configured authentication strategy:

**Email-only (default):**

```json
{
  "email": "user@example.com",
  "password": "secret123"
}
```

**Phone-only:**

```json
{
  "phone": "+905551234567",
  "password": "secret123"
}
```

**Dual-identity (email + phone enabled):**

Provide either `email` or `phone` -- at least one is required.

```json
{
  "email": "user@example.com",
  "password": "secret123"
}
```

| Field      | Type   | Rules                                                              |
|------------|--------|--------------------------------------------------------------------|
| `email`    | string | Required (or `required_without:phone` in dual mode). Valid email.  |
| `phone`    | string | Required (or `required_without:email` in dual mode). E.164 format. |
| `password` | string | Required.                                                          |

### Success Response (200)

```json
{
  "data": {
    "user": {
      "id": "9a8b7c6d-...",
      "name": "John Doe",
      "email": "user@example.com",
      "phone": null,
      "is_guest": false,
      "email_verified_at": "2025-01-01T00:00:00.000000Z",
      "locale": "en",
      "timezone": "UTC",
      "profile_photo_url": "https://ui-avatars.com/api/?name=John+Doe",
      "two_factor_enabled": false,
      "current_team": { "..." },
      "all_teams": [ "..." ],
      "created_at": "2025-01-01T00:00:00.000000Z",
      "updated_at": "2025-01-01T00:00:00.000000Z"
    },
    "token": "1|abc123..."
  },
  "message": "Login successful"
}
```

> [!NOTE]
> The `current_team` and `all_teams` fields are only present when the `teams` feature is enabled.

### Two-Factor Challenge Response (200)

When the user has 2FA enabled, the login endpoint returns a challenge token instead:

```json
{
  "two_factor": true,
  "two_factor_token": "eyJpdiI6..."
}
```

The client must then call the [Two-Factor Challenge](#two-factor-challenge) endpoint with this token.

### Error Response (401)

```json
{
  "message": "Invalid credentials"
}
```

---

<a name="registration"></a>
## Registration

Creates a new user account and returns an authenticated response with a Sanctum token.

**Endpoint:** `POST {prefix}/auth/register`

**Middleware:** `throttle:magic-starter-auth-register`

**Rate Limit:** 3 requests per minute, keyed by IP.

### Request Body

```json
{
  "name": "John Doe",
  "email": "user@example.com",
  "password": "Secret123",
  "password_confirmation": "Secret123",
  "locale": "en",
  "timezone": "Europe/Istanbul",
  "subscribe_newsletter": true
}
```

| Field                   | Type    | Rules                                                                                     |
|-------------------------|---------|-------------------------------------------------------------------------------------------|
| `name`                  | string  | Required. Max 255 characters.                                                             |
| `email`                 | string  | Required (or `required_without:phone` in dual mode). Valid email. Unique in users table.   |
| `phone`                 | string  | Required (or `required_without:email` in dual mode). E.164 format. Unique in users table.  |
| `password`              | string  | Required. Min 8 chars, must contain letters, numbers, and mixed case. Must be confirmed.   |
| `password_confirmation` | string  | Required. Must match `password`.                                                          |
| `locale`                | string  | Optional. Must be in `config('magic-starter.supported_locales')`.                          |
| `timezone`              | string  | Optional. Must be a valid IANA timezone identifier.                                        |
| `subscribe_newsletter`  | boolean | Optional. When `true` and the newsletter feature is enabled, creates a subscriber record.  |

> [!NOTE]
> The `locale` and `timezone` fields are auto-detected from request headers (`Accept-Language`, timezone headers) when not explicitly provided. They fall back to the defaults in `config('magic-starter.defaults')`.

### Success Response (201)

```json
{
  "data": {
    "user": { "..." },
    "token": "1|abc123..."
  },
  "message": "Registration successful"
}
```

The user resource shape is the same as described in [Login](#login).

> [!NOTE]
> When the `email-verification` feature is enabled and the user has an email address, a verification notification is automatically sent upon registration.

### Validation Error Response (422)

```json
{
  "message": "The email has already been taken.",
  "errors": {
    "email": ["The email has already been taken."]
  }
}
```

---

<a name="logout"></a>
## Logout

Revokes the current Sanctum access token.

**Endpoint:** `POST {prefix}/auth/logout`

**Middleware:** `auth:sanctum`

### Request Body

No body required. The token is identified from the `Authorization` header.

### Success Response (200)

```json
{
  "data": null,
  "message": "Logged out successfully"
}
```

---

<a name="current-user"></a>
## Current User

Returns the currently authenticated user.

**Endpoint:** `GET {prefix}/auth/user`

**Middleware:** `auth:sanctum`

### Success Response (200)

```json
{
  "data": {
    "id": "9a8b7c6d-...",
    "name": "John Doe",
    "email": "user@example.com",
    "phone": null,
    "is_guest": false,
    "email_verified_at": "2025-01-01T00:00:00.000000Z",
    "locale": "en",
    "timezone": "UTC",
    "profile_photo_url": "https://ui-avatars.com/api/?name=John+Doe",
    "two_factor_enabled": false,
    "current_team": { "..." },
    "all_teams": [ "..." ],
    "created_at": "2025-01-01T00:00:00.000000Z",
    "updated_at": "2025-01-01T00:00:00.000000Z"
  }
}
```

---

<a name="social-login"></a>
## Social Login

Authenticates a user via a third-party OAuth provider using Laravel Socialite. If no user exists with the provider's email, a new account is created automatically with a random password and the email marked as verified.

**Endpoint:** `POST {prefix}/auth/social/{provider}`

**Middleware:** `throttle:magic-starter-auth-social`

**Rate Limit:** 10 requests per minute, keyed by IP + provider.

The `{provider}` parameter is the Socialite driver name (e.g., `google`, `apple`, `github`). The provider must be configured in your application's `config/services.php`.

### Request Body

Provide either `access_token` or `authorization_code` -- at least one is required.

**Using an access token:**

```json
{
  "access_token": "ya29.a0AfH6..."
}
```

**Using an authorization code (e.g., Sign in with Apple):**

```json
{
  "authorization_code": "c1a2b3..."
}
```

| Field                | Type   | Rules                                     |
|----------------------|--------|-------------------------------------------|
| `access_token`       | string | Required without `authorization_code`.    |
| `authorization_code` | string | Required without `access_token`.          |

### Success Response (200)

Same shape as the [Login](#login) success response.

### Error Response (401)

Returned when the token is invalid or the provider rejects the credentials.

```json
{
  "message": "Invalid token or provider"
}
```

> [!NOTE]
> In debug mode (`APP_DEBUG=true`), the response includes an `error` field with the underlying exception message.

---

<a name="password-reset"></a>
## Password Reset

A two-step flow: first request a reset link, then submit the new password with the token received via email.

### Send Reset Link

Sends a password reset email. Always returns 200 regardless of whether the email exists, to prevent user enumeration.

**Endpoint:** `POST {prefix}/auth/forgot-password`

**Middleware:** `throttle:magic-starter-auth-password-reset`

**Rate Limit:** 3 requests per minute, keyed by IP + email.

#### Request Body

```json
{
  "email": "user@example.com"
}
```

| Field   | Type   | Rules              |
|---------|--------|--------------------|
| `email` | string | Required. Email.   |

#### Success Response (200)

```json
{
  "data": null,
  "message": "If an account with that email exists, a password reset link has been sent."
}
```

> [!NOTE]
> The password reset URL is customized in `MagicStarterServiceProvider::boot()` to point to your frontend application. Configure it via `config('magic-starter.password_reset_url')`.

### Reset Password

Resets the user's password using the token from the email.

**Endpoint:** `POST {prefix}/auth/reset-password`

**Middleware:** `throttle:magic-starter-auth-password-reset`

**Rate Limit:** 3 requests per minute, keyed by IP + email.

#### Request Body

```json
{
  "token": "a1b2c3d4...",
  "email": "user@example.com",
  "password": "NewSecret123",
  "password_confirmation": "NewSecret123"
}
```

| Field                   | Type   | Rules                                                                           |
|-------------------------|--------|---------------------------------------------------------------------------------|
| `token`                 | string | Required. The reset token from the email.                                       |
| `email`                 | string | Required. Email.                                                                |
| `password`              | string | Required. Min 8 chars, letters, numbers, mixed case. Must be confirmed.         |
| `password_confirmation` | string | Required. Must match `password`.                                                |

#### Success Response (200)

```json
{
  "data": null,
  "message": "Your password has been reset."
}
```

#### Error Response (422)

Returned when the token is invalid, expired, or the email does not match.

```json
{
  "data": null,
  "message": "This password reset token is invalid."
}
```

---

<a name="guest-authentication"></a>
## Guest Authentication

Creates or retrieves a guest user identified by a device ID, and issues a Sanctum token. Guest users have `null` email and `null` password.

**Endpoint:** `POST {prefix}/auth/guest`

**Middleware:** `throttle:magic-starter-guest-auth`

**Rate Limit:** 10 requests per minute, keyed by IP + device ID.

> [!NOTE]
> This endpoint is only available when the `guest-auth` feature is enabled in `config('magic-starter.features')`.

### Request Body

```json
{
  "device_id": "a1b2c3d4-e5f6-..."
}
```

| Field       | Type   | Rules                        |
|-------------|--------|------------------------------|
| `device_id` | string | Required. Max 255 characters. |

### Success Response (201 -- new guest, 200 -- returning guest)

```json
{
  "data": {
    "user": {
      "id": "...",
      "name": "Guest",
      "email": null,
      "phone": null,
      "is_guest": true,
      "..."
    },
    "token": "1|abc123..."
  },
  "message": "Guest session started"
}
```

When a returning guest authenticates (same `device_id`), all previous tokens are revoked before issuing a new one to prevent session buildup.

---

<a name="phone-otp"></a>
## Phone OTP

A two-step phone-based authentication flow: send a 6-digit OTP code to a phone number, then verify the code to receive an auth token. The OTP is cached for 5 minutes.

> [!NOTE]
> These endpoints are only available when the `phone-otp` feature is enabled in `config('magic-starter.features')`.

### Send OTP

Sends a 6-digit OTP code to the provided phone number. The actual delivery mechanism is delegated to the `SendsOtpCodes` contract, which you must bind in your application.

**Endpoint:** `POST {prefix}/auth/otp/send`

**Middleware:** `throttle:magic-starter-otp`

**Rate Limit:** 2 requests per minute, keyed by IP + phone.

#### Request Body

```json
{
  "phone": "+905551234567"
}
```

| Field   | Type   | Rules                           |
|---------|--------|---------------------------------|
| `phone` | string | Required. E.164 format.         |

#### Success Response (200)

```json
{
  "message": "OTP sent successfully"
}
```

### Verify OTP

Verifies the OTP code and authenticates the user associated with the phone number. The verification logic is delegated to the `VerifiesOtpCodes` contract.

**Endpoint:** `POST {prefix}/auth/otp/verify`

**Middleware:** `throttle:magic-starter-otp`

**Rate Limit:** 5 requests per minute, keyed by IP + phone.

#### Request Body

```json
{
  "phone": "+905551234567",
  "code": "123456"
}
```

| Field   | Type   | Rules                              |
|---------|--------|------------------------------------|
| `phone` | string | Required. E.164 format.            |
| `code`  | string | Required. Exactly 6 characters.    |

#### Success Response (200)

Same shape as the [Login](#login) success response.

#### Error Response (401)

```json
{
  "message": "Invalid or expired OTP"
}
```

#### Error Response (404)

Returned when the OTP is valid but no user exists with the given phone number.

```json
{
  "message": "User not found"
}
```

---

<a name="two-factor-challenge"></a>
## Two-Factor Challenge

Completes a two-factor authentication challenge after a successful login that returned `"two_factor": true`. Accepts either a TOTP code from an authenticator app or a recovery code.

**Endpoint:** `POST {prefix}/auth/two-factor-challenge`

**Middleware:** `throttle:magic-starter-2fa-challenge`

**Rate Limit:** 5 requests per minute, keyed by IP + two-factor token.

> [!NOTE]
> This endpoint is only available when the `two-factor-authentication` feature is enabled in `config('magic-starter.features')`.

### Request Body (TOTP code)

```json
{
  "two_factor_token": "eyJpdiI6...",
  "code": "123456"
}
```

### Request Body (Recovery code)

```json
{
  "two_factor_token": "eyJpdiI6...",
  "recovery_code": "abcde-12345"
}
```

| Field              | Type   | Rules                                    |
|--------------------|--------|------------------------------------------|
| `two_factor_token` | string | Required. The encrypted challenge token from the login response. |
| `code`             | string | Nullable. The 6-digit TOTP code.         |
| `recovery_code`    | string | Nullable. A one-time recovery code.      |

When `recovery_code` is provided, the `code` field is automatically nullified. Provide one or the other, not both.

The challenge token expires after the configured TTL (`config('magic-starter.two_factor.challenge_token_ttl')`, default: 5 minutes).

### Success Response (200)

Same shape as the [Login](#login) success response.

### Error Responses (422)

**Expired token:**

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "two_factor_token": ["Two-factor authentication token has expired."]
  }
}
```

**Invalid TOTP code:**

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "code": ["The provided two-factor authentication code was invalid."]
  }
}
```

**Invalid recovery code:**

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "recovery_code": ["The provided two-factor authentication recovery code was invalid."]
  }
}
```

---

<a name="switch-team"></a>
## Switch Team

Switches the authenticated user's current team. The user must be a member of the target team.

**Endpoint:** `PUT {prefix}/user/current-team`

**Middleware:** `auth:sanctum`

> [!NOTE]
> This endpoint is only available when the `teams` feature is enabled in `config('magic-starter.features')`.

### Request Body

```json
{
  "team_id": "9a8b7c6d-..."
}
```

| Field     | Type   | Rules                                         |
|-----------|--------|-----------------------------------------------|
| `team_id` | string | Required. UUID. Must exist in the teams table. |

Authorization is enforced via the `switchTo` Gate -- the user must be permitted to switch to the given team.

### Success Response (200)

```json
{
  "data": {
    "id": "...",
    "name": "John Doe",
    "email": "user@example.com",
    "current_team": {
      "id": "9a8b7c6d-...",
      "name": "My Team",
      "..."
    },
    "..."
  },
  "message": "Team switched successfully"
}
```

### Error Response (403)

```json
{
  "message": "You are not a member of this team."
}
```
