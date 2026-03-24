# Two-Factor Authentication

- [Introduction](#introduction)
- [Enable 2FA](#enable-2fa)
- [Confirm 2FA](#confirm-2fa)
- [Disable 2FA](#disable-2fa)
- [Recovery Codes](#recovery-codes)
  - [View Recovery Codes](#view-recovery-codes)
  - [Regenerate Recovery Codes](#regenerate-recovery-codes)
- [2FA Challenge](#2fa-challenge)
  - [Verify with TOTP Code](#verify-with-totp-code)
  - [Verify with Recovery Code](#verify-with-recovery-code)
- [TwoFactorAuthenticatable Trait](#twofactorauthenticatable-trait)
- [Configuration](#configuration)

---

## <a name="introduction"></a>Introduction

Magic Starter provides TOTP-based (Time-based One-Time Password) two-factor authentication. The feature is disabled by default and must be explicitly opted in via the feature flag.

When 2FA is enabled, the login flow becomes a two-step process: the normal credential check returns a short-lived `two_factor_token` instead of a Sanctum token, and the client then exchanges that token (plus a TOTP code or recovery code) for the actual Sanctum bearer token.

The TOTP implementation uses `pragmarx/google2fa` under the hood. QR codes are rendered as inline SVG using `bacon/bacon-qr-code` — no external image service is involved.

**Enabling the feature:**

```php
// config/magic-starter.php
'features' => [
    \FlutterSdk\MagicStarter\Features::twoFactorAuthentication(),
],
```

All 2FA management endpoints (`POST/DELETE two-factor-authentication`, `POST two-factor-recovery-codes/*`) require `auth:sanctum` middleware. The challenge endpoint (`POST auth/two-factor-challenge`) is public but rate-limited.

---

## <a name="enable-2fa"></a>Enable 2FA

Initiates 2FA setup for the authenticated user. Generates a TOTP secret, a set of recovery codes, and returns a QR code SVG ready to display to the user. 2FA is not yet active until confirmed — see [Confirm 2FA](#confirm-2fa).

**Endpoint**

```
POST /api/v1/two-factor-authentication
```

**Headers**

| Header          | Value              |
|-----------------|--------------------|
| `Authorization` | `Bearer {token}`   |
| `Accept`        | `application/json` |

**Request body**

None required.

**Response `200 OK`**

```json
{
  "data": {
    "secret": "BASE32ENCODEDSECRET",
    "qr_url": "otpauth://totp/AppName:user@example.com?secret=BASE32ENCODEDSECRET&issuer=AppName",
    "qr_svg": "<svg xmlns=\"http://www.w3.org/2000/svg\" ...>...</svg>",
    "recovery_codes": [
      "abc12-def34",
      "ghi56-jkl78"
    ]
  },
  "message": "Two-factor authentication enabled. Please confirm with your authenticator app."
}
```

| Field            | Description                                                                 |
|------------------|-----------------------------------------------------------------------------|
| `secret`         | The base32-encoded TOTP secret. Store securely — show once.                 |
| `qr_url`         | The `otpauth://` URI. Can be used to generate a custom QR code client-side. |
| `qr_svg`         | Inline SVG QR code (192 px). Render directly in the UI without any dependencies. |
| `recovery_codes` | One-time-use backup codes. Show to the user and prompt them to save securely. |

> [!NOTE]
> Calling this endpoint again before confirming will overwrite the existing unconfirmed secret and generate a new set of recovery codes. The user will lose the previous QR code.

---

## <a name="confirm-2fa"></a>Confirm 2FA

Activates 2FA by verifying the first TOTP code from the authenticator app. Until this endpoint is called successfully, `hasEnabledTwoFactorAuthentication()` returns `false` and the 2FA challenge is not enforced during login.

**Endpoint**

```
POST /api/v1/two-factor-authentication/confirm
```

**Headers**

| Header          | Value              |
|-----------------|--------------------|
| `Authorization` | `Bearer {token}`   |
| `Accept`        | `application/json` |
| `Content-Type`  | `application/json` |

**Request body**

| Field  | Type   | Required | Description                         |
|--------|--------|----------|-------------------------------------|
| `code` | string | yes      | 6-digit TOTP code from the authenticator app. |

```json
{
  "code": "123456"
}
```

**Response `200 OK`**

```json
{
  "data": null,
  "message": "Two-factor authentication confirmed successfully."
}
```

> [!NOTE]
> The `code` is validated with a clock-drift window of ±1 interval (30 seconds), so minor time differences between the server and the user's device are tolerated.

---

## <a name="disable-2fa"></a>Disable 2FA

Disables two-factor authentication for the authenticated user. Clears the stored secret, recovery codes, and confirmation timestamp.

**Endpoint**

```
DELETE /api/v1/two-factor-authentication
```

**Headers**

| Header          | Value              |
|-----------------|--------------------|
| `Authorization` | `Bearer {token}`   |
| `Accept`        | `application/json` |

**Request body**

None required.

**Response `200 OK`**

```json
{
  "data": null,
  "message": "Two-factor authentication has been disabled."
}
```

---

## <a name="recovery-codes"></a>Recovery Codes

Recovery codes are one-time-use backup codes generated when 2FA is enabled. Each code can only be used once — after use it is replaced with a newly generated random code in the stored set.

Both recovery code endpoints require password confirmation (sudo mode) in the request body to prevent unauthorized access.

### <a name="view-recovery-codes"></a>View Recovery Codes

Returns the current list of unused recovery codes.

**Endpoint**

```
POST /api/v1/two-factor-recovery-codes/show
```

> [!NOTE]
> This uses `POST` (not `GET`) because the request requires a password in the body. A `GET` request with credentials in the body is non-standard and may be stripped by proxies.

**Headers**

| Header          | Value              |
|-----------------|--------------------|
| `Authorization` | `Bearer {token}`   |
| `Accept`        | `application/json` |
| `Content-Type`  | `application/json` |

**Request body**

| Field      | Type   | Required | Description                  |
|------------|--------|----------|------------------------------|
| `password` | string | yes      | The user's current password. |

```json
{
  "password": "current-password"
}
```

**Response `200 OK`**

```json
{
  "data": [
    "abc12-def34",
    "ghi56-jkl78"
  ],
  "message": "Recovery codes retrieved successfully."
}
```

**Response `403 Forbidden`** — when 2FA is not enabled:

```json
{
  "message": "Two-factor authentication is not enabled."
}
```

### <a name="regenerate-recovery-codes"></a>Regenerate Recovery Codes

Generates a fresh set of recovery codes, invalidating all previous ones. Use this when recovery codes have been exhausted or potentially compromised.

**Endpoint**

```
POST /api/v1/two-factor-recovery-codes
```

**Headers**

| Header          | Value              |
|-----------------|--------------------|
| `Authorization` | `Bearer {token}`   |
| `Accept`        | `application/json` |
| `Content-Type`  | `application/json` |

**Request body**

| Field      | Type   | Required | Description                  |
|------------|--------|----------|------------------------------|
| `password` | string | yes      | The user's current password. |

```json
{
  "password": "current-password"
}
```

**Response `200 OK`**

```json
{
  "data": [
    "newcode1-abc12",
    "newcode2-def34"
  ],
  "message": "Recovery codes regenerated successfully."
}
```

> [!NOTE]
> All previously issued recovery codes are immediately invalidated when regeneration is triggered. Make sure to present the new codes to the user before navigating away.

---

## <a name="2fa-challenge"></a>2FA Challenge

When a user with confirmed 2FA enabled logs in, the login endpoint returns a `two_factor_token` instead of a Sanctum bearer token. The client must exchange this token — together with either a TOTP code or a recovery code — for the actual session token.

The challenge token is a short-lived encrypted payload. Its TTL is controlled by `magic-starter.two_factor.challenge_token_ttl` (default: 5 minutes).

**Endpoint**

```
POST /api/v1/auth/two-factor-challenge
```

**Headers**

| Header         | Value              |
|----------------|--------------------|
| `Accept`       | `application/json` |
| `Content-Type` | `application/json` |

> [!NOTE]
> This endpoint does not require `Authorization` — authentication is established by the `two_factor_token` itself. The endpoint is rate-limited by the `magic-starter-2fa-challenge` limiter.

### <a name="verify-with-totp-code"></a>Verify with TOTP Code

**Request body**

| Field               | Type   | Required | Description                                                     |
|---------------------|--------|----------|-----------------------------------------------------------------|
| `two_factor_token`  | string | yes      | The encrypted token returned by the login endpoint.             |
| `code`              | string | yes      | 6-digit TOTP code from the user's authenticator app.            |

```json
{
  "two_factor_token": "eyJpdiI6Ij...",
  "code": "123456"
}
```

### <a name="verify-with-recovery-code"></a>Verify with Recovery Code

**Request body**

| Field               | Type   | Required | Description                                                     |
|---------------------|--------|----------|-----------------------------------------------------------------|
| `two_factor_token`  | string | yes      | The encrypted token returned by the login endpoint.             |
| `recovery_code`     | string | yes      | One of the user's unused recovery codes.                        |

```json
{
  "two_factor_token": "eyJpdiI6Ij...",
  "recovery_code": "abc12-def34"
}
```

**Response `200 OK`** — same shape as the normal login response:

```json
{
  "data": {
    "token": "sanctum-bearer-token",
    "user": { "..." : "..." }
  }
}
```

**Error responses**

| Scenario                       | Field               | Message                                                                  |
|--------------------------------|---------------------|--------------------------------------------------------------------------|
| Tampered or invalid token      | `two_factor_token`  | `Invalid two-factor authentication token.`                               |
| Expired token                  | `two_factor_token`  | `Two-factor authentication token has expired.`                           |
| Invalid TOTP code              | `code`              | `The provided two-factor authentication code was invalid.`               |
| Invalid recovery code          | `recovery_code`     | `The provided two-factor authentication recovery code was invalid.`      |

> [!NOTE]
> When a recovery code is used successfully, it is immediately replaced with a new random code in the stored set. The consumed code cannot be reused.

---

## <a name="twofactorauthenticatable-trait"></a>TwoFactorAuthenticatable Trait

Add this trait to your `User` model to provide the 2FA capabilities the package relies on.

```php
use FlutterSdk\MagicStarter\Traits\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    use TwoFactorAuthenticatable;
}
```

**Database columns required** (added by the package migration):

| Column                       | Type        | Description                                          |
|------------------------------|-------------|------------------------------------------------------|
| `two_factor_secret`          | `text|null` | Encrypted base32 TOTP secret.                        |
| `two_factor_recovery_codes`  | `text|null` | Encrypted JSON array of recovery codes.              |
| `two_factor_confirmed_at`    | `timestamp|null` | Set when the user confirms their first TOTP code. |

**Public methods provided by the trait:**

| Method                                  | Return type        | Description                                                     |
|-----------------------------------------|--------------------|------------------------------------------------------------------|
| `hasEnabledTwoFactorAuthentication()`   | `bool`             | Returns `true` only when `two_factor_confirmed_at` is not null. |
| `twoFactorSecret()`                     | `string\|null`     | Decrypts and returns the raw base32 secret.                     |
| `recoveryCodes()`                       | `array<int,string>`| Decrypts and returns the current recovery code array.           |
| `twoFactorRecoveryCodesCount()`         | `int`              | Returns the count of remaining recovery codes.                  |
| `replaceRecoveryCode(string $code)`     | `void`             | Replaces a consumed recovery code with a fresh random string.   |
| `twoFactorQrCodeUrl()`                  | `string`           | Returns the `otpauth://` URI for QR code enrollment.            |
| `twoFactorQrCodeSvg()`                  | `string`           | Returns an inline SVG QR code (192 px) as a string.             |

> [!NOTE]
> `twoFactorSecret()` and `recoveryCodes()` decrypt values stored in the database. Ensure `APP_KEY` is stable — rotating the application key without re-encrypting these columns will break 2FA for all users.

---

## <a name="configuration"></a>Configuration

All 2FA settings live under the `two_factor` key in `config/magic-starter.php`.

```php
'two_factor' => [
    'company_name'         => env('APP_NAME', 'Laravel'),
    'recovery_codes_count' => 8,
    'geoip_db_path'        => null,
    'challenge_token_ttl'  => 5,
],
```

| Key                    | Default      | Description                                                                                                    |
|------------------------|--------------|----------------------------------------------------------------------------------------------------------------|
| `company_name`         | `APP_NAME`   | Issuer name shown in the authenticator app next to the account entry. Falls back to `config('app.name')`.     |
| `recovery_codes_count` | `8`          | Number of recovery codes generated when 2FA is enabled or regenerated.                                        |
| `geoip_db_path`        | `null`       | Absolute path to a MaxMind GeoIP2 `.mmdb` file for resolving location data on challenge attempts. Set to `null` to disable geo-resolution. |
| `challenge_token_ttl`  | `5`          | Minutes before the `two_factor_token` issued at login expires. Users must complete the challenge within this window. |

> [!NOTE]
> `company_name` controls what the user sees in Google Authenticator, Authy, or any other TOTP app. Set it to your product name rather than leaving it as the default `Laravel`.
