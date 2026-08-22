# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added

- **`billing` feature: entitlement arbitration for apps sold through more than one payment rail (opt-in, off by default)**: adds `Features::billing()` / `hasBillingFeatures()`, two rail-neutral enums (`BillingProvider`, `PlanStatus`), a `WritesTeamEntitlement` contract bound to a default `WriteTeamEntitlement` action, and a migration adding eight provenance columns to `teams`. The problem it solves: once a subscription can be sold by Stripe on the web AND by a store in an app, two feeders write one entitlement column, and applied in delivery order the last one wins, which makes the truth a property of the internet rather than of what a customer paid for. The action arbitrates instead. **Rule 1, monotonic per rail**: a write from the rail already on record is dropped when its source event is OLDER than `plan_source_event_at`, because stores retry (RevenueCat at 5, 10, 20, 40 and 80 minutes) so a promptly delivered expiry genuinely does arrive before a renewal whose first attempt failed. **Rule 1b, a tie is decided by what the write would do, not by arrival order**: an equal timestamp is not evidence of late delivery, it is an absence of evidence either way, so it applies unless it would leave the team holding LESS than it holds now, which means either a lower tier or the SAME tier on a status that no longer grants. Ranking the tier alone left the second case out: a same-second pair disagreeing only about whether the tier still grants read as no change at all, so the cancellation half landed and a paying team lost access on a coin flip. Dropping ties outright looked safer and was not, because a rail that stamps to the SECOND (Stripe's `created` is a Unix second) emits paired events from one API call inside that second in an order it does not guarantee, and one of the pair carries a tier read from the consumer's own not-yet-resynced state: the loser was routinely a paid upgrade. **Rule 2, a rail may only revoke what it granted**: a cross-rail downgrade is dropped, so a late Stripe cancellation cannot end a store grant during a web-to-store migration; a cross-rail upgrade lands and logs at warning level, because two rails claiming one customer at different tiers means somebody is paying twice and no automated path can pick which subscription to refund. **Rule 2b, a PROJECTED claim may not take over the record of a rail that is still granting**: the test is the WRITER's standing, which is why `write()` takes a required `bool $authoritative` with no default. True means the rail is speaking now, a webhook payload or a re-read of its API, and such a claim takes the record; false means the claim was assembled from a row the rail wrote into your database earlier, and such a claim may refresh what the record says but may not decide who is billing. Rule 2 only ever stopped a cross-rail revocation, so a cross-rail write that was not a downgrade passed and the persist step rewrote `plan_provider` unconditionally, and the damage arrived one step later: with the record naming the new rail, that rail's next revocation was SAME-rail, so rule 2 could no longer see it and rule 1 let it through. The tier could never answer this. An earlier formulation that tested it opened the mirror defect instead, refusing a genuine web-to-store MIGRATION and leaving provenance on the rail that was about to stop billing, whose cancellation was then same-rail and revoked a tier somebody had just started paying for. Consulting no direction also drops a projected cross-rail UPGRADE, which is intended: a projection is stale by construction, so the higher tier it reports is one the database already held, and the rail's own event follows carrying the standing to move the record. The status half of the condition stays load-bearing: `BillingProvider::grants()` is a per-RAIL table, true for every real rail, so gating on it alone would drop a genuine purchase from a team whose LAPSED record on another rail still named the same tier. No rule here is symmetric, deliberately: a write wrongly dropped leaves a customer on a tier a little longer and says so in the log, while a write wrongly applied takes a tier away from somebody who is paying.
- **Tier comparison is consumer-published, via `magic-starter.billing.tier_order`**: rule 2 has to know which of two tiers is higher, and the tier vocabulary belongs to the app rather than to this package, so the action takes the tier as a plain string plan id and ranks it against an ordered array of plan ids the consumer publishes (cheapest first). **An absent or empty `tier_order` makes the comparison undecidable, and the action then treats the write as a non-downgrade and logs the config key by name.** Refusing to compare is safe; guessing revokes a paying customer, and an unpublished catalogue is what a fresh install looks like rather than a misconfiguration.
- **The migration creates the entitlement itself where a consumer has not got it**, not only the provenance around it: `plan` and `plan_status` are added per column when absent, because the default action writes both on every apply and a consumer enabling `billing` without them would hit a throw on its first write. A consumer that already sells something keeps its own columns untouched, and a rollback drops only the eight provenance columns: it cannot tell whether it created `plan`, and dropping the column a paid tier is stored in would not be recoverable. `plan` is a nullable string on purpose, since this package must not name a default tier.
- **Provider and status labels in `lang/en` and `lang/tr`**: the package that defines the vocabulary ships the words for it, resolved through `label()` on each enum. Neither enum is cast on the model, so a status a newer release introduces cannot break reads on an older one.

## [0.0.5] - 2026-07-26

### Added

- **`meta.push_provisioned` on the notification-preferences responses**: both `GET` and `PUT /notification-preferences` now return a `meta.push_provisioned` boolean alongside the matrix, reporting whether the app configured its OneSignal `app_id`. A push preference is offered as soon as the onesignal feature is enabled, but with no app id the channel is dropped from `via()` at send time, so a client rendering the toggle could only promise a delivery that never happened. The flag lets it say so instead. Returned from the write response too, so a client that refreshes its matrix after a save does not lose the heads-up. Purely additive: the `data` shape is unchanged.
- **OneSignal SMS channel (`onesignal-sms`)**: SMS now rides the same `OneSignalChannel` as push through a `builderMethod` constructor parameter (default `toOneSignal`), so one send pipeline backs two drivers: a notification implements `toOneSignal()` for push and `toSms()` for SMS, and the two stay independently toggleable. The driver is registered only when the onesignal feature is enabled and is advertised in `NotificationPreferenceRegistry` under the `sms` alias, never as a default channel: adding it to a notification's channel set is an explicit consumer decision.
- **On-demand SMS subscription registration (`OneSignalSubscriptions`)**: OneSignal only delivers SMS to a user that already carries an SMS subscription, so `ensureSmsSubscription($user)` registers one the first time that user is about to be texted, guarded by the new persisted `users.sms_registered_at` column (added by a migration, cast on `HasNotifications`) so no send after the first issues a redundant API call. Registration is consumer-invoked by design: the package has no "about to SMS this user" hook, so the notification's own `toSms()` calls the helper before it targets the sms channel. A registration failure is reported and returns `false` (leaving the guard unset for a later retry) instead of poisoning the send, and the phone number rides only in the request body, never in a log or an exception message.

### Fixed

- **A zero-recipient OneSignal send is now reported instead of passing silently**: OneSignal answers a send that matched no subscription with an HTTP 200 and an empty notification id. Both the push and the SMS driver now `report()` that as an honest delivery failure without throwing, because unlike a transport error it is not retryable and rethrowing would only poison the queue. Transport exceptions keep their previous behavior (report, then rethrow so the queued job retries). Downstream consumers see one extra reported `RuntimeException` per zero-recipient send, which surfaces the common misconfiguration of a user with no registered subscription.
- **The payload-type error names the builder that actually ran**: the `InvalidArgumentException` for a builder returning the wrong type hardcoded `toOneSignal()` in its message, so an SMS send whose `toSms()` returned the wrong type reported the push builder's name. It now names the configured builder method.
- **User model stub silently skipped on a fresh app**: `magic-starter:install` published the trait-laden `app/Models/User.php` stub via `vendor:publish` without `--force`, which silently skips an existing target. On a fresh Laravel app (which ships `app/Models/User.php`), the default model was kept with none of the Magic Starter traits (`HasTeams`, `TwoFactorAuthenticatable`, etc.) while the installer still printed "DONE", leaving teams/2FA/profile endpoints broken with no warning. The installer now mirrors the default-users-migration heuristic: it overwrites the stock Laravel default model (or with `--force`), preserves an already trait-equipped or customized model, and prints `SKIPPED` plus a warning listing the traits to add when a customized model lacks them.
- **Team switch persistence**: `AuthController::switchTeam` now uses `forceFill(['current_team_id' => ...])->save()` instead of `update()`. `current_team_id` is a system-managed field deliberately kept out of the published User stub's `$fillable`, so the mass-assignment guard silently dropped it: the endpoint returned 200 "Team switched successfully" while `current_team_id` stayed null. Mirrors Jetstream's `switchTeam`. Regression test added with a fillable-restricted user fixture (the prior tests used a fully unguarded fixture and masked the bug).
- **`current_team_id` persistence across every write site (not just `switchTeam`)**: the same mass-assignment bug also affected five other writers that used `$user->update(['current_team_id' => ...])` and silently dropped the value against the guarded User stub: `CreatePersonalTeamListener` (registration left `current_team_id` null even though the personal team was created), `CreateTeam` (a newly created team was not made current), `CreateGuestUser` (guest sessions had no current team), `TeamMemberController::leave` (leaving a team did not reassign the next team), and `TeamController` delete cleanup (deleting the current team did not switch to the next). All now use `forceFill([...])->save()`, consistent with `switchTeam`. Added a `CreatePersonalTeamListener` regression test with the fillable-restricted user fixture.
- **Guest auth persisted `is_guest = false` and a null `device_id`**: `CreateGuestUser` built the new guest via `firstOrCreate(['device_id' => ...], ['is_guest' => true, ...])`, which goes through mass-assignment. `is_guest` and `device_id` are system-managed and intentionally absent from the published User stub's `$fillable` (making `is_guest` mass-assignable would let a crafted payload flag any account as a guest), so both were silently dropped: guests were stored with `is_guest = false` and a null `device_id`, and because the lookup key never persisted, every guest login created a brand-new user instead of returning the existing one. The action now looks the guest up by `device_id` and `forceFill`s the system columns on creation, restoring the `is_guest` flag, the stored `device_id`, and the find-existing idempotency. Regression test added with a fillable-restricted user fixture.
- **SwitchTeamRequest**: `team_id` validation rule now respects `magic-starter.use_uuids` config. When UUIDs are disabled (integer primary keys), the rule is `integer` instead of the previously hardcoded `uuid`, which caused a 422 on every team-switch attempt in integer-PK deployments.

### Changed
- **Documentation**: Clarify in README and installation guide when `MAGIC_STARTER_FRONTEND_URL` (or `--frontend-url`) is needed: set it when email links should open a frontend whose host or scheme differs from `APP_URL`; otherwise email links (verification, password reset, and other email links) point at the backend host instead of the frontend app. Added a troubleshooting section covering the symptom, the solution, and three ways to configure it.
- **Documentation**: Rewrite installation guide to lead with `php artisan magic-starter:install` command as the recommended path, with non-interactive `--all`, `--features`, `--uuid`, `--no-uuid`, `--route-prefix`, and `--frontend-url` options for CI/CD. Demote manual `vendor:publish` + `migrate` steps to an "Advanced" section with a caveat that vendor:publish does not generate ordered migration timestamps.

## [0.0.4] - 2026-03-25

### ✨ Features
- **Install Command**: Publish User model stub with all 9 required traits (ConditionallyUsesUuids, HasApiTokens, HasFactory, HasGuestSupport, HasNotifications, HasProfilePhoto, HasTeams, MustVerifyEmail, TwoFactorAuthenticatable)
- **Install Command**: Publish TeamPolicy stub when teams feature is selected
- **Install Command**: Publish language files (en/tr team translations)
- **Install Command**: Publish UserFactory stub with `guest()`, `withPhone()`, and `unverified()` states
- **Install Command**: Detect and replace Laravel's default users migration when it conflicts with UUID primary keys

## [0.0.3] - 2026-03-25

### ✨ Features
- **Boost Skill**: Add community support (GitHub star) and issue reporting sections to magic-starter-development skill

## [0.0.2] - 2026-03-25

### 🐛 Bug Fixes
- **URLs**: Update website URLs from `wind.fluttersdk.com` to `magic.fluttersdk.com`

## [0.0.1] - 2026-03-25

### ✨ Core Features
- **Authentication**: Register, login, logout, current user via Sanctum tokens
- **Social Login**: Laravel Socialite integration (access_token and authorization_code flows)
- **Password Reset**: Forgot + reset via Laravel's Password broker
- **Teams**: Team CRUD with authorization gates
- **Team Members**: Add, update role, remove, leave
- **Team Invitations**: Token-based invitation system
- **Profile Management**: Update profile, update password, delete account
- **Profile Photos**: Upload/delete with configurable storage disk
- **Session Management**: List, revoke one, revoke others via Sanctum tokens
- **Two-Factor Authentication**: Enable/disable 2FA with QR code, OTP confirm, recovery codes
- **Notifications**: List, mark read/unread, preference matrix
- **Email Verification**: Send verification, verify via signed URL
- **Guest Auth**: OTP-based phone login
- **Newsletter**: Subscribe/unsubscribe
- **Feature Toggles**: 12 opt-in features (teams, profilePhotos, sessions, socialLogin, etc.)
- **Dynamic Model Resolution**: User, Team, Membership models via `MagicStarter::*Model()`
- **Publishable Assets**: Config, migrations, action stubs, model stubs, translations
- **Install Command**: `magic-starter:install` Artisan command with interactive setup
- **18 Action Contracts**: With publishable stub implementations

### 📚 Documentation
- **README**: Full rewrite to match Magic ecosystem format with badges, features table, quick start
- **doc/ folder**: Comprehensive documentation (installation, configuration, authentication, profile, teams, notifications, 2FA, architecture)

### 🔧 Improvements
- **Publishing**: GitHub Actions workflows for CI/CD and tag-triggered releases
- **Templates**: GitHub issue templates for bug reports and feature requests
