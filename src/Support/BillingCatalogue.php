<?php

namespace FlutterSdk\MagicStarter\Support;

use BackedEnum;
use FlutterSdk\MagicStarter\Enums\BillingChannel;
use FlutterSdk\MagicStarter\Enums\BillingCycle;
use FlutterSdk\MagicStarter\Enums\CommissionMode;
use FlutterSdk\MagicStarter\Enums\ProductType;
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
 *     type: ?ProductType,
 *     tier: ?string,
 *     cycle: ?BillingCycle,
 *     credits: ?int,
 *     trial_days: int,
 *     sellable: bool,
 *     prices: array<string, mixed>,
 *     refs: Refs,
 * }
 * @phpstan-type Pricing array{currency: string, commission: array{mode: CommissionMode, rate: float}}
 */
final class BillingCatalogue
{
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

    private const DEFAULT_COMMISSION_MODE = CommissionMode::ABSORB;

    private const DEFAULT_COMMISSION_RATE = 0.15;

    /**
     * The last configured products and what they normalised to.
     *
     * Keyed by the raw config array itself rather than by time or request,
     * because normalising is a pure function of that array: an equal input
     * can only give the same products, so no config change, test rewrite or
     * Octane request can be served a stale catalogue. The comparison is cheap
     * where it matters, since PHP answers `===` on two copies of one array
     * from its identity before comparing a single element, and an unchanged
     * config hands back the same array every time.
     *
     * @var array{configured: array<array-key, mixed>, products: array<string, Product>}|null
     */
    private static ?array $normalised = null;

    /**
     * Refuse a catalogue that would sell the wrong thing, naming the problem.
     *
     * A throw rather than a log, because every rule here guards money moving the
     * wrong way: a removed key means no tier is mapped at all, a floor with a
     * product means "free" can be bought, and one Play subscription under two
     * tiers means a base-plan change Play reports as a renewal silently moves a
     * customer between tiers.
     *
     * The billing gate catches it in exactly one process, `billing:doctor`,
     * which reports it; everywhere else it stops boot.
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

        // 3. The rule every store price derives under, then each product's own
        //    shape: a price nobody can derive is refused before any request.
        self::validatePricing();

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

        // 6. Every rail id names one product, and a Play subscription one tier.
        self::validateStripePrices($products);
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
     * Normalised once per distinct config (see {@see self::$normalised}),
     * since every reverse lookup below walks the whole list.
     *
     * @return array<string, Product>
     */
    public static function products(): array
    {
        $configured = self::configuredProducts();

        if (self::$normalised !== null && self::$normalised['configured'] === $configured) {
            return self::$normalised['products'];
        }

        $products = [];

        foreach ($configured as $key => $product) {
            $key = (string) $key;
            $products[$key] = self::normalise($key, $product);
        }

        self::$normalised = [
            'configured' => $configured,
            'products' => $products,
        ];

        return $products;
    }

    /**
     * Whether any product offers a trial.
     *
     * The gate every trial reader asks first (the plans endpoint, the
     * reconciler's trial sweep, the doctor), because it is config alone: an
     * adopter selling without trials answers false here and never touches the
     * `billing_trials` table, which they need not have migrated.
     */
    public static function offersTrials(): bool
    {
        return self::trialProductKeys() !== [];
    }

    /**
     * The keys of the products that offer a trial, in config order.
     *
     * @return list<string>
     */
    public static function trialProductKeys(): array
    {
        $keys = [];

        foreach (self::products() as $product) {
            if ($product['trial_days'] > 0) {
                $keys[] = $product['key'];
            }
        }

        return $keys;
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
     * The product behind a rail's own id, as a billable's `plan_product_id`
     * stores it: a Stripe price first, then a store id.
     *
     * One order for every reader, because the entitlement read and the wire
     * would otherwise name different products for one id that both a Stripe
     * price and a store id carry.
     *
     * @return Product|null
     */
    public static function productForRailId(?string $id): ?array
    {
        return self::productForStripePrice($id) ?? self::productForStoreId($id);
    }

    /**
     * The subscription id of a Play ref `<subscription_id>:<base_plan_id>`.
     *
     * The subscription is what Play moves a customer within (a base-plan change
     * reaches the rail as a renewal), so it is the unit the catalogue keeps to
     * one tier and the unit a bare Play id from the v1 API names.
     */
    public static function playSubscriptionId(string $playRef): string
    {
        return explode(':', $playRef, 2)[0];
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
        return self::firstWithRef(BillingChannel::APP_STORE->value, $storeId)
            ?? self::firstWithRef(BillingChannel::PLAY->value, $storeId);
    }

    /**
     * The base currency and the commission rule store prices derive under.
     *
     * An absent or empty mode is the default; a mode that names nothing is
     * refused here rather than defaulted, because absorbing a cut the adopter
     * asked to pass on would sell every store product below its figure.
     *
     * @return Pricing
     *
     * @throws LogicException When the configured commission mode is not one this package knows.
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
                'mode' => is_string($mode) && $mode !== '' ? self::commissionMode($mode) : self::DEFAULT_COMMISSION_MODE,
                'rate' => is_int($rate) || is_float($rate) ? (float) $rate : self::DEFAULT_COMMISSION_RATE,
            ],
        ];
    }

    /**
     * Refuse a product whose type is unknown, whose `sellable` is not a
     * boolean or whose `trial_days` is not one Stripe honours, or a
     * subscription that does not name a ranked tier and a known cycle.
     *
     * @param  list<string>  $tierOrder
     *
     * @throws LogicException
     */
    private static function validateProduct(string $key, mixed $product, array $tierOrder): void
    {
        $type = is_array($product) ? ($product['type'] ?? null) : null;
        $productType = is_string($type) ? ProductType::tryFrom($type) : null;

        if ($productType === null) {
            throw new LogicException(sprintf(
                'Product [%s] has type [%s]; use one of [%s].',
                $key,
                is_string($type) ? $type : get_debug_type($type),
                implode(', ', array_column(ProductType::cases(), 'value')),
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

        self::validateRefs($key, $product);
        self::validatePrices($key, $product);
        self::validateTrialDays($key, $product, $productType);

        if ($productType !== ProductType::SUBSCRIPTION) {
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

        if (! is_string($cycle) || BillingCycle::tryFrom($cycle) === null) {
            throw new LogicException(sprintf(
                'Subscription product [%s] has cycle [%s]; set its [cycle] to one of [%s].',
                $key,
                is_string($cycle) ? $cycle : get_debug_type($cycle),
                implode(', ', array_column(BillingCycle::cases(), 'value')),
            ));
        }
    }

    /**
     * Refuse a trial length Stripe would not honour as written.
     *
     * A string, a float or a negative number would read as no trial and a
     * customer would be charged on day one against what the screen promised;
     * one day would be silently stretched to two, because Stripe Checkout (and
     * so Cashier) enforces a minimum of 48 hours. A trial on a product that is
     * not a subscription has nothing to defer, so it is a mistake, not a no-op.
     *
     * @param  array<array-key, mixed>  $product
     *
     * @throws LogicException
     */
    private static function validateTrialDays(string $key, array $product, ProductType $type): void
    {
        if (! array_key_exists('trial_days', $product)) {
            return;
        }

        $days = $product['trial_days'];

        if (! is_int($days) || $days < 0) {
            throw new LogicException(sprintf(
                'Product [%s] has [trial_days] of [%s]; use a whole number of days, 0 or more.',
                $key,
                is_int($days) ? $days : get_debug_type($days),
            ));
        }

        if ($days === 1) {
            throw new LogicException(sprintf(
                'Product [%s] has [trial_days] of [1]; Stripe Checkout enforces a minimum of 48 hours, '
                . 'so use 0 for no trial or 2 or more days.',
                $key,
            ));
        }

        if ($days > 0 && $type !== ProductType::SUBSCRIPTION) {
            throw new LogicException(sprintf(
                'Product [%s] is a [%s] with [trial_days] of [%d]; only a subscription can trial, '
                . 'so remove the key or set it to 0.',
                $key,
                $type->value,
                $days,
            ));
        }
    }

    /**
     * Refuse a store ref in the other store's shape.
     *
     * The Play form is `<subscription_id>:<base_plan_id>`, exactly two parts,
     * because that composed id is what a purchase is matched against; a bare
     * subscription id would match nothing. An App Store id never carries a
     * colon, so one that does is a Play ref pasted into the wrong slot.
     *
     * @param  array<array-key, mixed>  $product
     *
     * @throws LogicException
     */
    private static function validateRefs(string $key, array $product): void
    {
        $refs = is_array($product['refs'] ?? null) ? $product['refs'] : [];
        $play = self::stringOrNull($refs[BillingChannel::PLAY->value] ?? null);
        $appStore = self::stringOrNull($refs[BillingChannel::APP_STORE->value] ?? null);

        if ($play !== null && preg_match('/^[^:]+:[^:]+$/', $play) !== 1) {
            throw new LogicException(sprintf(
                'Product [%s] has refs.play [%s]; write it as <subscription_id>:<base_plan_id>.',
                $key,
                $play,
            ));
        }

        if ($appStore !== null && str_contains($appStore, ':')) {
            throw new LogicException(sprintf(
                'Product [%s] has refs.app_store [%s]; an App Store product id has no colon '
                . '(the <subscription_id>:<base_plan_id> form belongs in refs.play).',
                $key,
                $appStore,
            ));
        }
    }

    /**
     * Refuse a price table that is not channel => currency => minor units.
     *
     * {@see PriceTable} skips what it cannot read, which is right for a reader
     * and wrong for the catalogue: a float or a string amount, an unknown
     * channel or a misspelt currency would drop out of every price list with no
     * trace, and the tier would show as unpriced on that channel.
     *
     * @param  array<array-key, mixed>  $product
     *
     * @throws LogicException
     */
    private static function validatePrices(string $key, array $product): void
    {
        if (! array_key_exists('prices', $product)) {
            return;
        }

        if (! is_array($product['prices'])) {
            throw new LogicException(sprintf(
                'Product [%s] has [prices] of type [%s]; use channel => currency => amount in minor units.',
                $key,
                get_debug_type($product['prices']),
            ));
        }

        foreach ($product['prices'] as $channel => $currencies) {
            if (! is_string($channel) || BillingChannel::tryFrom($channel) === null || ! is_array($currencies)) {
                throw new LogicException(sprintf(
                    'Product [%s] prices channel [%s]; use one of [%s], each a map of currency => amount.',
                    $key,
                    $channel,
                    implode(', ', array_column(BillingChannel::cases(), 'value')),
                ));
            }

            foreach ($currencies as $currency => $amount) {
                if (! is_string($currency) || preg_match('/^[A-Za-z]{3}$/', $currency) !== 1) {
                    throw new LogicException(sprintf(
                        'Product [%s] prices [%s] in currency [%s]; use a three-letter ISO 4217 code.',
                        $key,
                        $channel,
                        $currency,
                    ));
                }

                if (! is_int($amount) || $amount < 0) {
                    throw new LogicException(sprintf(
                        'Product [%s] prices [%s] [%s] at [%s]; use a whole amount in minor units, 0 or more.',
                        $key,
                        $channel,
                        $currency,
                        is_scalar($amount) ? (string) $amount : get_debug_type($amount),
                    ));
                }
            }
        }
    }

    /**
     * Refuse a commission rule {@see PriceTable} cannot derive under.
     *
     * Read from the raw config rather than through {@see self::pricing()},
     * which falls back to the default rate for a value that is not a number:
     * a rate written as the string `'0.30'` would otherwise price every store
     * product at the default cut with nothing said.
     *
     * @throws LogicException
     */
    private static function validatePricing(): void
    {
        $commission = config('magic-starter.billing.pricing.commission', []);
        $commission = is_array($commission) ? $commission : [];
        $mode = $commission['mode'] ?? null;

        if (array_key_exists('mode', $commission) && (! is_string($mode) || CommissionMode::tryFrom($mode) === null)) {
            throw new LogicException(sprintf(
                '[magic-starter.billing.pricing.commission.mode] is [%s]; use one of [%s].',
                is_scalar($mode) ? (string) $mode : get_debug_type($mode),
                implode(', ', array_column(CommissionMode::cases(), 'value')),
            ));
        }

        if (! array_key_exists('rate', $commission)) {
            return;
        }

        $rate = $commission['rate'];

        if (! (is_int($rate) || is_float($rate)) || $rate < 0 || $rate >= 1) {
            throw new LogicException(sprintf(
                '[magic-starter.billing.pricing.commission.rate] is [%s]; use a number of at least 0 and below 1.',
                is_scalar($rate) ? (string) $rate : get_debug_type($rate),
            ));
        }
    }

    /**
     * Refuse one Stripe price on two products.
     *
     * A webhook names a price and the catalogue answers with the first product
     * carrying it, so a second product on the same price would never be found:
     * the tier it grants would depend on the order the config was written in.
     * A grandfathered product keeps its OWN old price, not a sold one's.
     *
     * @param  array<string, Product>  $products
     *
     * @throws LogicException
     */
    private static function validateStripePrices(array $products): void
    {
        $owners = [];

        foreach ($products as $product) {
            $priceId = $product['refs']['stripe_price'];

            if ($priceId === null) {
                continue;
            }

            if (isset($owners[$priceId])) {
                throw new LogicException(sprintf(
                    'Stripe price [%s] is on both [%s] and [%s]; a Stripe price names one product.',
                    $priceId,
                    $owners[$priceId],
                    $product['key'],
                ));
            }

            $owners[$priceId] = $product['key'];
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
            if ($product['type'] !== ProductType::SUBSCRIPTION || ! $product['sellable']) {
                continue;
            }

            $pair = $product['tier'] . '|' . $product['cycle']?->value;

            if (isset($owners[$pair])) {
                throw new LogicException(sprintf(
                    'Subscription products [%s] and [%s] both sell tier [%s] on cycle [%s]; keep one sellable '
                    . 'and set [sellable] to false on a product kept only so an old price still maps.',
                    $owners[$pair],
                    $product['key'],
                    $product['tier'],
                    $product['cycle']?->value,
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
            foreach ([BillingChannel::APP_STORE, BillingChannel::PLAY] as $channel) {
                $storeId = $product['refs'][$channel->value];

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

            $play = $product['refs'][BillingChannel::PLAY->value];

            if ($play === null || $product['tier'] === null) {
                continue;
            }

            $subscriptionId = self::playSubscriptionId($play);
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
            'type' => self::enumOrNull(ProductType::class, $product['type'] ?? null),
            'tier' => self::stringOrNull($product['tier'] ?? null),
            'cycle' => self::enumOrNull(BillingCycle::class, $product['cycle'] ?? null),
            'credits' => is_int($product['credits'] ?? null) ? $product['credits'] : null,
            'trial_days' => is_int($product['trial_days'] ?? null) ? max(0, $product['trial_days']) : 0,
            'sellable' => ($product['sellable'] ?? true) === true,
            'prices' => is_array($product['prices'] ?? null) ? $product['prices'] : [],
            'refs' => [
                'stripe_price' => self::stringOrNull($refs['stripe_price'] ?? null),
                'app_store' => self::stringOrNull($refs[BillingChannel::APP_STORE->value] ?? null),
                'play' => self::stringOrNull($refs[BillingChannel::PLAY->value] ?? null),
            ],
        ];
    }

    /**
     * The case of [$enum] that [$value] names, or null.
     *
     * Null rather than a throw, because this is a reader: {@see self::validate()}
     * is where an unknown word is refused by name, at boot.
     *
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum|null
     */
    private static function enumOrNull(string $enum, mixed $value): ?BackedEnum
    {
        $value = self::stringOrNull($value);

        return $value === null ? null : $enum::tryFrom($value);
    }

    /**
     * @throws LogicException When [$mode] names no {@see CommissionMode}.
     */
    private static function commissionMode(string $mode): CommissionMode
    {
        return CommissionMode::tryFrom($mode) ?? throw new LogicException(sprintf(
            'Commission mode [%s] is not one of [%s]; set [magic-starter.billing.pricing.commission.mode].',
            $mode,
            implode(', ', array_column(CommissionMode::cases(), 'value')),
        ));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
