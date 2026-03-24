{{-- magic-starter-laravel AI guidelines --}}
{{-- Source: fluttersdk/magic-starter-laravel --}}

## magic-starter-laravel

A Laravel API-only package providing auth, teams, 2FA, profile, sessions, notifications, locale/timezone, newsletter, and OTP modules. All behavior is driven by feature flags, contract-action bindings, and dynamic model resolution — never hardcoded class names or inline logic.

### Critical Rules

| Rule | Constraint |
|------|-----------|
| Model resolution | ALWAYS use `MagicStarter::userModel()`, `::teamModel()`, `::membershipModel()`, `::teamInvitationModel()` — NEVER hardcode `App\Models\*` |
| Feature gates | ALWAYS check `Features::enabled()` or `Features::has*()` before any conditional feature logic |
| Contract-action | Controllers ALWAYS inject contracts via `app(Contract::class)` — NEVER instantiate action classes directly |
| Thin controllers | NEVER put business logic in controllers — delegate entirely to injected action contracts |
| UUID migrations | ALWAYS use `MigrationHelper::primaryKey($table)`, `MigrationHelper::foreignKey($table, 'col')`, `MigrationHelper::morphColumns($table, 'name')` — NEVER raw `$table->id()`, `$table->uuid()`, or `$table->morphs()` |
| Overriding actions | ALWAYS bind overrides via `$this->app->singleton(Contract::class, CustomAction::class)` in `AppServiceProvider::register()` — NEVER re-bind in `boot()` |
| Phone numbers | E.164 format only (`+[country][number]`) — validate with `new E164Phone` rule, NEVER regex strings |
| Request resources | ALWAYS use `$this->when(Features::has*(), fn () => ...)` for feature-conditional fields in API resources |

### Architecture at a Glance

| Layer | Convention | Concrete Example |
|-------|-----------|-----------------|
| Model resolution | `MagicStarter::*Model()` static registry | `MagicStarter::userModel()`, `::teamModel()` |
| Feature checks | `Features::has*()` / `Features::enabled(string)` | `Features::hasTeamFeatures()`, `Features::enabled('sessions')` |
| Action contracts | `Contracts/` interface → `Actions/` implementation | `CreatesUsers` → `CreateUser` |
| Auth identity | `Features::emailIdentity()` / `::phoneIdentity()` | Reads `magic-starter.auth.email/phone` config |
| Validation | `Validator::make($input, $rules)->validate()` inline in actions | NOT in constructors or controllers |
| Migration PK | `MigrationHelper::primaryKey($table)` | UUID or int based on `magic-starter.use_uuids` |
| Migration FK | `MigrationHelper::foreignKey($table, 'user_id')->constrained()->cascadeOnDelete()` | UUID or int matched automatically |
| Morph columns | `MigrationHelper::morphColumns($table, 'notifiable')` | `uuidMorphs` or `morphs` based on config |
| Team membership | `->using(MagicStarter::membershipModel())->withPivot('role')` | Pivot always via `membershipModel()` |
| Password reset URL | Customized in `MagicStarterServiceProvider::boot()` | NEVER override separately |

### Available Feature Flags

All flags are string constants on `Features::class` — add to `config('magic-starter.features')` array to enable:

| Method | String value | `has*()` check |
|--------|-------------|----------------|
| `Features::teams()` | `'teams'` | `Features::hasTeamFeatures()` |
| `Features::profilePhotos()` | `'profile-photos'` | `Features::hasProfilePhotoFeatures()` |
| `Features::sessions()` | `'sessions'` | `Features::hasSessionFeatures()` |
| `Features::socialLogin()` | `'social-login'` | `Features::hasSocialLoginFeatures()` |
| `Features::twoFactorAuthentication()` | `'two-factor-authentication'` | `Features::hasTwoFactorAuthenticationFeatures()` |
| `Features::extendedProfile()` | `'extended-profile'` | `Features::hasExtendedProfileFeatures()` |
| `Features::notifications()` | `'notifications'` | `Features::hasNotificationFeatures()` |
| `Features::guestAuth()` | `'guest-auth'` | `Features::hasGuestAuthFeatures()` |
| `Features::phoneOtp()` | `'phone-otp'` | `Features::hasPhoneOtpFeatures()` |
| `Features::emailVerification()` | `'email-verification'` | `Features::hasEmailVerificationFeatures()` |
| `Features::newsletterSubscription()` | `'newsletter-subscription'` | `Features::hasNewsletterSubscriptionFeatures()` |
| `Features::timezones()` | `'timezones'` | `Features::hasTimezoneFeatures()` |

### Contract Bindings (default, all overridable)

Bound in `MagicStarterServiceProvider::register()` via `$this->app->bind()`:

- `CreatesUsers` → `CreateUser`
- `UpdatesUserProfiles` → `UpdateUserProfile`
- `UpdatesUserPasswords` → `UpdateUserPassword`
- `DeletesUsers` → `DeleteUser`
- `CreatesTeams` → `CreateTeam` / `UpdatesTeams` → `UpdateTeam` / `DeletesTeams` → `DeleteTeam`
- `AddsTeamMembers` → `AddTeamMember` / `RemovesTeamMembers` → `RemoveTeamMember`
- `InvitesTeamMembers` → `InviteTeamMember` / `UpdatesTeamMemberRoles` → `UpdateTeamMemberRole`
- `CreatesGuestUsers` → `CreateGuestUser`
- `SendsOtpCodes` → `LogOtpProvider` / `VerifiesOtpCodes` → `CacheOtpVerifier`
- `EnablesTwoFactorAuthentication`, `ConfirmsTwoFactorAuthentication`, `DisablesTwoFactorAuthentication`, `GeneratesNewRecoveryCodes`

### Common Gotchas

1. **Guest users**: `email` and `password` may both be `null` — always null-check before using.
2. **Optional trait methods**: `method_exists($user, 'methodName')` required before calling any method provided by optional traits (`HasTeams`, `HasGuestSupport`, `TwoFactorAuthenticatable`).
3. **Stale Composer classmap**: `resolveConcreteModel()` wraps `class_exists()` in try/catch — run `composer dump-autoload` after any stub publish/delete cycle.
4. **Team membership is a pivot model**: Use `->using(MagicStarter::membershipModel())` on all `BelongsToMany` team relations — a raw pivot will lose the role column.
5. **Sanctum migrations disabled**: The package calls `Sanctum::ignoreMigrations()` — `PersonalAccessToken` model includes device-info columns; never run vendor Sanctum migrations separately.
6. **Password reset URL**: Customized via `ResetPassword::createUrlUsing()` in `ServiceProvider::boot()` — a second `createUrlUsing()` call in the app will silently win and break the link.
7. **Request user resolver**: After setting the authenticated user on a request, call `$request->setUserResolver()` before returning the response from a controller.
8. **Model config source order**: `userModel()` resolves in this order: `MagicStarter::useUserModel()` → `magic-starter.models.user` → `auth.providers.users.model`. All other models use only their own config key.

### Overriding Actions

@verbatim
<code-snippet name="Override a contract action in AppServiceProvider" lang="php">
// app/Providers/AppServiceProvider.php — register(), not boot()
public function register(): void
{
    $this->app->singleton(
        \FlutterSdk\MagicStarter\Contracts\CreatesUsers::class,
        \App\Actions\CustomCreateUser::class,
    );
}
</code-snippet>
@endverbatim

### Config Keys Reference

| Key | Default | Purpose |
|-----|---------|---------|
| `magic-starter.use_uuids` | `true` | UUID vs integer primary keys (affects all migrations) |
| `magic-starter.features` | `[]` | Active feature flags array |
| `magic-starter.auth.email` | `true` | Email identity enabled |
| `magic-starter.auth.phone` | `false` | Phone identity enabled |
| `magic-starter.models.user` | env / `auth.providers.users.model` | User model class |
| `magic-starter.models.team` | `Team::class` | Team model class |
| `magic-starter.models.membership` | `TeamUser::class` | Pivot model class |
| `magic-starter.models.team_invitation` | `TeamInvitation::class` | Invitation model class |
| `magic-starter.route_prefix` | `'api/v1'` | Route prefix for all package endpoints |
| `magic-starter.token_expiration_minutes` | `null` | Sanctum token TTL (null = never expires) |
| `magic-starter.two_factor.challenge_token_ttl` | `5` | 2FA challenge window in minutes |
