# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-02-24

### Added

- Authentication (register, login, logout, current user) via Sanctum tokens
- Social login via Laravel Socialite (access_token and authorization_code flows)
- Password reset (forgot + reset) via Laravel's Password broker
- Team CRUD with authorization gates
- Team member management (add, update role, remove, leave)
- Token-based team invitation system
- Profile management (update profile, update password, delete account)
- Profile photo upload/delete with configurable storage disk
- Session management (list, revoke one, revoke others) via Sanctum tokens
- Feature toggle system (teams, profilePhotos, sessions, socialLogin)
- Dynamic model resolution for User and Team models
- Publishable config, migrations, and action stubs
- `magic-starter:install` Artisan command
- 10 action contracts with publishable stub implementations
- Comprehensive PHPDoc coverage following Laravel conventions