# Authentication

## Where to Find It

- src/Actions/CreateUser.php — registration flow, newsletter, email verification
- src/Actions/CreateGuestUser.php — guest user creation keyed by device_id
- src/Actions/LogOtpProvider.php — default OTP sender (logs; override in production)
- src/Actions/CacheOtpVerifier.php — OTP verifier using cache with one-time pull
- src/Http/Controllers/Concerns/AuthenticatesUsers.php — token creation and response shape
- src/Http/Requests/LoginRequest.php — identity-strategy-aware login rules
- src/Http/Requests/RegisterRequest.php — identity-strategy-aware registration rules
- src/Features.php — emailIdentity(), phoneIdentity(), all feature flag checks

## What to Watch For

### Identity strategy is config-driven (email, phone, or dual)

- Features::emailIdentity() reads magic-starter.auth.email (default true); Features::phoneIdentity() reads magic-starter.auth.phone (default false).
- Both LoginRequest and RegisterRequest call these methods at rule-build time, so the validation surface changes at runtime based on config — not code.
- When both are enabled, email uses required_without:phone and phone uses required_without:email; the user must supply at least one.
- Phone values must pass the E164Phone rule in all cases.

### Guest users have nullable email AND password

- CreateGuestUser hardcodes email: null and password: null in attributes; is_guest is set to true.
- Lookup is by device_id via firstOrCreate — re-calling with the same device_id returns the existing guest rather than creating a duplicate.
- Conversion to a full account requires the consumer to supply email (or phone) plus password and call CreatesUsers::create(); guard all email/password reads with null checks.

### Social login pre-verifies email

- CreateUser accepts email_verified_at as an optional validated field when extended-profile is enabled.
- Passing a non-null email_verified_at suppresses the sendEmailVerificationNotification() call because the condition requires email_verified_at === null.
- Social auth controllers set this field before delegating to CreatesUsers, so the verification email is never sent for social registrations.

### OTP flow uses two separate contracts

- SendsOtpCodes is bound to LogOtpProvider by default; it logs the code via Log::info and does nothing else — override this binding for any real SMS/push delivery.
- VerifiesOtpCodes is bound to CacheOtpVerifier; it calls Cache::pull('otp_' . $phone), which consumes the entry on first read, making codes single-use by design.
- The OTP TTL is controlled by however long the code lives in cache before CacheOtpVerifier reads it; the verifier itself does not set the TTL.

### Auth response pattern (AuthenticatesUsers trait)

- createAuthToken() calls $user->createToken('auth_token') and then force-fills ip_address and user_agent onto the resulting PersonalAccessToken — requires the custom token model with those columns.
- authenticatedResponse() sets the user resolver on both the injected $request and the container-resolved app('request') so that nested resources (e.g. TeamResource) can call $request->user() before Sanctum middleware has run.
- The JSON envelope is always {data: {user, token}, message}; no variations exist.
