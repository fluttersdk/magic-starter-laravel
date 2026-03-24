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

Guest users may additionally pass `password` and `password_confirmation` to upgrade their account in a single call. The password must be at least 8 characters with letters, numbers, and mixed case. When a guest provides an email/phone and a password, they are automatically converted to a full user (`is_guest` becomes `false`).

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
        "profile_photo_url": "https://ui-avatars.com/api/?name=J%20D&color=FFFFFF&background=009E60",
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

The `current_password` is verified against the stored hash via `Hash::check()`. Guest users who have no password set yet can skip the `current_password` field.

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

Permanently deletes the authenticated user's account.

| Property | Value |
|----------|-------|
| **Method** | `POST` or `DELETE` |
| **URI** | `user/` |
| **Auth** | `auth:sanctum` |
| **Controller** | `ProfileController@destroy` |
| **Request** | `DeleteAccountRequest` |
| **Response** | `204 No Content` |

### Fields

| Field | Type | Rules |
|-------|------|-------|
| `password` | string | Required (optional for guest users without a password) |

The password is verified via `Hash::check()` before proceeding. Guest users without a password can delete their account without providing one.

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

`204 No Content` -- empty body on success.

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

The photo is stored publicly on the disk configured by `magic-starter.profile_photo_disk` (falls back to `filesystems.default`, typically `public`). The storage path is controlled by `magic-starter.profile_photo_path` (default: `profile-photos`). If a previous photo exists, it is deleted from the disk before storing the new one.

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

Removes the photo file from disk and sets `profile_photo_path` to `null`. The `profile_photo_url` in the response falls back to the default avatar URL (see [HasProfilePhoto Trait](#has-profile-photo-trait)).

#### Request Example

```http
DELETE /user/profile-photo
Authorization: Bearer {token}
```

---

## <a name="has-profile-photo-trait"></a>HasProfilePhoto Trait

The `HasProfilePhoto` trait (`FlutterSdk\MagicStarter\Traits\HasProfilePhoto`) provides the `profile_photo_url` accessor used by `UserResource`.

### profilePhotoUrl Accessor

`getProfilePhotoUrlAttribute(): string`

If `profile_photo_path` is set, returns the public URL from the configured filesystem disk (`magic-starter.profile_photo_disk`). If the disk driver supports the `url()` method, it generates the full URL; otherwise it returns the raw path.

### defaultProfilePhotoUrl

`defaultProfilePhotoUrl(): string`

When no custom photo is uploaded, generates a fallback avatar URL using [ui-avatars.com](https://ui-avatars.com). The initials are extracted from the user's `name` (first letter of each word). The base URL is configurable via `magic-starter.ui_avatars_url`.

Default format:

```
https://ui-avatars.com/api/?name=J%20D&color=FFFFFF&background=009E60
```

### Configuration

| Config Key | Default | Description |
|------------|---------|-------------|
| `magic-starter.profile_photo_disk` | `filesystems.default` | Filesystem disk for photo storage |
| `magic-starter.profile_photo_path` | `profile-photos` | Storage directory within the disk |
| `magic-starter.ui_avatars_url` | `https://ui-avatars.com/api/` | Base URL for fallback avatar generation |

---

## <a name="email-verification"></a>Email Verification

Available when the `email-verification` feature is enabled.

### Verify Email Address

| Property | Value |
|----------|-------|
| **Method** | `GET` |
| **URI** | `email/verify/{id}/{hash}` |
| **Middleware** | `signed` (no auth required) |
| **Route Name** | `verification.verify` |
| **Controller** | `EmailVerificationController@verify` |

This is a public endpoint protected by a signed URL (not `auth:sanctum`). The `{hash}` parameter is a SHA-1 of the user's email address. The link is validated to ensure it matches the user's current email, preventing reuse after an email change.

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
        "timezones": true
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

Returns the current subscription status keyed to the user's email.

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

Subscribing creates a `NewsletterSubscriber` record (via `firstOrCreate`) with `source` set to `profile` and `is_active` set to `true`. Unsubscribing sets `is_active` to `false` without deleting the record. Requires the user to have an email address; returns `400` otherwise.

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
                "platform": "macOS"
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
            "user_agent": "Dart/3.2 (dart:io)",
            "agent": {
                "browser": "Dart",
                "platform": "Unknown"
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
