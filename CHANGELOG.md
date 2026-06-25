# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Fixed

- **User model stub silently skipped on a fresh app**: `magic-starter:install` published the trait-laden `app/Models/User.php` stub via `vendor:publish` without `--force`, which silently skips an existing target. On a fresh Laravel app (which ships `app/Models/User.php`), the default model was kept with none of the Magic Starter traits (`HasTeams`, `TwoFactorAuthenticatable`, etc.) while the installer still printed "DONE", leaving teams/2FA/profile endpoints broken with no warning. The installer now mirrors the default-users-migration heuristic: it overwrites the stock Laravel default model (or with `--force`), preserves an already trait-equipped or customized model, and prints `SKIPPED` plus a warning listing the traits to add when a customized model lacks them.
- **Team switch persistence**: `AuthController::switchTeam` now uses `forceFill(['current_team_id' => ...])->save()` instead of `update()`. `current_team_id` is a system-managed field deliberately kept out of the published User stub's `$fillable`, so the mass-assignment guard silently dropped it: the endpoint returned 200 "Team switched successfully" while `current_team_id` stayed null. Mirrors Jetstream's `switchTeam`. Regression test added with a fillable-restricted user fixture (the prior tests used a fully unguarded fixture and masked the bug).
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
