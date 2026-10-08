<?php

namespace FlutterSdk\MagicStarter\Support;

use LogicException;

/**
 * The one reader of the billing catalogue: tiers, their ranking, the products
 * that sell them, and the pricing rule behind store figures.
 *
 * One catalogue replaces three keys that described the same products from three
 * sides (`plans` for display, `prices` for Stripe, `store_products` for the
 * stores) and that nothing kept in step. A tier priced on the screen and mapped
 * on no rail, or mapped on Play under the wrong tier, was a config every reader
 * accepted on its own terms. Here a product names its tier, cycle, prices and
 * every rail's id in one entry, and every rail reads it through this class.
 *
 * {@see self::validate()} runs at boot under the billing feature, so the readers
 * below trust the shape they read: a catalogue that could sell the wrong thing
 * never reaches a request. They still skip what is not an array or not a string,
 * because a config value that is not even the right type is not a catalogue at
 * all, and a reader is the last place to discover that by a TypeError.
 *
 * @phpstan-type Refs array{stripe_price: ?string, app_store: ?string, play: ?string}
 * @phpstan-type Product array{
 *     key: string,
 *     type: string,
 *     tier: ?string,
 *     cycle: ?string,
 *     credits: ?int,
 *     sellable: bool,
 *     prices: array<string, mixed>,
 *     refs: Refs,
 * }
 * @phpstan-type Pricing array{currency: string, commission: array{mode: string, rate: float}}
 */
final class BillingCatalogue
{
    public const TYPE_SUBSCRIPTION = 'subscription';

    public const TYPE_CONSUMABLE = 'consumable';

    public const TYPE_NON_CONSUMABLE = 'non_consumable';

    public const TYPE_PHYSICAL = 'physical';

    /**
     * @var array<int, string>
     */
    public const TYPES = [
        self::TYPE_SUBSCRIPTION,
        self::TYPE_CONSUMABLE,
        self::TYPE_NON_CONSUMABLE,
        self::TYPE_PHYSICAL,
    ];

    public const CHANNEL_WEB = 'web';

    public const CHANNEL_APP_STORE = 'app_store';

    public const CHANNEL_PLAY = 'play';

    /**
     * The keys this catalogue replaced, and where their content lives now.
     *
     * `mergeConfigFrom()` is a SHALLOW merge, so a config published before the
     * catalogue existed replaces the whole `billing` array: it carries these
     * keys and none of the new ones. Booting it would leave every tier unranked
     * and every price unmapped with no error anywhere, so their presence is
     * refused by name. This list is the only reader of them; there is no alias.
     *
     * @var array<string, string>
     */
    public const REMOVED_KEYS = [
        'plans' => '[magic-starter.billing.tiers] (display) and [magic-starter.billing.tier_order] (ranking)',
        'prices' => 'refs.stripe_price on a [magic-starter.billing.products] entry',
        'store_products' => 'refs.app_store and refs.play on a [magic-starter.billing.products] entry',
    ];

    /**
     * Shipped when a published config predates the `pricing` block: absorbing
     * the store's cut is the rule that never raises a price nobody configured.
     */
    private const DEFAULT_CURRENCY = 'USD';

    private const DEFAULT_COMMISSION_MODE = 'absorb';

    private const DEFAULT_COMMISSION_RATE = 0.15;

    /**
     * Refuse a catalogue that would sell the wrong thing, naming the problem.
     *
     * A throw rather than a log, because every rule here guards money moving the
     * wrong way: a removed key means no tier is mapped at all, a floor with a
     * product means "free" can be bought, and one Play subscription under two
     * tiers means a base-plan change Play reports as a renewal silently moves a
     * customer between tiers.
     *
     * @throws LogicException Naming the offending key, product or tier.
     */
    public static function validate(): void
    {
        // 1. A pre-catalogue config, before anything else reads a key it lacks.
        foreach (self::REMOVED_KEYS as $key => $replacement) {
            if (config()->has("magic-starter.billing.{$key}")) {
                throw new LogicException(sprintf(
                    '[magic-starter.billing.%s] was removed; move its content to %s.',
                    $key,
                    $replacement,
                ));
            }
        }

        // 2. A ranking, because the floor and every cross-rail decision read it.
        $tierOrder = self::tierOrder();

        if ($tierOrder === []) {
            throw new LogicException(
                '[magic-starter.billing.tier_order] is empty; list your tier ids cheapest first, '
                . 'starting with the free floor.',
            );
        }

        // 3. Each product's own shape.
        foreach (self::configuredProducts() as $key => $product) {
            self::validateProduct((string) $key, $product, $tierOrder);
        }

        $products = self::products();

        // 4. The floor is what a customer holds for free, so nothing sells it.
        foreach ($products as $product) {
            if ($product['tier'] === $tierOrder[0]) {
                throw new LogicException(sprintf(
                    'Product [%s] sells [%s], the free floor (the first entry of '
                    . '[magic-starter.billing.tier_order]); the floor has no sellable product.',
                    $product['key'],
                    $tierOrder[0],
                ));
            }
        }

        // 5. A tier and cycle are sold by one product, so a checkout never has to
        //    choose between two prices for what the screen shows as one.
        self::validateOneSellablePerTierAndCycle($products);

        // 6. Store ids name one product each, and a Play subscription one tier.
        self::validateStoreIds($products);
    }

    /**
     * The tier ids, cheapest first. Entries that are not a non-empty string are
     * dropped rather than cast: stringifying one would invent a tier.
     *
     * @return list<string>
     */
    public static function tierOrder(): array
    {
        $configured = config('magic-starter.billing.tier_order', []);

        if (! is_array($configured)) {
            return [];
        }

        $ids = [];

        foreach ($configured as $tier) {
            if (is_string($tier) && $tier !== '') {
                $ids[] = $tier;
            }
        }

        return $ids;
    }

    /**
     * The free floor: the first tier of the ranking, or null when none is
     * published.
     */
    public static function floor(): ?string
    {
        return self::tierOrder()[0] ?? null;
    }

    /**
     * Every ranked tier's display definition, in ranking order, with its `id`.
     *
     * The ranking decides membership and order; the `tiers` map's own order
     * never does. A ranked tier with no definition is served as its bare id
     * rather than dropped, because hiding a tier the rails sell would leave a
     * customer holding something the screen cannot name. Every other key on a
     * definition travels untouched: what a tier caps or unlocks is the
     * application's knowledge, not this package's.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function tiers(): array
    {
        $definitions = config('magic-starter.billing.tiers', []);
        $definitions = is_array($definitions) ? $definitions : [];

        $tiers = [];

        foreach (self::tierOrder() as $id) {
            $definition = $definitions[$id] ?? [];

            $tiers[$id] = ['id' => $id] + (is_array($definition) ? $definition : []);
        }

        return $tiers;
    }

    /**
     * Every product, normalised, keyed by its catalogue key in config order.
     *
     * @return array<string, Product>
     */
    public static function products(): array
    {
        $products = [];

        foreach (self::configuredProducts() as $key => $product) {
            $key = (string) $key;
            $products[$key] = self::normalise($key, $product);
        }

        return $products;
    }

    /**
     * @return Product|null
     */
    public static function product(string $key): ?array
    {
        return self::products()[$key] ?? null;
    }

    /**
     * The product a Stripe price sells, or null when none does.
     *
     * Null is a config gap and never a downgrade: a caller that cannot name the
     * tier leaves the entitlement alone and warns. The first product carrying
     * the price wins, in config order.
     *
     * @return Product|null
     */
    public static function productForStripePrice(?string $priceId): ?array
    {
        return self::firstWithRef('stripe_price', $priceId);
    }

    /**
     * The product an App Store or Play product id sells, matched EXACTLY.
     *
     * Play reports `<subscription_id>:<base_plan_id>`, and that whole string is
     * the ref; a bare subscription id names no product.
     *
     * @return Product|null
     */
    public static function productForStoreId(?string $storeId): ?array
    {
        return self::firstWithRef(self::CHANNEL_APP_STORE, $storeId)
            ?? self::firstWithRef(self::CHANNEL_PLAY, $storeId);
    }

    /**
     * The base currency and the commission rule store prices derive under.
     *
     * @return Pricing
     */
    public static function pricing(): array
    {
        $pricing = config('magic-starter.billing.pricing', []);
        $pricing = is_array($pricing) ? $pricing : [];
        $commission = is_array($pricing['commission'] ?? null) ? $pricing['commission'] : [];

        $currency = $pricing['currency'] ?? null;
        $mode = $commission['mode'] ?? null;
        $rate = $commission['rate'] ?? null;

        return [
            'currency' => is_string($currency) && $currency !== '' ? strtoupper($currency) : self::DEFAULT_CURRENCY,
            'commission' => [
                'mode' => is_string($mode) && $mode !== '' ? $mode : self::DEFAULT_COMMISSION_MODE,
                'rate' => is_int($rate) || is_float($rate) ? (float) $rate : self::DEFAULT_COMMISSION_RATE,
            ],
        ];
    }

    /**
     * Refuse a product whose type is unknown or whose `sellable` is not a
     * boolean, or a subscription that does not name a ranked tier and a known
     * cycle.
     *
     * @param  list<string>  $tierOrder
     *
     * @throws LogicException
     */
    private static function validateProduct(string $key, mixed $product, array $tierOrder): void
    {
        $type = is_array($product) ? ($product['type'] ?? null) : null;

        if (! in_array($type, self::TYPES, true)) {
            throw new LogicException(sprintf(
                'Product [%s] has type [%s]; use one of [%s].',
                $key,
                is_string($type) ? $type : get_debug_type($type),
                implode(', ', self::TYPES),
            ));
        }

        // A string such as 'no' is truthy to a loose reader, so a typo would keep
        // a retired product on sale; refusing it by name is the only safe answer.
        if (array_key_exists('sellable', $product) && ! is_bool($product['sellable'])) {
            throw new LogicException(sprintf(
                'Product [%s] has [sellable] of type [%s]; use true or false.',
                $key,
                get_debug_type($product['sellable']),
            ));
        }

        if ($type !== self::TYPE_SUBSCRIPTION) {
            return;
        }

        $tier = $product['tier'] ?? null;
        $cycle = $product['cycle'] ?? null;

        if (! is_string($tier) || $tier === '') {
            throw new LogicException(sprintf(
                'Subscription product [%s] names no tier; set its [tier] to an id from '
                . '[magic-starter.billing.tier_order].',
                $key,
            ));
        }

        if (! in_array($tier, $tierOrder, true)) {
            throw new LogicException(sprintf(
                'Subscription product [%s] sells tier [%s], which is not in [magic-starter.billing.tier_order].',
                $key,
                $tier,
            ));
        }

        if (! in_array($cycle, StripeSubscriptionState::CYCLES, true)) {
            throw new LogicException(sprintf(
                'Subscription product [%s] has cycle [%s]; set its [cycle] to one of [%s].',
                $key,
                is_string($cycle) ? $cycle : get_debug_type($cycle),
                implode(', ', StripeSubscriptionState::CYCLES),
            ));
        }
    }

    /**
     * Refuse two sellable subscriptions on the same tier and cycle.
     *
     * A retired price that must stay mapped is the legitimate second product for
     * a pair, and `sellable: false` is how it says so. Two products both for
     * sale would make the plans screen list one tier and cycle twice, at
     * figures a customer could not tell apart.
     *
     * @param  array<string, Product>  $products
     *
     * @throws LogicException
     */
    private static function validateOneSellablePerTierAndCycle(array $products): void
    {
        $owners = [];

        foreach ($products as $product) {
            if ($product['type'] !== self::TYPE_SUBSCRIPTION || ! $product['sellable']) {
                continue;
            }

            $pair = $product['tier'] . '|' . $product['cycle'];

            if (isset($owners[$pair])) {
                throw new LogicException(sprintf(
                    'Subscription products [%s] and [%s] both sell tier [%s] on cycle [%s]; keep one sellable '
                    . 'and set [sellable] to false on a product kept only so an old price still maps.',
                    $owners[$pair],
                    $product['key'],
                    $product['tier'],
                    $product['cycle'],
                ));
            }

            $owners[$pair] = $product['key'];
        }
    }

    /**
     * Refuse a store id on two products, and a Play subscription id whose base
     * plans sell two different tiers.
     *
     * The Play rule is the one a duplicate check cannot see: `pro_sub:monthly`
     * and `pro_sub:annual` are different ids, but a base-plan change inside one
     * subscription reaches the rail as a renewal of the same purchase, so the
     * two must grant the same tier or the tier moves without anybody deciding.
     *
     * @param  array<string, Product>  $products
     *
     * @throws LogicException
     */
    private static function validateStoreIds(array $products): void
    {
        $owners = [];
        $playTiers = [];

        foreach ($products as $product) {
            foreach ([self::CHANNEL_APP_STORE, self::CHANNEL_PLAY] as $channel) {
                $storeId = $product['refs'][$channel];

                if ($storeId === null) {
                    continue;
                }

                $owner = $owners[$storeId] ?? $product['key'];

                if ($owner !== $product['key']) {
                    throw new LogicException(sprintf(
                        'Store product id [%s] is on both [%s] and [%s]; a store id names one product.',
                        $storeId,
                        $owner,
                        $product['key'],
                    ));
                }

                $owners[$storeId] = $owner;
            }

            $play = $product['refs'][self::CHANNEL_PLAY];

            if ($play === null || $product['tier'] === null) {
                continue;
            }

            $subscriptionId = explode(':', $play, 2)[0];
            $tier = $playTiers[$subscriptionId] ?? $product['tier'];

            if ($tier !== $product['tier']) {
                throw new LogicException(sprintf(
                    'Play subscription [%s] sells both [%s] and [%s]; give each tier its own Play subscription.',
                    $subscriptionId,
                    $tier,
                    $product['tier'],
                ));
            }

            $playTiers[$subscriptionId] = $tier;
        }
    }

    /**
     * @return Product|null
     */
    private static function firstWithRef(string $ref, ?string $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        foreach (self::products() as $product) {
            if ($product['refs'][$ref] === $value) {
                return $product;
            }
        }

        return null;
    }

    /**
     * The configured products that are at least arrays, keys untouched.
     *
     * @return array<array-key, mixed>
     */
    private static function configuredProducts(): array
    {
        $configured = config('magic-starter.billing.products', []);

        return is_array($configured) ? $configured : [];
    }

    /**
     * One product in the shape every reader relies on.
     *
     * An EMPTY ref is null, and that is the guard that matters: refs are
     * normally assembled from the environment, and an unset variable writes an
     * empty string that a reverse lookup would otherwise return as the price of
     * a paid tier.
     *
     * @return Product
     */
    private static function normalise(string $key, mixed $product): array
    {
        $product = is_array($product) ? $product : [];
        $refs = is_array($product['refs'] ?? null) ? $product['refs'] : [];

        return [
            'key' => $key,
            'type' => self::stringOrNull($product['type'] ?? null) ?? '',
            'tier' => self::stringOrNull($product['tier'] ?? null),
            'cycle' => self::stringOrNull($product['cycle'] ?? null),
            'credits' => is_int($product['credits'] ?? null) ? $product['credits'] : null,
            'sellable' => ($product['sellable'] ?? true) === true,
            'prices' => is_array($product['prices'] ?? null) ? $product['prices'] : [],
            'refs' => [
                'stripe_price' => self::stringOrNull($refs['stripe_price'] ?? null),
                'app_store' => self::stringOrNull($refs[self::CHANNEL_APP_STORE] ?? null),
                'play' => self::stringOrNull($refs[self::CHANNEL_PLAY] ?? null),
            ],
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
