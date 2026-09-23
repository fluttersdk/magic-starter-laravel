# Profile Management

## Where to Find It

- `UpdateUserProfile`: profile, email, phone, timezone, locale updates; guest conversion
- `UpdateUserPassword`: dedicated password change with current-password verification
- `DeleteUser`: token revocation, photo deletion, user removal
- `HasProfilePhoto`: profile photo URL resolution, null when none is stored
- `SessionAgent`: device and browser detection from user agent strings

## What to Watch For

### Profile Updates and Email Changes

Email changes reset `email_verified_at` to null and trigger verification re-queue. Guest users may set password during single-call profile upgrade. Change detection uses original email captured before update; verification gates behind feature flag.

### Guest User Conversion

Guest users convert to regular users when they have both credentials (email or phone) AND a password. Conversion happens automatically during profile or password updates. Check `is_guest` flag before attempting password verification on guests without passwords.

### Profile Photos

The `HasProfilePhoto` trait provides `profile_photo_url` accessor. Stored photos resolve via configured disk; a missing photo answers null, and the client draws its own initials. Disk defaults to `magic-starter.profile_photo_disk` or Laravel's default filesystem.

### Sessions and Device Detection

`SessionAgent::parse()` extracts browser, platform, and mobile flag from user agent. Sessions endpoint lists devices with detection results. Token revocation during deletion wipes all API access; incomplete token cleanup leaves session orphans.

### Password Changes

Non-guest users require `current_password` validation; guest users without passwords skip it. Password hashing happens inline; confirmation field stripped before saving. Guest password-first flow auto-converts if email/phone present.

### User Deletion Order

Strict sequence: revoke tokens first, delete photos second, delete user last. Storage disk detection uses `magic-starter.profile_photo_disk` with fallback to default. Out-of-order deletion leaves orphaned photos or inaccessible tokens.
