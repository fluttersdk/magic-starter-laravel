<?php

use FlutterSdk\MagicStarter\Models\Team;
use FlutterSdk\MagicStarter\Models\TeamInvitation;
use FlutterSdk\MagicStarter\Models\TeamUser;

return [
    /*
    |--------------------------------------------------------------------------
    | Primary Key Strategy
    |--------------------------------------------------------------------------
    |
    | Determines whether the package uses UUID primary keys or standard
    | auto-incrementing integer IDs. When true, all package migrations
    | use uuid() columns and foreignUuid() references. When false,
    | standard id() and foreignId() are used instead.
    |
    | This is set automatically during installation based on your
    | existing database schema, but can be changed manually.
    |
    */

    'use_uuids' => true,

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Enable or disable package features. Each feature is a string constant
    | defined on the Features class. Disabled features are simply omitted
    | from this array.
    |
    */

    'features' => [
        // \FlutterSdk\MagicStarter\Features::twoFactorAuthentication(),
        // \FlutterSdk\MagicStarter\Features::teams(),
        // \FlutterSdk\MagicStarter\Features::profilePhotos(),
        // \FlutterSdk\MagicStarter\Features::sessions(),
        // \FlutterSdk\MagicStarter\Features::socialLogin(),
        // \FlutterSdk\MagicStarter\Features::newsletterSubscription(),
        // \FlutterSdk\MagicStarter\Features::extendedProfile(),
        // \FlutterSdk\MagicStarter\Features::notifications(),
        // \FlutterSdk\MagicStarter\Features::guestAuth(),
        // \FlutterSdk\MagicStarter\Features::phoneOtp(),
        // \FlutterSdk\MagicStarter\Features::emailVerification(),
        // \FlutterSdk\MagicStarter\Features::timezones(),
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | The URL of the frontend application that will consume the API provided by
    | this package. This is used when sending email invitations to teams, so
    | that the links in the email point to the correct frontend application.
    |
    */

    'frontend_url' => env('MAGIC_STARTER_FRONTEND_URL'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default Eloquent models used by the package.
    |
    */

    'models' => [
        'user' => env('MAGIC_STARTER_USER_MODEL'),
        'team' => env('MAGIC_STARTER_TEAM_MODEL', Team::class),
        'membership' => env('MAGIC_STARTER_MEMBERSHIP_MODEL', TeamUser::class),
        'team_invitation' => env('MAGIC_STARTER_TEAM_INVITATION_MODEL', TeamInvitation::class),
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale & Timezone
    |--------------------------------------------------------------------------
    |
    | Default locale and timezone for new users. These values are used when
    | creating a new user account and can be updated by the user later.
    |
    | When the client sends Accept-Language or X-Timezone headers during
    | registration, the package auto-detects values from those headers and
    | validates them against the supported lists below.
    |
    */

    'defaults' => [
        'locale' => env('MAGIC_STARTER_DEFAULT_LOCALE', 'en'),
        'timezone' => env('MAGIC_STARTER_DEFAULT_TIMEZONE', 'UTC'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Locales
    |--------------------------------------------------------------------------
    |
    | The list of locale codes your application supports. Used for validation
    | during registration and profile updates, and for auto-detection from
    | the Accept-Language header. Locale codes should be 2-letter ISO 639-1.
    |
    */

    'supported_locales' => [
        'en',
        'tr',
    ],

    /*
    |--------------------------------------------------------------------------
    | Profile & Team Photos
    |--------------------------------------------------------------------------
    |
    | Configure the storage disk, paths, and fallback Avatar generator URL
    | for user profile photos and team profile photos.
    |
    */

    'profile_photo_disk' => env('MAGIC_STARTER_PROFILE_PHOTO_DISK', 'public'),
    'team_photo_disk' => env('MAGIC_STARTER_TEAM_PHOTO_DISK', env('MAGIC_STARTER_PROFILE_PHOTO_DISK', 'public')),
    'profile_photo_path' => env('MAGIC_STARTER_PROFILE_PHOTO_PATH', 'profile-photos'),
    'team_photo_path' => env('MAGIC_STARTER_TEAM_PHOTO_PATH', 'team-photos'),
    'ui_avatars_url' => env('MAGIC_STARTER_UI_AVATARS_URL', 'https://ui-avatars.com/api/'),

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for all routes registered by this package.
    |
    */

    'route_prefix' => env('MAGIC_STARTER_ROUTE_PREFIX', 'api/v1'),

    /*
    |--------------------------------------------------------------------------
    | Team Invitation Expiry
    |--------------------------------------------------------------------------
    |
    | Determines the number of days until a team invitation expires.
    |
    */

    'invitation_expiry_days' => env('MAGIC_STARTER_INVITATION_EXPIRY_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Token Expiration
    |--------------------------------------------------------------------------
    |
    | Set the number of minutes until issued tokens expire. Null means
    | tokens never expire. Configure Sanctum's pruning command to clean
    | up expired tokens: php artisan sanctum:prune-expired --hours=24
    |
    */

    'token_expiration_minutes' => env('MAGIC_STARTER_TOKEN_EXPIRATION', null),

    /*
    |--------------------------------------------------------------------------
    | Authentication Identity
    |--------------------------------------------------------------------------
    |
    | Configure which identity fields are accepted during registration and
    | login. Both can be enabled simultaneously — in that case, at least one
    | identifier is required.
    |
    | - email: true  → users may register/login with an email address
    | - phone: true  → users may register/login with a phone number
    |
    | When both are true, the register and login forms accept either or both.
    | When only one is true, that identifier becomes required.
    |
    */

    'auth' => [
        'email' => (bool) env('MAGIC_STARTER_AUTH_EMAIL', true),
        'phone' => (bool) env('MAGIC_STARTER_AUTH_PHONE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication
    |--------------------------------------------------------------------------
    |
    | Configure the settings for Two-Factor Authentication (2FA). This includes
    | the company name displayed in authenticator apps, the number of recovery
    | codes to generate, and the TTL for the challenge token.
    |
    */

    'two_factor' => [
        /*
        |--------------------------------------------------------------------------
        | Company Name
        |--------------------------------------------------------------------------
        |
        | The name of your company or application as it will appear in the
        | user's authenticator app (e.g., Google Authenticator, Authy).
        |
        */

        'company_name' => env('APP_NAME', 'Laravel'),

        /*
        |--------------------------------------------------------------------------
        | Recovery Codes Count
        |--------------------------------------------------------------------------
        |
        | The number of multi-use recovery codes that should be generated for
        | the user when they enable two-factor authentication.
        |
        */

        'recovery_codes_count' => 8,

        /*
        |--------------------------------------------------------------------------
        | GeoIP Database Path
        |--------------------------------------------------------------------------
        |
        | The absolute path to the MaxMind GeoIP2 database file (.mmdb) used to
        | resolve location data for 2FA challenge attempts. Set to null to
        | disable location resolution.
        |
        */

        'geoip_db_path' => null,

        /*
        |--------------------------------------------------------------------------
        | Challenge Token TTL
        |--------------------------------------------------------------------------
        |
        | The number of minutes a two-factor authentication challenge token is
        | valid for. Users must complete the challenge within this window.
        |
        */

        'challenge_token_ttl' => 5,
    ],
];
