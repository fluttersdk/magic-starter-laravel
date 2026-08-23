<?php

namespace FlutterSdk\MagicStarter\Support;

/**
 * What the store rail's configuration says about itself, in one place.
 *
 * Three callers ask the same question and each of them acts on it differently:
 * the webhook route file withholds the endpoint, the service provider logs once
 * at boot, and the install command refuses to finish. Three copies of "is this
 * rail half configured" would be free to disagree with each other while every
 * one of their tests passed, and the disagreement is not cosmetic here: a route
 * registered against a predicate the installer does not share is an endpoint
 * that accepts deliveries the adopter was told they had not enabled.
 *
 * It lives beside the rail's other reader rather than on the controller because
 * the console command is one of the three callers, and a command reaching into
 * an HTTP controller for a config predicate is a dependency pointing the wrong
 * way. Nothing here touches a request.
 */
class StoreRailConfiguration
{
    /**
     * Whether the store rail is configured while the secret that authenticates
     * its deliveries is not.
     *
     * Deliberately narrower than "the webhook secret is empty": on a fresh
     * install with billing on, nothing about the store rail is set and there is
     * nothing to complain about. What this catches is the half-configured
     * deployment, where an outbound API key or a product map says the adopter
     * means to sell through a store while the inbound secret says no delivery
     * can be believed.
     */
    public static function isMisconfigured(): bool
    {
        return self::railIsConfigured() && self::webhookSecret() === null;
    }

    /**
     * Whether anything about the store rail has been configured at all.
     *
     * The outbound API key and the product map are the two keys an adopter
     * cannot sell through a store without: the first is what the authoritative
     * re-read authenticates with, and the second is what maps a store product
     * onto one of their tiers. Either one present means the rail is meant to
     * work.
     */
    public static function railIsConfigured(): bool
    {
        $apiKey = config('magic-starter.billing.revenuecat.secret_api_key');
        $products = config('magic-starter.billing.store_products', []);

        return (is_string($apiKey) && trim($apiKey) !== '')
            || (is_array($products) && $products !== []);
    }

    /**
     * The configured signing secret, or null when there is nothing usable there.
     *
     * One reader for the key, so the boot-time guard and the per-request refusal
     * cannot disagree about what an empty secret is: a whitespace-only value in
     * an `.env` file is not a secret, and a guard that accepted it would clear
     * the boot check while the endpoint refused every delivery.
     */
    public static function webhookSecret(): ?string
    {
        $secret = config('magic-starter.billing.revenuecat.webhook_secret');

        return is_string($secret) && trim($secret) !== '' ? $secret : null;
    }
}
