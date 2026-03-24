# Two-Factor Authentication

## Where to Find It

- Actions: `src/Actions/EnableTwoFactorAuthentication.php`, `ConfirmTwoFactorAuthentication.php`, `DisableTwoFactorAuthentication.php`, `GenerateNewRecoveryCodes.php`
- Contracts: `src/Contracts/EnablesTwoFactorAuthentication.php`, `ConfirmsTwoFactorAuthentication.php`, `DisablesTwoFactorAuthentication.php`, `GeneratesNewRecoveryCodes.php`
- Trait: `src/Traits/TwoFactorAuthenticatable.php` — applied to the user model
- Provider: `src/Support/TwoFactorAuthenticationProvider.php` — wraps PragmaRX/Google2FA
- Controllers: `src/Http/Controllers/TwoFactorAuthenticationController.php`, `TwoFactorChallengeController.php`, `TwoFactorRecoveryCodeController.php`
- Config: `config/magic-starter.php` — `two_factor` key

## What to Watch For

### Enable flow generates secret but does not activate 2FA

`EnablesTwoFactorAuthentication::enable(mixed $user): array` generates a base32 TOTP secret via `TwoFactorAuthenticationProvider::generateSecretKey()`, produces `recovery_codes_count` (default 8) recovery codes formatted as `random(10)-random(10)`, stores both encrypted on the user model, and clears `two_factor_confirmed_at`. It returns `secret`, `qr_url` (otpauth:// URI), and `recovery_codes` — all three are needed for the enrollment UI. Enable alone does not activate 2FA; `two_factor_confirmed_at` being null means 2FA is pending confirmation.

### Confirm verifies TOTP and sets the activation timestamp

`ConfirmsTwoFactorAuthentication::confirm(mixed $user, string $code): void` decrypts the stored secret via `$user->twoFactorSecret()`, verifies the TOTP code through the provider (window of 1 interval for clock drift), and sets `two_factor_confirmed_at`. Throws `ValidationException` on missing secret or invalid code. Only after confirmation does `hasEnabledTwoFactorAuthentication()` return true.

### Disable nulls all three columns without verification

`DisablesTwoFactorAuthentication::disable(mixed $user): void` nulls all three columns: `two_factor_secret`, `two_factor_recovery_codes`, and `two_factor_confirmed_at`. No verification step — caller must assert the user is authenticated before invoking.

### Recovery code regeneration replaces the full set

`GeneratesNewRecoveryCodes::generate(mixed $user): array` regenerates the full set using the same count config, re-encrypts, saves, and returns the plaintext array. When a single recovery code is used during challenge, `replaceRecoveryCode()` on the trait blanks that specific code (making it unusable) — it does not regenerate it. The stored value is `encrypt(json_encode($codes))` — decrypt then JSON-decode to read.

### Challenge flow uses a short-lived encrypted token

When login detects 2FA is enabled, it returns an encrypted `two_factor_token` containing `user_id` and `expires_at` (TTL from `challenge_token_ttl` config, in minutes). The challenge endpoint decrypts the token, checks expiry, resolves the user, then validates either a TOTP `code` or a `recovery_code`. On success it calls `createAuthToken` and returns the standard authenticated response.

### Config and storage details

- `two_factor_confirmed_at` is the authoritative gate — secret present but null timestamp means enrollment is incomplete.
- Both `secret` and `recovery_codes` are stored via Laravel's `encrypt()` — never compare or read raw column values directly.
- `company_name` defaults to `APP_NAME` and controls the issuer label in authenticator apps; set it explicitly via `magic-starter.two_factor.company_name`.
- `geoip_db_path` is optional — null disables location resolution on challenge attempts without breaking the flow.
- The challenge token is short-lived; `challenge_token_ttl` (minutes) must be long enough for the user to switch apps but short enough to limit exposure.
