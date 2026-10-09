<?php

namespace FlutterSdk\MagicStarter\Support;

use stdClass;

/**
 * Every store and rail object the catalogue needs, described for an agent that
 * holds asc, gplay, rc and stripe access and applies it.
 *
 * The catalogue says what is sold; this says what each vendor dashboard has to
 * hold for that to work: the App Store subscription group and its levels, one
 * Play subscription per tier with a base plan per cycle, RevenueCat's
 * products, entitlements, offering and webhook, and Stripe's products and
 * lookup-keyed prices. It describes and never applies: the package makes no
 * vendor write, so a manifest can be read, diffed and reviewed before anything
 * changes anywhere.
 *
 * Only subscriptions appear. A one-off product has no tier, no entitlement and
 * no base plan, and nothing in this package sells one yet.
 *
 * NO SECRET VALUE IS EVER READ INTO THIS ARRAY. The `env` section reports each
 * key as `present` or `absent`, and every other section is assembled from the
 * catalogue field by field, so the output is a whitelist by construction.
 */
final class BillingManifest
{
    public const SCHEMA_VERSION = 1;

    /**
     * @var list<string>
     */
    public const SECTIONS = [
        'app_store',
        'play',
        'revenuecat',
        'stripe',
        'env',
    ];

    /**
     * Where an App Store price point lands against the derived target. Apple
     * offers fixed points per territory, so the target is rarely one of them,
     * and rounding up is the direction that never sells below the figure the
     * screen shows.
     */
    public const PRICE_POINT_RULE = 'nearest at or above target';

    public const OFFERING = 'default';

    /**
     * The secrets and identifiers the rails read, by environment variable,
     * with the config key each one feeds.
     *
     * @var array<string, string>
     */
    public const ENVIRONMENT = [
        'STRIPE_SECRET' => 'cashier.secret',
        'STRIPE_WEBHOOK_SECRET' => 'cashier.webhook.secret',
        'REVENUECAT_SECRET_API_KEY' => 'magic-starter.billing.revenuecat.secret_api_key',
        'REVENUECAT_WEBHOOK_SECRET' => 'magic-starter.billing.revenuecat.webhook_secret',
        'REVENUECAT_API_V2_KEY' => 'magic-starter.billing.revenuecat.api_v2_key',
        'REVENUECAT_PROJECT_ID' => 'magic-starter.billing.revenuecat.project_id',
    ];

    /**
     * App Store periods and Play billing periods per catalogue cycle.
     *
     * @var array<string, array{app_store: string, play: string, stripe: string}>
     */
    private const PERIODS = [
        'monthly' => [
            'app_store' => 'ONE_MONTH',
            'play' => 'P1M',
            'stripe' => 'month',
        ],
        'annual' => [
            'app_store' => 'ONE_YEAR',
            'play' => 'P1Y',
            'stripe' => 'year',
        ],
    ];

    /**
     * The whole manifest.
     *
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $products = self::subscriptions();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'app_store' => self::appStore($products),
            'play' => self::play($products),
            'revenuecat' => self::revenueCat($products),
            'stripe' => self::stripe($products),
            'env' => self::environment($products),
        ];
    }

    /**
     * The environment variable a product's Stripe price id is read from.
     *
     * A convention rather than a config key: the published config writes
     * `env('CASHIER_PRICE_PRO_MONTHLY')` into `refs.stripe_price`, and the
     * manifest has to tell an agent which variable to fill after it creates
     * the price.
     */
    public static function stripePriceEnvKey(string $productKey): string
    {
        return 'CASHIER_PRICE_' . strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $productKey));
    }

    /**
     * The URL RevenueCat delivers to: the application url plus the rail's
     * served path.
     */
    public static function revenueCatWebhookUrl(): string
    {
        $base = config('app.url');
        $path = config('magic-starter.billing.revenuecat.path', 'webhooks/revenuecat');

        return rtrim(is_string($base) ? $base : '', '/') . '/' . ltrim(is_string($path) ? $path : '', '/');
    }

    /**
     * The paid tiers, cheapest first: the ranking without its free floor.
     *
     * @return list<string>
     */
    public static function paidTiers(): array
    {
        return array_slice(BillingCatalogue::tierOrder(), 1);
    }

    /**
     * Every sellable subscription product with a known cycle, in catalogue order.
     *
     * A product kept only so an old price still maps is left out: an agent
     * applies this list, and a store product or Stripe price created for it
     * would put the retired offer back on sale. `billing:doctor` reads its
     * per-product checks from here for the same reason.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function subscriptions(): array
    {
        return array_filter(
            BillingCatalogue::products(),
            static fn (array $product): bool => $product['type'] === BillingCatalogue::TYPE_SUBSCRIPTION
                && $product['sellable']
                && $product['tier'] !== null
                && $product['cycle'] !== null
                && isset(self::PERIODS[$product['cycle']]),
        );
    }

    /**
     * One subscription group; the highest tier sits at level 1, which is what
     * Apple reads as the top of the group for upgrades and downgrades.
     *
     * @param  array<string, array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    private static function appStore(array $products): array
    {
        $levels = array_flip(array_reverse(self::paidTiers()));
        $entries = [];

        foreach ($products as $product) {
            $entries[] = [
                'key' => $product['key'],
                'product_id' => $product['refs'][BillingCatalogue::CHANNEL_APP_STORE],
                'reference_name' => self::referenceName($product),
                'tier' => $product['tier'],
                'period' => self::PERIODS[$product['cycle']]['app_store'],
                'group_level' => ($levels[$product['tier']] ?? count($levels)) + 1,
                'prices' => self::prices($product, BillingCatalogue::CHANNEL_APP_STORE),
                'price_point_rule' => self::PRICE_POINT_RULE,
            ];
        }

        $name = config('app.name');

        return [
            'subscription_group' => [
                'reference_name' => is_string($name) && $name !== '' ? $name : 'Subscriptions',
            ],
            'products' => $entries,
        ];
    }

    /**
     * One Play subscription per tier, a base plan per cycle.
     *
     * Grouped by the subscription id the refs name, because the catalogue
     * refuses one id under two tiers; a product with no Play ref yet is grouped
     * under its tier with a null id for the agent to fill.
     *
     * @param  array<string, array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    private static function play(array $products): array
    {
        $subscriptions = [];

        foreach ($products as $product) {
            $ref = $product['refs'][BillingCatalogue::CHANNEL_PLAY];
            $subscriptionId = $ref === null ? null : BillingCatalogue::playSubscriptionId($ref);
            $basePlanId = $subscriptionId === null ? null : substr($ref, strlen($subscriptionId) + 1);
            $group = $subscriptionId ?? "tier:{$product['tier']}";

            $subscriptions[$group] ??= [
                'product_id' => $subscriptionId,
                'tier' => $product['tier'],
                'name' => self::tierName($product['tier']),
                'base_plans' => [],
            ];

            $subscriptions[$group]['base_plans'][] = [
                'key' => $product['key'],
                'base_plan_id' => $basePlanId ?? $product['cycle'],
                'billing_period' => self::PERIODS[$product['cycle']]['play'],
                'auto_renewing' => true,
                'prices' => self::prices($product, BillingCatalogue::CHANNEL_PLAY),
            ];
        }

        return [
            'subscriptions' => array_values($subscriptions),
        ];
    }

    /**
     * RevenueCat's half: apps, products, an entitlement per paid tier, the
     * current offering with one package per product key, and the webhook.
     *
     * @param  array<string, array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    private static function revenueCat(array $products): array
    {
        $storeProducts = [];
        $packages = [];
        $byTier = array_fill_keys(self::paidTiers(), []);

        foreach (array_values($products) as $index => $product) {
            $identifiers = self::storeIdentifiers($product);

            foreach ($identifiers as $app => $identifier) {
                $storeProducts[] = [
                    'key' => $product['key'],
                    'store_identifier' => $identifier,
                    'app' => $app,
                    'type' => BillingCatalogue::TYPE_SUBSCRIPTION,
                ];
            }

            $byTier[$product['tier']] = [
                ...$byTier[$product['tier']] ?? [],
                ...array_values($identifiers),
            ];

            $packages[] = [
                'lookup_key' => $product['key'],
                'position' => $index + 1,
                'products' => array_values($identifiers),
            ];
        }

        $entitlements = [];

        foreach ($byTier as $tier => $identifiers) {
            $entitlements[] = [
                'lookup_key' => $tier,
                'display_name' => self::tierName($tier),
                'products' => $identifiers,
            ];
        }

        return [
            'apps' => [
                [
                    'type' => 'app_store',
                ],
                [
                    'type' => 'play_store',
                ],
            ],
            'products' => $storeProducts,
            'entitlements' => $entitlements,
            'offering' => [
                'lookup_key' => self::OFFERING,
                'is_current' => true,
                'packages' => $packages,
            ],
            'webhook' => [
                'url' => self::revenueCatWebhookUrl(),
                'signing' => 'hmac',
                'instruction' => 'Enable HMAC signing on this webhook in the RevenueCat dashboard and put the '
                    . 'secret it shows once into REVENUECAT_WEBHOOK_SECRET. The endpoint verifies '
                    . 'X-RevenueCat-Webhook-Signature and refuses a static Authorization header.',
            ],
        ];
    }

    /**
     * A Stripe product per tier, and per product key a price whose lookup key
     * IS that key, so a price can be found again without knowing its id.
     *
     * The base currency is the catalogue's when the web channel prices it; the
     * rest travel as `currency_options` on the same price.
     *
     * @param  array<string, array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    private static function stripe(array $products): array
    {
        $pricing = BillingCatalogue::pricing();
        $tiers = [];

        foreach ($products as $product) {
            $web = PriceTable::for($product, BillingCatalogue::CHANNEL_WEB, $pricing);

            if ($web === []) {
                continue;
            }

            $base = isset($web[$pricing['currency']]) ? $pricing['currency'] : (string) array_key_first($web);
            $options = [];

            foreach ($web as $currency => $price) {
                if ($currency !== $base) {
                    $options[strtolower($currency)] = [
                        'unit_amount' => $price['amount_minor'],
                    ];
                }
            }

            $tiers[$product['tier']] ??= [
                'tier' => $product['tier'],
                'name' => self::tierName($product['tier']),
                'prices' => [],
            ];

            $tiers[$product['tier']]['prices'][] = [
                'key' => $product['key'],
                'lookup_key' => $product['key'],
                'currency' => strtolower($base),
                'unit_amount' => $web[$base]['amount_minor'],
                'currency_options' => JsonObject::map($options),
                'recurring' => [
                    'interval' => self::PERIODS[$product['cycle']]['stripe'],
                ],
                'env_key' => self::stripePriceEnvKey($product['key']),
            ];
        }

        return [
            'products' => array_values($tiers),
        ];
    }

    /**
     * Each rail key as `present` or `absent`; never its value.
     *
     * A product's price variable reports on the ref it feeds, since the
     * catalogue normalises an empty ref to absent too.
     *
     * @param  array<string, array<string, mixed>>  $products
     * @return array<string, string>
     */
    private static function environment(array $products): array
    {
        $env = [];

        foreach (self::ENVIRONMENT as $key => $configKey) {
            $value = config($configKey);
            $env[$key] = self::presence(is_string($value) && trim($value) !== '');
        }

        foreach ($products as $product) {
            $env[self::stripePriceEnvKey($product['key'])] = self::presence($product['refs']['stripe_price'] !== null);
        }

        return $env;
    }

    /**
     * The store identifiers a product carries, keyed by RevenueCat app type.
     *
     * @param  array<string, mixed>  $product
     * @return array<string, string>
     */
    private static function storeIdentifiers(array $product): array
    {
        return array_filter([
            'app_store' => $product['refs'][BillingCatalogue::CHANNEL_APP_STORE],
            'play_store' => $product['refs'][BillingCatalogue::CHANNEL_PLAY],
        ], static fn (?string $identifier): bool => $identifier !== null);
    }

    /**
     * One channel's prices with a display figure beside each amount.
     *
     * @param  array<string, mixed>  $product
     * @return array<string, array{amount_minor: int, display: string, source: string}>|stdClass
     */
    private static function prices(array $product, string $channel): array|stdClass
    {
        $table = PriceTable::for($product, $channel, BillingCatalogue::pricing());
        $prices = PriceTable::display($table);

        // The agent needs to know which figures it may override in a store
        // dashboard and which follow the web price, so `source` rides along.
        foreach ($prices as $currency => $price) {
            $prices[$currency] = $price + [
                'source' => $table[$currency]['source'],
            ];
        }

        return JsonObject::map($prices);
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private static function referenceName(array $product): string
    {
        return self::tierName($product['tier']) . ' ' . ucfirst((string) $product['cycle']);
    }

    private static function tierName(string $tier): string
    {
        $name = BillingCatalogue::tiers()[$tier]['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : $tier;
    }

    private static function presence(bool $present): string
    {
        return $present ? 'present' : 'absent';
    }
}
