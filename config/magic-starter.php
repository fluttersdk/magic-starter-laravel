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
    | auto-incrementing integer IDs. When true, package migrations use
    | uuid() columns and foreignUuid() references. When false, standard
    | id() and foreignId() are used instead. The notifications table keeps
    | a uuid() id either way, because Laravel's database channel writes one.
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
        // \FlutterSdk\MagicStarter\Features::audit(),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Trail
    |--------------------------------------------------------------------------
    |
    | With the audit feature on, every Eloquent create, update and delete is
    | written to `magic_starter_audits` once its transaction commits, along
    | with the acting user and the request it came from. Query-builder bulk
    | writes fire no model events and are not recorded.
    |
    | `exclude` lists model classes (subclasses included) never recorded. The
    | package already skips its own audit rows and every pivot.
    |
    | `ignore` (not set by default) maps a model class (subclasses included)
    | to attributes whose change alone is not recorded, for a column that
    | moves on every tick. An update touching only those (and `updated_at`)
    | writes no row; a mixed update is recorded without them. A model's own
    | `$auditIgnore` property adds to the list, and a model with no list is
    | audited in full. Credential columns are never ignored.
    |
    | `redact` lists attribute names stored as `[redacted]`. It EXTENDS the
    | built-in list (password, remember_token, two_factor_secret,
    | two_factor_recovery_codes, token) and never replaces it; a model's
    | `$hidden`, its encrypted and hashed casts and its own `$auditExclude`
    | property are redacted too.
    |
    | Deleting a user erases the rows about that user and keeps the rows they
    | acted in without the actor's key.
    |
    | `retention_days` is the age, in days, past which a row may be pruned.
    |
    */

    'audit' => [
        // Sanctum stamps `last_used_at` on every authenticated request, so
        // auditing tokens would write one row per API call.
        'exclude' => [
            \Laravel\Sanctum\PersonalAccessToken::class,
        ],
        'redact' => [],
        'retention_days' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | Administrators
    |--------------------------------------------------------------------------
    |
    | Email addresses allowed into the admin panel, from the comma-separated
    | MAGIC_STARTER_ADMIN_EMAILS. Empty by default.
    |
    */

    'admin' => [
        // The host the generated admin panel answers on; null serves it on a path.
        'host' => env('MAGIC_STARTER_ADMIN_HOST'),

        'emails' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MAGIC_STARTER_ADMIN_EMAILS', '')),
        ))),
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
    | Configure the storage disk and paths for user and team profile photos.
    | An account with no upload answers `profile_photo_url: null`, and the
    | client draws its own initials.
    |
    */

    'profile_photo_disk' => env('MAGIC_STARTER_PROFILE_PHOTO_DISK', 'public'),
    'team_photo_disk' => env('MAGIC_STARTER_TEAM_PHOTO_DISK', env('MAGIC_STARTER_PROFILE_PHOTO_DISK', 'public')),
    'profile_photo_path' => env('MAGIC_STARTER_PROFILE_PHOTO_PATH', 'profile-photos'),
    'team_photo_path' => env('MAGIC_STARTER_TEAM_PHOTO_PATH', 'team-photos'),

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
    | Middleware applied to every route in `src/routes/api.php`.
    |
    | NOT the vendor webhook routes. Those load from `src/routes/webhooks.php`
    | under their own gate and deliberately inherit nothing from here: a webhook
    | URL is registered in a vendor dashboard and is called by that vendor, not
    | by your users, so a tenant scope or a locale resolver has nobody to resolve
    | and an auth middleware would reject the call.
    |
    | These routes are loaded by the service provider rather than from the
    | host's `routes/api.php`, so they join NO middleware group on their own.
    | Anything the host relies on in its `api` group (a locale resolver, a
    | request id, a tenant scope) does not run here unless it is named below.
    |
    | Empty by default rather than `['api']`: every route here already declares
    | the throttle it wants by name, and defaulting into a group that carries
    | `throttle:api` as well would silently halve a rate limit a prior release
    | granted.
    */
    'route_middleware' => [],

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
    | THE CATALOGUE is four keys, and one product entry carries everything every
    | rail needs to know about it. Validation runs at boot whenever the billing
    | feature is on and throws a LogicException naming the problem, because each
    | rule below guards money moving the wrong way, not a cosmetic slip.
    |
    | 'tier_order' is your tier ranking, CHEAPEST FIRST, and it is required. The
    | tier vocabulary belongs to your application, so this package never guesses
    | it; list your own tier ids in the order a customer upgrades through them.
    | ELEMENT 0 IS THE FREE FLOOR: what a billable holds when nobody pays, so no
    | product may sell it. A ranking is the only list of tiers that exist: a
    | subscription naming a tier outside it is refused, and so is a checkout.
    |
    | WritesEntitlement uses this list to decide whether an incoming write from a
    | DIFFERENT billing rail than the one on record would leave the billable
    | holding LESS than it holds now. Such a write is dropped, because a rail may
    | only revoke what it granted. The shipped default is empty, which is fine
    | with billing off and refused at boot with it on.
    |
    | 'tiers' is the DISPLAY copy per tier id: 'name', 'tagline', 'features',
    | 'recommended', and anything else you add, which travels to the client
    | UNTOUCHED (what a tier caps, what it unlocks, copy for a capability only
    | your product has). GET billing/plans serves one entry per ranked tier, in
    | ranking order, with its 'id' added; the map's own order never ranks. A
    | ranked tier with no definition is served as its bare id. 'cycles' and
    | 'products' are RESERVED: both are derived from the products below and
    | written onto every tier row, so do not write either. A bullet in
    | 'features' is a promise made to somebody holding a credit card, so it may
    | only name something that works today.
    |
    | 'products' is what you SELL, keyed by a name of your choosing:
    |
    |   'type'    subscription | consumable | non_consumable | physical
    |   'tier'    a 'tier_order' id; required for a subscription
    |   'cycle'   monthly | annual; required for a subscription. A tier is not
    |             a price: sold both ways it is two products, and a checkout
    |             names the product KEY so the customer is charged the figure
    |             the screen showed them.
    |   'credits' optional integer a one-off purchase grants
    |   'sellable' optional boolean, default true. false keeps a product MAPPED
    |             without selling it: a grandfathered Stripe price or a retired
    |             store product that existing subscribers still pay, so a webhook
    |             or the reconciler still names their tier. GET billing/plans
    |             lists it flagged 'sellable: false' so a client can place what a
    |             subscriber holds, and never offers it: billing/checkout and
    |             billing/swap refuse it (422 product_not_sellable), and
    |             billing:manifest and billing:doctor leave it out. Anything but
    |             a boolean stops boot.
    |   'prices'  channel (web | app_store | play) => three-letter currency =>
    |             whole amount >= 0 in MINOR units (cents, kurus; 3400 yen is
    |             3400, 1.500 KWD is 1500). Display figures, never a charge: each
    |             rail charges what its own dashboard says. Any other shape stops
    |             boot.
    |   'refs'    each rail's id for this product: 'stripe_price' (env-backed,
    |             from CASHIER_PRICE_<KEY>: the product key uppercased, so
    |             'pro_monthly' reads CASHIER_PRICE_PRO_MONTHLY; one product per
    |             price), 'app_store' (no colon), and 'play' as
    |             '<subscription_id>:<base_plan_id>', the WHOLE id Google sends.
    |             A bare subscription id stops boot: it misses on every Android
    |             renewal.
    |
    | Write ONE sellable product per (tier, cycle) and put every rail's ref on
    | it; a second one for the same pair is refused at boot unless it is
    | 'sellable' => false. A checkout sells the product key it names; a
    | reverse lookup (a Stripe price or a store id back to its product) answers
    | the FIRST product carrying that ref, in config order.
    |
    | A store id belongs to one product, and ONE PLAY SUBSCRIPTION SELLS ONE
    | TIER: Play moves a customer between base plans of a subscription as a
    | renewal of the same purchase, so 'pro_sub:monthly' under one tier and
    | 'pro_sub:annual' under another would move the tier with nobody deciding.
    | Both are refused at boot.
    |
    | An EMPTY ref is no ref. Refs assembled from the environment write an empty
    | string the moment a variable is unset, and a reverse lookup honouring it
    | would name the empty string as the price of a paid tier; leaving one unset
    | costs you a product that cannot be sold on that rail, never one that is
    | given away.
    |
    | An unmapped price or store product is a CONFIG GAP and never a downgrade:
    | a rail that cannot name the tier a paying subscription sells leaves the
    | entitlement alone and warns, because the alternative is taking a tier
    | away from somebody whose card just cleared.
    |
    | 'pricing' is how a STORE price is derived when a product does not write
    | one: from the SAME currency's web price, under 'commission'. 'absorb'
    | charges the web figure and you net less; 'gross_up' charges
    | ceil(web / (1 - rate)) in minor units so you net the web figure. An
    | explicit store price always wins. A currency the web channel does not
    | price is ABSENT on a store channel: this package never converts between
    | currencies, because an exchange rate frozen into config is wrong the day
    | after it is written. 'currency' is the base currency a screen falls back
    | to.
    |
    | THE KEYS THIS REPLACED ARE REFUSED BY NAME. 'plans', 'prices' and
    | 'store_products' described the same products from three sides with
    | nothing keeping them in step. mergeConfigFrom is a SHALLOW merge, so a
    | config published before this catalogue replaces the whole 'billing'
    | array and carries none of the keys above: booting it would map no tier on
    | any rail with no error anywhere. With billing on, any of the three present
    | (even empty) stops boot with a message naming where its content moved.
    |
    | The Stripe price ids live here rather than under cashier.plans, which is
    | where an earlier application kept them. That key is NOT part of Cashier:
    | Cashier's own config has no 'plans' key at all, so an adopter following
    | Cashier's documentation would never create one, every price would resolve
    | to no tier, and no webhook would ever grant anything.
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
    | deliberate exceptions to the package-owned-key rule the Stripe refs follow
    | above.
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
    | value and would silently UNSIGN the endpoint. The Stripe refs moved
    | precisely because the opposite is true of them: 'cashier.plans' was never a
    | Cashier key at all, so nothing in Cashier reads it.
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
    | configured ('secret_api_key' is set) and 'webhook_secret' is not,
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
    | 'api_v2_key' and 'project_id' are read by `billing:doctor --remote` only,
    | through RevenueCat's v2 API. The v2 key is a SEPARATE secret key scoped
    | to project_configuration:{apps,products,entitlements,offerings,packages,
    | integrations}:read; the v1 key above reads subscribers and is not widened
    | to cover configuration. Neither is needed to sell.
    |
    | THE AGENT PAIR. `billing:manifest` prints every store and rail object this
    | catalogue needs (App Store group and levels, Play subscriptions and base
    | plans, RevenueCat products, entitlements, offering and webhook, Stripe
    | prices whose lookup key is the product key) for an agent to apply with
    | asc, gplay, rc and stripe; `billing:doctor` checks the configuration and,
    | with --remote, diffs Stripe and RevenueCat against it. Both only READ: the
    | package never writes to a vendor, and neither prints a secret, only
    | whether it is set.
    |
    | 'accept_sandbox' is whether this deployment may act on sandbox purchases
    | at all. FALSE in production, always: a sandbox purchase granting a real
    | paid tier is money out of the door, and a store's sandbox is trivially
    | reachable by anybody with a developer account. It only WIDENS what an
    | inbound event is allowed to say; it is never read instead of it.
    |
    | 'reconcile' configures the SWEEP that heals a dropped webhook. Both rails
    | abandon a delivery (RevenueCat after five retries inside about three
    | hours, Stripe after roughly three days) and after that the drift is
    | permanent and silent, so `billing:reconcile` re-reads each rail and
    | corrects what moved. The package registers the schedule itself, under the
    | billing feature, because a rail that only heals when the adopter
    | remembered to schedule something is a rail that does not heal.
    |
    | THE DEFAULT IS DAILY, and that is a deliberate softening of the cadence
    | the application this rail came from runs. The sweep makes one
    | authoritative RevenueCat read per store subject per run, so hourly against
    | a large store fleet is an API bill an adopter never agreed to, and this
    | package ships the schedule whether or not they thought about it. Daily
    | still heals inside Stripe's three-day window; it can be a day behind a
    | store expiry. An adopter selling mostly through the stores should set this
    | to 'hourly', which is what heals inside the window the damage arrives in.
    |
    | The value is a frequency WORD from the list the reconciler's registration
    | recognises, or any cron expression, which is the escape hatch for a
    | cadence no word names (a staggered sweep, or four times a day at hours you
    | choose). A word that is not on the list and is not a valid cron expression
    | raises from `schedule:run` rather than silently never running.
    |
    | The registration carries `withoutOverlapping()` and `onOneServer()`.
    | onOneServer() is NOT fleet-wide protection on every cache store: it takes
    | a lock through the default cache, and the `file` and `array` stores both
    | implement locking LOCALLY (a file on that server's own disk, an array in
    | that process's own memory), so every server acquires its own lock and runs
    | its own sweep. Nothing raises and nothing warns. Point the default cache
    | at a shared store (redis, memcached, database, dynamodb) if one sweep per
    | fleet is what you need; otherwise expect one per server.
    |
    */

    'billing' => [
        'billable' => 'user',

        'tiers' => [
            // 'free' => [
            //     'name' => 'Free',
            //     'tagline' => 'Kick the tires.',
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

        'products' => [
            // 'pro_monthly' => [
            //     'type' => 'subscription',
            //     'tier' => 'pro',
            //     'cycle' => 'monthly',
            //     'prices' => [
            //         'web' => ['USD' => 2900, 'TRY' => 49900],
            //         // Optional: an explicit store figure wins over the derivation.
            //         'app_store' => ['TRY' => 59999],
            //     ],
            //     'refs' => [
            //         'stripe_price' => env('CASHIER_PRICE_PRO_MONTHLY'),
            //         'app_store' => 'com.example.app.pro.monthly',
            //         'play' => 'pro:monthly',
            //     ],
            // ],
        ],

        'pricing' => [
            'currency' => 'USD',
            'commission' => [
                'mode' => 'absorb',
                'rate' => 0.15,
            ],
        ],

        'reconcile' => [
            'cadence' => env('MAGIC_STARTER_BILLING_RECONCILE_CADENCE', 'daily'),
        ],

        'revenuecat' => [
            'path' => env('REVENUECAT_WEBHOOK_PATH', 'webhooks/revenuecat'),
            'webhook_secret' => env('REVENUECAT_WEBHOOK_SECRET'),
            'secret_api_key' => env('REVENUECAT_SECRET_API_KEY'),
            'base_url' => env('REVENUECAT_BASE_URL', RevenueCatClient::DEFAULT_BASE_URL),
            'operation_budget_seconds' => env('REVENUECAT_OPERATION_BUDGET_SECONDS', 10),
            'accept_sandbox' => (bool) env('REVENUECAT_ACCEPT_SANDBOX', false),
            'api_v2_key' => env('REVENUECAT_API_V2_KEY'),
            'project_id' => env('REVENUECAT_PROJECT_ID'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Social Authentication
    |--------------------------------------------------------------------------
    |
    | Configure OAuth providers, redirect targets, and credential keys for
    | social sign-in flows. The `providers` array names which drivers are
    | enabled; others are refused when a client requests them. Absent from
    | the array means the provider is not supported. The `redirects` object
    | carries the post-auth URL for each platform a flow may name (`ios`,
    | `android`, `web`), any of which may be null (the refusal is by design,
    | not a silent fallback: the client sees it as "this platform is not
    | configured"). Point `android` at a verified https App Link: any app can
    | register a custom scheme and catch the sign-in, and the package logs a
    | warning at boot when it is anything else. `ios` may stay a custom
    | scheme, since ASWebAuthenticationSession returns only to the app that
    | started it.
    |
    | `audiences` lists are comma-separated, trimmed env values naming the
    | client ids an ID token may be issued to. For `google` those are the
    | OAuth client ids (web, iOS, Android); for `apple` list the bundle id
    | AND the Services ID, because a native token is issued to the first and
    | a web one to the second. An empty list refuses every token for that
    | provider.
    |
    | `apple` carries five keys: `team_id` (your Developer Team ID from Apple
    | Developer), `key_id` (the key's own ID from the Signing Keys section),
    | `private_key` (the PEM path or inlined PEM text), `bundle_id` (your
    | app's main bundle), and `services_id` (the service identifier from your
    | domain associations). The first three sign the client secret a code
    | exchange or a revocation needs; verifying an identity token needs none.
    |
    | `cache_store` names the Laravel cache driver used for state and code
    | token storage; absence defaults to the app default. `state_ttl` (seconds,
    | default 600) and `code_ttl` (default 60) control token lifetimes.
    | `link_ticket_ttl` (default 300) gates login-link tickets, and
    | `confirmation_ttl` (default 600) gates post-auth confirmation tokens.
    |
    */

    'social' => [
        'providers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MAGIC_STARTER_SOCIAL_PROVIDERS', 'google,apple,github,microsoft')),
        ))),

        'redirects' => [
            'ios' => env('MAGIC_STARTER_SOCIAL_IOS_REDIRECT'),
            'android' => env('MAGIC_STARTER_SOCIAL_ANDROID_REDIRECT'),
            'web' => env('MAGIC_STARTER_SOCIAL_WEB_REDIRECT'),
        ],

        'audiences' => [
            'google' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('MAGIC_STARTER_SOCIAL_GOOGLE_AUDIENCES', '')),
            ))),
            'apple' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('MAGIC_STARTER_SOCIAL_APPLE_AUDIENCES', '')),
            ))),
        ],

        'apple' => [
            'team_id' => env('MAGIC_STARTER_APPLE_TEAM_ID'),
            'key_id' => env('MAGIC_STARTER_APPLE_KEY_ID'),
            'private_key' => env('MAGIC_STARTER_APPLE_PRIVATE_KEY'),
            'bundle_id' => env('MAGIC_STARTER_APPLE_BUNDLE_ID'),
            'services_id' => env('MAGIC_STARTER_APPLE_SERVICES_ID'),
        ],

        'cache_store' => env('MAGIC_STARTER_SOCIAL_CACHE_STORE'),

        'state_ttl' => (int) env('MAGIC_STARTER_SOCIAL_STATE_TTL', 600),
        'code_ttl' => (int) env('MAGIC_STARTER_SOCIAL_CODE_TTL', 60),
        'link_ticket_ttl' => (int) env('MAGIC_STARTER_SOCIAL_LINK_TICKET_TTL', 300),
        'confirmation_ttl' => (int) env('MAGIC_STARTER_SOCIAL_CONFIRMATION_TTL', 600),
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

        /*
        |--------------------------------------------------------------------------
        | External ID Prefix
        |--------------------------------------------------------------------------
        |
        | What a device's OneSignal external id carries before the user's own
        | key. Both sides have to compose the same string or the server
        | addresses an id no device registered, and nothing anywhere says so:
        | OneSignal accepts the notification and delivers it to nobody, leaving
        | a zero-recipient response as the only trace.
        |
        | The Flutter client reads the same value from
        | `magic_starter.notifications.external_id_prefix`, which defaults to
        | the same `user_`. Change one and change the other.
        |
        | It is read in two places here: {@see HasNotifications::routeNotificationForOneSignal},
        | and {@see OneSignalChannel::send}'s fallback for a notifiable that
        | does not use the trait. That fallback used to send the key BARE,
        | which is the one shape that can never work: it matches no device this
        | stack registers, and OneSignal rejects a bare numeric external id
        | outright. A published `App\Models\User` without the trait is the
        | ordinary way to reach it, since `MagicStarter::userModel()` detects
        | one automatically.
        |
        */

        'external_id_prefix' => env('MAGIC_STARTER_EXTERNAL_ID_PREFIX', 'user_'),

        /*
        |--------------------------------------------------------------------------
        | Web Origin
        |--------------------------------------------------------------------------
        |
        | Where the Flutter web client is served, e.g. https://app.example.com.
        | Set this and a push carrying a deep link opens the right SCREEN in a
        | browser instead of the home page.
        |
        | It is needed because a browser reads `web_url` and nothing else. The
        | mobile clients navigate from the notification's custom data, and the
        | web one cannot: a click is handled by the service worker, which opens
        | the launch url as an ordinary page load, so no Dart is running yet to
        | read that data. OneSignal supplies the dashboard's Site URL when the
        | payload names no url, which is why the symptom is the home page in a
        | new tab rather than an error. Measured against a live deployment on
        | 2026-09-10; nothing reported a failure.
        |
        | {@see OneSignalChannel::applyWebUrl} joins this to the deep link the
        | notification already carries, so an application sets one value here
        | rather than composing an absolute url in every notification class. A
        | builder that sets `web_url` itself is always left alone.
        |
        | Absent means off, and off is the old behaviour rather than a broken
        | one: mobile keeps working exactly as before and web keeps landing on
        | the home page. Guessing an origin would be worse than not having one,
        | since `APP_URL` on an API-only deployment is the API host and would
        | send every web recipient somewhere the client is not served.
        |
        */

        'web_origin' => env('MAGIC_STARTER_WEB_ORIGIN'),

        /*
        |--------------------------------------------------------------------------
        | Self-Addressed Push Test
        |--------------------------------------------------------------------------
        |
        | Whether POST {route_prefix}/notifications/push-test is switched on.
        | The endpoint lets a signed-in person page their OWN devices, so they
        | can find out whether push reaches the phone in their hand before an
        | incident rather than during one. It takes no recipient: the target is
        | derived from the Sanctum session.
        |
        | OFF, and an absent key is off too. Everything the endpoint does is
        | make the platform emit a real push because a client asked it to, and
        | that is a capability to switch on deliberately once an application
        | actually offers the button, not one to have live and reachable by
        | anything holding a token from the day the package is installed.
        | Absence has to mean off for the same reason: `mergeConfigFrom` is a
        | shallow merge, so a config published before this key existed carries
        | an `onesignal` block with no switch in it, and an upgrade must not
        | turn an outbound send on for an adopter who never asked for one.
        |
        | While it is off the route is still registered (under the
        | `notifications` feature, as before) and answers 501: the server does
        | not offer this functionality. That is deliberately none of the other
        | three answers this endpoint gives. 403 means the caller may not, 409
        | means push is not provisioned on this deployment, and 404 means the
        | notifications feature is off entirely; a switched-off endpoint is
        | none of those, and reusing one of them would send whoever hits it
        | looking for the wrong thing.
        |
        | TURNING IT ON TAKES BOTH HALVES. The Flutter client carries the same
        | switch, `notifications.push.self_test_enabled` in the magic
        | notifications config, also off by default, and its push channel
        | reports itself unavailable and posts nothing while that one is off.
        | Setting only this key leaves a live endpoint no client calls; setting
        | only the client's leaves a client posting requests that always 501.
        | Set both, and only once something needs the button.
        |
        */

        'self_test_enabled' => (bool) env('MAGIC_STARTER_PUSH_SELF_TEST_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Account Deletion
    |--------------------------------------------------------------------------
    |
    | Configure the grace period for account deletion. Asking for a deletion
    | schedules it and locks the account at once: every token is revoked and
    | every push device dropped, while the data stays until the purge. There
    | is no cancel endpoint; signing in again during the grace period is what
    | cancels a deletion the user asked for. One an identity provider reported
    | (an orphan) is not cancelled by a sign-in. The purge command deletes
    | the account once the grace period has passed.
    |
    | `grace_days` is the number of days between the request and the purge.
    | 0 means the account is purged at the next purge run, not at the moment
    | of the request.
    |
    */

    'account_deletion' => [
        'grace_days' => (int) env('MAGIC_STARTER_ACCOUNT_DELETION_GRACE_DAYS', 30),
    ],
];
