# Overriding & Customization

## Where to Find It

- `src/MagicStarterServiceProvider.php` — 18 `bind()` calls, one per contract
- `src/Contracts/` — one interface per action; these are the override surface
- `src/MagicStarter.php` — `useUserModel()`, `useTeamModel()`, `ignoreRoutes()`
- `config/magic-starter.php` — feature toggles, model class names, auth strategy

## What to Watch For

### Override actions via singleton binding

Call `$this->app->singleton(Contract::class, CustomAction::class)` inside `AppServiceProvider::register()`. The package registers actions with `bind()`, so a consumer `singleton()` wins by priority. Only override what differs — leave the rest bound to defaults.

### Override models via config or runtime

Set `magic-starter.models.user` in config (or `MAGIC_STARTER_USER_MODEL` env) for the user model. For team, membership, and team-invitation models, use the `models` array keys. Runtime registration (`MagicStarter::useUserModel(CustomUser::class)`) takes precedence over config and is the correct approach in test `setUp()`.

### Disable package routes and register custom

Call `MagicStarter::ignoreRoutes()` inside `AppServiceProvider::register()` before the package boots. Then define replacement routes in your own route file. The package's rate limiters and middleware remain available.

### Publish and customize config

Run `php artisan vendor:publish --tag=magic-starter-config` to copy the config file. Enable features by uncommenting entries in the `features` array; disable by removing them. Feature flags gate both routes and resource fields — toggling a feature affects the entire layer.

### Consumer pattern: minimal overrides work best

In practice, most apps override nothing. Extend models with domain relations (e.g., `Team` gains `statusPages()`) rather than replacing them. Register custom notification types in `NotificationPreferenceRegistry` from a service provider `boot()`. Override an action only when business rules differ — not to log or decorate; use observers or events for side effects instead.

## Full Contract Reference

| Contract | Default Action | Method Signature |
|---|---|---|
| `CreatesUsers` | `CreateUser` | `create(array $input): Authenticatable` |
| `UpdatesUserProfiles` | `UpdateUserProfile` | `update(Authenticatable $user, array $input): void` |
| `UpdatesUserPasswords` | `UpdateUserPassword` | `update(Authenticatable $user, array $input): void` |
| `DeletesUsers` | `DeleteUser` | `delete(Authenticatable $user): void` |
| `CreatesTeams` | `CreateTeam` | `create(Authenticatable $user, array $input): Model` |
| `UpdatesTeams` | `UpdateTeam` | `update(Authenticatable $user, Model $team, array $input): void` |
| `DeletesTeams` | `DeleteTeam` | `delete(Model $team): void` |
| `AddsTeamMembers` | `AddTeamMember` | `add(Authenticatable $user, Model $team, string $email, string $role): void` |
| `RemovesTeamMembers` | `RemoveTeamMember` | `remove(Authenticatable $user, Model $team, Model $teamMember): void` |
| `InvitesTeamMembers` | `InviteTeamMember` | `invite(Authenticatable $user, Model $team, string $email, string $role): Model` |
| `UpdatesTeamMemberRoles` | `UpdateTeamMemberRole` | `update(Authenticatable $user, Model $team, Model $teamMember, string $role): void` |
| `TransfersTeamOwnership` | `TransferTeamOwnership` | `transfer(Authenticatable $actor, Model $team, Model $newOwner): void` |
| `CancelsTeamInvitations` | `CancelTeamInvitation` | `cancel(Authenticatable $actor, Model $invitation): void` |
| `ResendsTeamInvitations` | `ResendTeamInvitation` | `resend(Authenticatable $actor, Model $invitation): void` |
| `CancelsUserDeletion` | `CancelUserDeletion` | `cancel(Authenticatable $user): bool` |
| `RevokesApiTokens` | `RevokeApiTokens` | `revoke(Authenticatable $user, ?string $tokenId = null): void` |
| `CreatesGuestUsers` | `CreateGuestUser` | `create(array $input): Authenticatable` |
| `SendsOtpCodes` | `LogOtpProvider` | `send(string $phone, string $code): void` |
| `VerifiesOtpCodes` | `CacheOtpVerifier` | `verify(string $phone, string $code): bool` |
| `EnablesTwoFactorAuthentication` | `EnableTwoFactorAuthentication` | `enable(mixed $user): array` |
| `ConfirmsTwoFactorAuthentication` | `ConfirmTwoFactorAuthentication` | `confirm(mixed $user, string $code): void` |
| `DisablesTwoFactorAuthentication` | `DisableTwoFactorAuthentication` | `disable(mixed $user): void` |
| `GeneratesNewRecoveryCodes` | `GenerateNewRecoveryCodes` | `generate(mixed $user): array` |

### Team owner guards live in the actions

`RemoveTeamMember` refuses the owner (`owner_not_removable`, or `owner_cannot_leave` when the owner asks to go) and `UpdateTeamMemberRole` refuses to change the owner's role (`owner_role_locked`) or to hand out `owner` (`role_not_assignable`), each as a `ValidationException` with that `code`. The HTTP API maps them back to 403 through the public `ensureRemovable()` and `ensureAssignable()`, so the API holds whatever you bind. The admin panel and `PurgeDeletedUsersCommand` call the contracts, so a replacement of either action has to keep the guard (call the static helper from your override). Ownership itself moves only through `TransfersTeamOwnership`.
