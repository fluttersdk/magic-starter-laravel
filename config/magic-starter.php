<?php

use FlutterSdk\MagicStarter\Models\Team;

return [

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
        // \FlutterSdk\MagicStarter\Features::teams(),
        // \FlutterSdk\MagicStarter\Features::profilePhotos(),
        // \FlutterSdk\MagicStarter\Features::sessions(),
        // \FlutterSdk\MagicStarter\Features::socialLogin(),
        // \FlutterSdk\MagicStarter\Features::newsletterSubscription(),
        // \FlutterSdk\MagicStarter\Features::extendedProfile(),
        // \FlutterSdk\MagicStarter\Features::notifications(),
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale & Timezone
    |--------------------------------------------------------------------------
    |
    | Default locale and timezone for new users. These values are used when
    | creating a new user account, and can be updated by the user later.
    |
    */

    'defaults' => [
        'locale' => env('MAGIC_STARTER_DEFAULT_LOCALE', 'en'),
        'timezone' => env('MAGIC_STARTER_DEFAULT_TIMEZONE', 'UTC'),
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

    'route_prefix' => env('MAGIC_STARTER_ROUTE_PREFIX', ''),

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
];
