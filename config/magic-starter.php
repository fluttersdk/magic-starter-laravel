<?php

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
    ],

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
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default Eloquent models used by the package.
    |
    */

    'models' => [
        'user' => env('MAGIC_STARTER_USER_MODEL'),
        'team' => env('MAGIC_STARTER_TEAM_MODEL', \FlutterSdk\MagicStarter\Models\Team::class),
    ],

    'profile_photo_disk' => env('MAGIC_STARTER_PROFILE_PHOTO_DISK', 'public'),
    'profile_photo_path' => env('MAGIC_STARTER_PROFILE_PHOTO_PATH', 'profile-photos'),
    'team_photo_path' => env('MAGIC_STARTER_TEAM_PHOTO_PATH', 'team-photos'),

    'defaults' => [
        'locale' => env('MAGIC_STARTER_DEFAULT_LOCALE', 'en'),
        'timezone' => env('MAGIC_STARTER_DEFAULT_TIMEZONE', 'UTC'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for all routes registered by this package.
    |
    */

    'route_prefix' => env('MAGIC_STARTER_ROUTE_PREFIX', ''),

];
