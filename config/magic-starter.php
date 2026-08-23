<?php

use FlutterSdk\MagicStarter\Models\Team;
use FlutterSdk\MagicStarter\Models\TeamInvitation;
use FlutterSdk\MagicStarter\Models\TeamUser;
use FlutterSdk\MagicStarter\Support\RevenueCatClient;

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
    | 'plans' is the CATALOGUE the billing screen renders: one entry per tier,
    | cheapest first, served verbatim under a 'data' envelope by
    | GET billing/plans. It is display data and gating data, never Stripe price
    | ids (those are 'prices' below).
    |
    | The package names only the fields every billing screen needs: 'id', 'name',
    | 'tagline', 'monthly', 'annual', 'currency', 'features', 'recommended'. Every
    | other key you put on an entry travels to the client UNTOUCHED, which is
    | where anything product-specific belongs: what a tier caps, what it unlocks,
    | the copy for a capability only your product has. This package cannot know
    | those and does not try, exactly as it delegates counting to ReportsUsage
    | and the tier vocabulary to the list below. A null price means "contact us";
    | what a null LIMIT means is your application's business, not this package's.
    |
    | A bullet in 'features' is a promise made to somebody holding a credit card,
    | so it may only name something that works today.
    |
    | ORDER AND RANKING. When 'tier_order' below is published it is the ranking,
    | full stop, and this catalogue is only display. When it is NOT published the
    | order is taken from these entries' ids instead, so an adopter who publishes
    | one list gets both behaviours rather than a working screen beside an
    | undecidable cross-rail write. Publishing both and having them disagree
    | means the explicit list wins, because it is the more specific declaration;
    | there is no reason to write two orders, so write one.
    |
    | 'prices' is which Stripe PRICE sells which of those tiers, as a plain
    | ['price_id' => 'tier_id'] map. The Stripe rail reads it in both directions:
    | a webhook asks which tier the price on a subscription sells, and a checkout
    | asks which price sells a tier the customer picked.
    |
    | It lives here rather than under cashier.plans, which is where an earlier
    | application kept it. That key is NOT part of Cashier: Cashier's own config
    | has no 'plans' key at all, so an adopter following Cashier's documentation
    | would never create one, every price would resolve to no tier, and no
    | webhook would ever grant anything, with no error anywhere to say why.
    |
    | 'store_products' is the same question for the STORE rail: which App Store
    | or Play product sells which tier, as a ['product_id' => 'tier_id'] map. The
    | store rail cannot grant anything until it is filled, and an unmapped
    | product is logged and written nowhere, which is the direction that cannot
    | hand out a tier nobody bought.
    |
    | KEY IT ON THE WHOLE PRODUCT ID GOOGLE SENDS. Play reports a subscription as
    | '<subscription_id>:<base_plan_id>', so a map keyed on the bare subscription
    | id misses on every Android renewal and warns instead of granting. Apple
    | sends the plain product id and needs no such care.
    |
    | An unmapped price is a CONFIG GAP and never a downgrade: a rail that cannot
    | name the tier a paying subscription sells leaves the entitlement alone and
    | warns, because the alternative is taking a tier away from somebody whose
    | card just cleared.
    |
    | Assembling this from the environment is the normal case
    | (env('CASHIER_PRICE_PRO') and friends), and it is also how the dangerous
    | entry appears: an unset variable writes an EMPTY KEY, and an empty key that
    | reached a reverse lookup would name the empty string as the price that
    | sells a paid tier. Empty keys and empty tiers are therefore stripped when
    | this map is read, so leaving a variable unset costs you one tier that
    | cannot be sold rather than one that is given away.
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
    | THE STRIPE WEBHOOK KEEPS CASHIER'S PATH AND CASHIER'S SECRET, and both are
    | deliberate exceptions to the package-owned-key rule 'prices' follows above.
    |
    | The package serves the webhook itself (src/routes/webhooks.php, loaded from
    | its own loadRoutesFrom under the billing feature), and it registers the
    | route under config('cashier.path'), which defaults to 'stripe'. So the
    | served path is `stripe/webhook`: exactly what Cashier serves, exactly what
    | an adopter arriving from Cashier already has in their Stripe dashboard.
    | That file is separate from src/routes/api.php because nothing here may
    | inherit 'route_prefix' above: an API prefix moves with a deploy, and a
    | webhook URL registered in a vendor dashboard cannot, so inheriting it would
    | 404 every delivery until somebody edited the dashboard by hand.
    |
    | config('cashier.webhook.secret') stays Cashier's key for a stricter reason
    | than convention: Cashier's own WebhookController::__construct() is what
    | reads it, and it attaches the signature middleware only when it is set.
    | Moving it under a package key would leave that constructor reading an empty
    | value and would silently UNSIGN the endpoint. 'prices' moved precisely
    | because the opposite is true of it: 'cashier.plans' was never a Cashier key
    | at all, so nothing in Cashier reads it.
    |
    | DO NOT RUN `vendor:publish --tag=cashier-migrations`. That is the residual
    | cost turning into a broken schema, and it is the one instruction here that
    | can only be written down. Cashier's five migrations hardcode
    | Schema::table('users'), $table->id() and foreignId('user_id'): on an
    | application billing a team they put the Stripe customer columns on the
    | wrong table, and on any application using UUID keys they create a bigint
    | `subscriptions` whose child `subscription_items` cannot reference it. The
    | package ships its own three in place of those five
    | (add_cashier_customer_columns_to_billable_table, create_subscriptions_table
    | and create_subscription_items_table), resolving the table from 'billable'
    | and the key type from 'use_uuids', with Cashier's two later meter columns
    | folded into the items create. `magic-starter:install` publishes them in
    | dependency order.
    |
    | USAGE REPORTING has no key here, because it has no default to hold: the
    | package ships FlutterSdk\MagicStarter\Contracts\ReportsUsage with
    | deliberately NO default implementation and NO binding, and the usage
    | endpoint it would feed is registered only once a consumer binds one
    | (typically `$this->app->bind(ReportsUsage::class, ...)` in the
    | consumer's own AppServiceProvider). Binding a default here would answer
    | an empty usage map for every billable until the consumer wires real
    | counting, and an empty map is not "unknown", it is "used nothing": every
    | cap a consumer gates on that answer would silently open. See the
    | contract's own docblock for the shipped defect this refusal exists to
    | prevent.
    |
    | UPGRADING FROM A RELEASE BEFORE 'billable' EXISTED. Set this key
    | EXPLICITLY before re-running the installer, even to the value you believe
    | is already in effect. mergeConfigFrom is a shallow merge, so a config
    | published before the key existed carries no 'billable' at all and the
    | 'user' default answers for it. That is correct for the arbitration
    | contract, and it is wrong for the SCHEMA of an application billing a team:
    | the four migrations above resolve their target through
    | MagicStarter::billableModel(), so a team-billing application that upgrades
    | without setting the key gets a whole Cashier schema plus the entitlement
    | provenance on `users` instead of `teams`. Nothing refuses it, and nothing
    | can: the boot guard only rejects a token it does not RECOGNISE, and 'user'
    | is a perfectly valid one. Before the Cashier tables shipped this cost one
    | mis-targeted ALTER; it now costs the whole billing schema.
    |
    | 'revenuecat' configures the STORE rail: Apple App Store and Google Play
    | subscriptions, reaching the application as webhook deliveries that are
    | only ever a SIGNAL. What a subscriber is actually entitled to is read
    | back from RevenueCat's API by
    | \FlutterSdk\MagicStarter\Support\RevenueCatClient, so both halves of the
    | rail need configuration here: the secret that authenticates an inbound
    | delivery, and the key that authenticates the outbound read.
    |
    | It lives under a PACKAGE-OWNED key rather than under 'cashier', because
    | RevenueCat has no Laravel vendor package at all here: there is no default
    | to defer to and no adopter dashboard that already points at some other
    | path, unlike the Stripe webhook, which keeps reading Cashier's own
    | 'cashier.path'.
    |
    | The five SECRET AND TUNING ENV VAR NAMES below are NOT this package's to
    | rename, even though the config key that reads them is. An adopter migrating
    | from a hand-rolled RevenueCat integration already has these set on their
    | server, and keeping the names means adopting this package is a config-file
    | change rather than a server .env edit with a window where deliveries fail.
    |
    | 'path' is the WHOLE served path of the inbound webhook, and its default is
    | constrained by the same fact: `webhooks/revenuecat` is the path the
    | application this rail was extracted from already serves and already has
    | registered in the RevenueCat dashboard. A webhook URL cannot move with a
    | deploy, so any other default would make adopting this package a manual
    | dashboard edit with a window in which every delivery 404s. Change it only
    | when you are changing the dashboard in the same breath.
    |
    | THE ROUTE IS WITHHELD ON A HALF-CONFIGURED RAIL. When the store rail is
    | configured (an API key or a store product map) and 'webhook_secret' is not,
    | the endpoint could not authenticate anybody, so it is not registered at all:
    | the provider logs the reason once at boot and `magic-starter:install`
    | refuses to complete. The application keeps serving; only the store rail is
    | held back, because an endpoint that refuses every delivery would spend
    | RevenueCat's five retries on a configuration no retry can fix.
    |
    | BOTH SECRETS ARE EMPTY BY DEFAULT AND THE RAIL FAILS CLOSED ON EITHER.
    | With no webhook secret the endpoint refuses every delivery, because it
    | cannot tell RevenueCat apart from anybody who found the URL and an
    | endpoint that queues a tier change must not accept an unauthenticated
    | one. With no API key the authoritative read raises rather than answering
    | "nothing is owed", which would revoke every paying team. Neither has a
    | fallback: a default would be either a secret in a public repository or a
    | value that authenticates as nobody.
    |
    | 'operation_budget_seconds' bounds the WHOLE retried read, not one call: a
    | per-call timeout sized against a wall breaks the moment anything retries.
    |
    | 'accept_sandbox' is whether this deployment may act on sandbox purchases
    | at all. FALSE in production, always: a sandbox purchase granting a real
    | paid tier is money out of the door, and a store's sandbox is trivially
    | reachable by anybody with a developer account. It only WIDENS what an
    | inbound event is allowed to say; it is never read instead of it.
    |
    */

    'billing' => [
        'billable' => 'user',

        'plans' => [
            // [
            //     'id' => 'free',
            //     'name' => 'Free',
            //     'tagline' => 'Kick the tires.',
            //     'monthly' => 0,
            //     'annual' => 0,
            //     'currency' => 'usd',
            //     'features' => [
            //         'Everything you need to try it',
            //     ],
            //     'recommended' => false,
            //     // Anything below this line is yours and travels untouched.
            //     'limits' => [
            //         'seats' => 1,
            //     ],
            // ],
        ],

        'tier_order' => [
            // 'free',
            // 'pro',
            // 'business',
        ],

        'prices' => [
            // env('CASHIER_PRICE_PRO') => 'pro',
            // env('CASHIER_PRICE_BUSINESS') => 'business',
        ],

        'store_products' => [
            // 'com.example.app.pro.monthly' => 'pro',
            // 'business_monthly:business-base' => 'business',
        ],

        'revenuecat' => [
            'path' => env('REVENUECAT_WEBHOOK_PATH', 'webhooks/revenuecat'),
            'webhook_secret' => env('REVENUECAT_WEBHOOK_SECRET'),
            'secret_api_key' => env('REVENUECAT_SECRET_API_KEY'),
            'base_url' => env('REVENUECAT_BASE_URL', RevenueCatClient::DEFAULT_BASE_URL),
            'operation_budget_seconds' => env('REVENUECAT_OPERATION_BUDGET_SECONDS', 10),
            'accept_sandbox' => (bool) env('REVENUECAT_ACCEPT_SANDBOX', false),
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
