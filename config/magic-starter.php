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
        // \FlutterSdk\MagicStarter\Features::onesignal(),
        // \FlutterSdk\MagicStarter\Features::guestAuth(),
        // \FlutterSdk\MagicStarter\Features::phoneOtp(),
        // \FlutterSdk\MagicStarter\Features::emailVerification(),
        // \FlutterSdk\MagicStarter\Features::timezones(),
        // \FlutterSdk\MagicStarter\Features::billing(),
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

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | The package ships the entitlement CONTRACT, not a payment rail: the two
    | neutral enums, the provenance columns, and the one action that arbitrates
    | between rails writing the same tier. Your own rail (Cashier, a store SDK,
    | an operator command) feeds it.
    |
    | 'billable' is WHICH KIND of thing you bill, and it accepts exactly two
    | values: 'user' or 'team'. It is a closed token rather than a model class
    | name deliberately, because the package has to know what kind of subject it
    | is writing to and a class name cannot say (an App\Models\Account could be
    | either). The class itself still comes from the 'models' block above, so a
    | published App\Models\Team or your own user model is picked up unchanged.
    |
    | The default is 'user' because the teams feature ships OFF: on a fresh
    | install there is no team to bill, not even a personal one. Selecting 'team'
    | therefore REQUIRES the teams feature, and the provider refuses to boot
    | rather than letting an entitlement be written to a subject that does not
    | exist. Any other PRESENT value, including an explicit null, is refused at
    | boot for the same reason. Leaving the key out entirely is not: an older
    | published config has no key at all, and 'user' is the answer for it.
    |
    | 'tier_order' is your plan catalogue, CHEAPEST FIRST. The tier vocabulary
    | belongs to your application, so this package never guesses it; list your
    | own plan ids in the order a customer upgrades through them.
    |
    | WritesEntitlement uses this list for exactly one decision: whether an
    | incoming write from a DIFFERENT billing rail than the one on record would
    | leave the billable holding LESS than it holds now. Such a write is
    | dropped, because a rail may only revoke what it granted.
    |
    | Leaving the list empty makes that comparison undecidable, and an
    | undecidable cross-rail write against a tier the billable HOLDS is REFUSED,
    | with a warning naming this key. A billable holding nothing is a separate
    | case and is unaffected: there is no tier to take away, so such a write
    | applies whether or not this list is published. So the empty default is
    | safe for a fresh install and is not safe once you sell something on more
    | than one rail: publish the order then.
    |
    | WHY laravel/cashier IS A HARD REQUIRE. It is the one dependency in this
    | package that needed an argument. The four SDKs already in the require
    | block (Sanctum, Socialite, google2fa, OneSignal) are libraries: they cost
    | an adopter nothing until something resolves them. Cashier is a package
    | with a service provider, and CashierServiceProvider::boot() runs for every
    | adopter whether or not they bill, registering the stripe/webhook route
    | under config('cashier.path'), merging a cashier config, and adding a
    | vendor:publish group.
    |
    | Cashier::ignoreRoutes() is what makes that acceptable. The provider calls
    | it from register() under the billing feature gate, which is the only phase
    | early enough: every provider's register() runs before any provider's
    | boot(), so by the time Cashier boots the refusal is already in place. With
    | billing on, the package serves the webhook under a key of its own and
    | Cashier's route never appears; with billing off, nothing here touches
    | Cashier and an adopter driving it directly keeps their own routes.
    |
    | The publish group is the one residual cost and it cannot be vetoed in
    | code: addPublishGroup() is additive and Laravel exposes no removal, so
    | Cashier's groups stay listed under vendor:publish.
    |
    */

    'billing' => [
        'billable' => 'user',

        'tier_order' => [
            // 'free',
            // 'pro',
            // 'business',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OneSignal Push Notifications
    |--------------------------------------------------------------------------
    |
    | Configure the settings for OneSignal push notifications. This includes
    | the app ID, REST API key, and the default target channel for push messages.
    |
    */

    'onesignal' => [
        /*
        |--------------------------------------------------------------------------
        | OneSignal App ID
        |--------------------------------------------------------------------------
        |
        | The OneSignal application ID for your project. Required to send push
        | notifications via the OneSignal PHP SDK.
        |
        */

        'app_id' => env('ONESIGNAL_APP_ID'),

        /*
        |--------------------------------------------------------------------------
        | OneSignal REST API Key
        |--------------------------------------------------------------------------
        |
        | The OneSignal REST API key for server-to-server authentication.
        | Required to send push notifications via the OneSignal PHP SDK.
        |
        */

        'rest_api_key' => env('ONESIGNAL_REST_API_KEY'),

        /*
        |--------------------------------------------------------------------------
        | OneSignal Target Channel
        |--------------------------------------------------------------------------
        |
        | The default delivery channel for OneSignal push notifications.
        | Typically 'push' for native mobile push notifications.
        |
        */

        'target_channel' => 'push',
    ],
];
