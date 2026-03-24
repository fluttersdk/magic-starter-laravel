---
name: magic-starter-development
description: "Use this skill when working with magic-starter-laravel in a Laravel project. Covers authentication (email/phone/social/guest/OTP), team management (create/invite/roles/membership), two-factor authentication (TOTP/recovery codes), user profiles (photo/locale/timezone/sessions), notification preferences (channel gating/registry), and the contract-action architecture pattern. Also covers model resolution, feature flags, UUID support, and migration helpers. Do not use for generic Laravel auth without magic-starter, standalone Sanctum setup, or Laravel Fortify."
license: MIT
metadata:
  author: fluttersdk
---
@php /** @var \Laravel\Boost\Install\GuidelineAssist $assist */ @endphp

# Magic Starter Development

## Documentation

Use `search-docs` for detailed magic-starter patterns and documentation.

For deeper guidance, read the relevant reference file before implementing:

- `references/auth.md` — registration, login, social auth, guest users, OTP, identity strategy
- `references/teams.md` — team CRUD, membership, invitations, roles, pivot model, personal teams
- `references/two-factor.md` — enable/confirm/disable 2FA, recovery codes, challenge flow
- `references/profile.md` — profile updates, photos, sessions, password changes, account deletion
- `references/notifications.md` — preference registry, channel gating, OneSignal routing
- `references/models-and-migrations.md` — traits, relations, UUID support, MigrationHelper, casts
- `references/overriding.md` — customizing actions, models, routes, config; consumer patterns

## Contract-Action Pattern

Controllers inject contracts; the ServiceProvider binds them to default actions. Consumers override any binding in their `AppServiceProvider`.

@boostsnippet("Controller — inject contract, dispatch action, return response", "php")
final class ProfileController extends Controller
{
    public function update(UpdateUserProfileRequest $request): JsonResponse
    {
        $action = app(UpdatesUserProfiles::class);
        $user   = $action->update($request->user(), $request->validated());

        return response()->json(new UserResource($user));
    }
}
@endboostsnippet

@boostsnippet("Override a contract binding in AppServiceProvider", "php")
// In App\Providers\AppServiceProvider::register()
$this->app->singleton(CreatesUsers::class, \App\Actions\CreateUser::class);
@endboostsnippet

## Feature Flags

Check `Features::enabled()` or the dedicated gate methods before any feature-specific logic.

| Feature | Gate method | Config key |
|---|---|---|
| Teams | `Features::hasTeamFeatures()` | `teams` |
| Profile photos | `Features::hasProfilePhotoFeatures()` | `profile-photos` |
| Sessions | `Features::hasSessionFeatures()` | `sessions` |
| Social login | `Features::hasSocialLoginFeatures()` | `social-login` |
| Newsletter | `Features::hasNewsletterSubscriptionFeatures()` | `newsletter-subscription` |
| Extended profile | `Features::hasExtendedProfileFeatures()` | `extended-profile` |
| Notifications | `Features::hasNotificationFeatures()` | `notifications` |
| Two-factor auth | `Features::hasTwoFactorAuthenticationFeatures()` | `two-factor-authentication` |
| Email verification | `Features::hasEmailVerificationFeatures()` | `email-verification` |
| Guest auth | `Features::hasGuestAuthFeatures()` | `guest-auth` |
| Phone OTP | `Features::hasPhoneOtpFeatures()` | `phone-otp` |
| Timezones | `Features::hasTimezoneFeatures()` | `timezones` |

Enable features in `config/magic-starter.php` under the `features` array.

## Model Resolution

Never hardcode model class names. Always resolve via `MagicStarter::*Model()`.

@boostsnippet("Model relations — dynamic resolution + membership pivot", "php")
// In a relation definition inside any model
public function teams(): BelongsToMany
{
    return $this->belongsToMany(MagicStarter::teamModel())
        ->using(MagicStarter::membershipModel())
        ->withPivot('role');
}

// Runtime override (e.g. in tests or a custom ServiceProvider)
MagicStarter::useUserModel(\App\Models\User::class);
MagicStarter::useTeamModel(\App\Models\Team::class);
@endboostsnippet

## Verification

Before marking any task complete, confirm:

1. No hardcoded model class names — every reference goes through `MagicStarter::*Model()`.
2. Feature-gated logic is wrapped in the appropriate `Features::has*()` call.
3. New actions implement exactly one contract from `Contracts/` and are bound in the ServiceProvider.
4. Migrations use `MigrationHelper::primaryKey()` and `MigrationHelper::foreignKey()` — never raw `id()`.
