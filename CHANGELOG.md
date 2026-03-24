# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

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
