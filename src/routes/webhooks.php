<?php

/**
 * Magic Starter vendor webhook route definitions.
 *
 * A SECOND route file beside `api.php`, and the split is the whole point rather
 * than tidiness. Every route in `api.php` is grouped under
 * `config('magic-starter.route_prefix')`, which an adopter is free to change:
 * moving their API from `api/v1` to `api/v2` is a deploy. A webhook URL is
 * registered in a VENDOR'S DASHBOARD, so it cannot move with a deploy at all;
 * the vendor keeps posting to the old one, every delivery 404s, and the only
 * symptom is an entitlement that silently stops moving. So nothing here inherits
 * that prefix, and nothing here may be moved into `api.php`.
 *
 * Loaded by the provider through its own `loadRoutesFrom`, gated on the billing
 * feature. `loadRoutesFrom` applies no middleware group, which is what this file
 * wants: a `web` registration would put session, cookies and CSRF on a route
 * whose caller carries no token and cannot be made to, and every delivery would
 * be a 419 until somebody remembered to exempt the exact path.
 */

use FlutterSdk\MagicStarter\Http\Controllers\RevenueCatWebhookController;
use FlutterSdk\MagicStarter\Http\Controllers\StripeWebhookController;
use FlutterSdk\MagicStarter\Support\StoreRailConfiguration;
use Illuminate\Support\Facades\Route;

/*
 * The Stripe rail, under CASHIER'S OWN path key rather than a package-owned one.
 *
 * `cashier.path` defaults to 'stripe', so the served path is `stripe/webhook`:
 * exactly what Cashier itself serves, exactly what an adopter arriving from
 * Cashier already has in their Stripe dashboard, and exactly what an application
 * migrating onto this package was already serving. Minting a package-owned path
 * here would force every one of them to edit that dashboard by hand during the
 * upgrade, with a window in which Stripe's deliveries 404 and entitlements
 * silently stop.
 *
 * The route NAME is Cashier's too, for the same reason: an adopter who resolves
 * `route('cashier.webhook')` keeps resolving it. Cashier's own registration is
 * refused in the provider's register() (`Cashier::ignoreRoutes()`), so there is
 * one endpoint on these events and not two.
 *
 * The signature check is NOT here. It is attached by
 * {@see StripeWebhookController::__construct()} through Cashier's own
 * constructor, so it travels with the controller however this route is
 * registered; adding a route-level middleware instead would leave the endpoint
 * unsigned the moment somebody registers the controller elsewhere.
 *
 * The RevenueCat rail below is the opposite case and sits under a package-owned
 * key: it has no vendor default and no Cashier config to defer to.
 */
Route::prefix((string) config('cashier.path', 'stripe'))
    ->group(function (): void {
        Route::post('webhook', [StripeWebhookController::class, 'handleWebhook'])
            ->name('cashier.webhook');
    });

/*
 * The store rail, under a PACKAGE-OWNED key whose default is not free to choose.
 *
 * RevenueCat ships no Laravel package, so there is no vendor default to inherit
 * and the key is this package's to own: `magic-starter.billing.revenuecat.path`,
 * one key holding the WHOLE path rather than a prefix, because RevenueCat's URL
 * has no vendor-fixed second segment for a prefix to sit in front of.
 *
 * The DEFAULT is the constrained half, and it is constrained the same way
 * `cashier.path` above is even though the mechanism is opposite. `webhooks/revenuecat`
 * is the path the application this rail was extracted from already serves and
 * already has registered in the RevenueCat dashboard. A webhook URL lives in a
 * vendor dashboard and cannot move with a deploy, so minting any other default
 * would turn adopting this package into a manual dashboard edit with a window in
 * which every delivery 404s and entitlements silently stop moving.
 *
 * The signature check is inside the controller, over the RAW request bytes, and
 * it belongs there rather than in a route middleware for the same reason the
 * Stripe rail's belongs in Cashier's constructor: it must travel with the
 * controller however the route is registered.
 *
 * REGISTERED ONLY WHEN THE RAIL CAN AUTHENTICATE ANYBODY. A deployment that
 * configured the store rail without a signing secret cannot tell RevenueCat from
 * whoever else found the URL, so this endpoint would refuse every delivery it
 * received and spend RevenueCat's five retries on a configuration no retry can
 * fix. Withholding the route is the proportionate answer: the application keeps
 * serving, the provider logs the reason once at boot, and `magic-starter:install`
 * refuses to complete until the secret is set.
 */
if (! StoreRailConfiguration::isMisconfigured()) {
    Route::post(
        (string) config('magic-starter.billing.revenuecat.path', 'webhooks/revenuecat'),
        RevenueCatWebhookController::class,
    )->name('magic-starter.webhooks.revenuecat');
}
