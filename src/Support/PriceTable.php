<?php

namespace FlutterSdk\MagicStarter\Support;

use LogicException;

/**
 * The price a product sells at on one channel, explicit or derived from web.
 *
 * An adopter writes the WEB price and, where a store needs a different figure,
 * an explicit store price. Everything else on a store channel is derived from
 * the same currency's web price under the catalogue's commission rule, so a
 * store price exists without being typed twice and cannot drift from web by
 * accident.
 *
 * Two derivations are refused on purpose. The web channel is never derived: it
 * is the source, and deriving it from a store figure would run the commission
 * backwards. And a currency is never converted: a store channel offers only the
 * currencies the web channel prices, because an exchange rate is a decision
 * about money this package has no business taking, and a rate frozen into
 * config is wrong the day after it is written.
 */
final class PriceTable
{
    /**
     * The adopter wrote this figure for this channel.
     */
    public const SOURCE_EXPLICIT = 'explicit';

    /**
     * The figure was computed from the same currency's web price.
     */
    public const SOURCE_DERIVED = 'derived';

    /**
     * The store's cut comes out of the web price: the store channel charges the
     * web figure and the adopter nets less.
     */
    public const MODE_ABSORB = 'absorb';

    /**
     * The store channel charges more, so the adopter nets the web figure after
     * the store's cut.
     */
    public const MODE_GROSS_UP = 'gross_up';

    /**
     * The rate is applied in parts per million so the gross-up stays integer
     * arithmetic: `3400 / 0.85` in floats lands a hair above 4000 and a ceil
     * would then charge 4001.
     */
    private const RATE_SCALE = 1_000_000;

    /**
     * Every currency [$product] sells in on [$channel], with where the figure
     * came from.
     *
     * @param  array<string, mixed>  $product  A catalogue product; only its `prices` (channel => currency =>
     *                                         amount_minor) is read.
     * @param  string  $channel  `web`, `app_store` or `play`.
     * @param  array{currency: string, commission: array{mode: string, rate: float}}  $pricing  The catalogue's
     *                                                                                          `pricing` block.
     * @return array<string, array{amount_minor: int, source: string}> Keyed by uppercase ISO 4217 code.
     *
     * @throws LogicException When the commission mode is not one this table knows.
     */
    public static function for(array $product, string $channel, array $pricing): array
    {
        $prices = is_array($product['prices'] ?? null) ? $product['prices'] : [];
        $explicit = self::amounts($prices[$channel] ?? []);

        // 1. Explicit figures win outright, on every channel.
        $table = array_map(
            static fn (int $amount): array => ['amount_minor' => $amount, 'source' => self::SOURCE_EXPLICIT],
            $explicit,
        );

        if ($channel === BillingCatalogue::CHANNEL_WEB) {
            return $table;
        }

        // 2. A store channel derives every remaining currency the web channel
        //    prices, and only those: a currency web does not price is absent.
        foreach (self::amounts($prices[BillingCatalogue::CHANNEL_WEB] ?? []) as $currency => $web) {
            if (isset($table[$currency])) {
                continue;
            }

            $table[$currency] = [
                'amount_minor' => self::derive($web, $pricing['commission']),
                'source' => self::SOURCE_DERIVED,
            ];
        }

        return $table;
    }

    /**
     * Apply the commission rule to a web price.
     *
     * @param  int  $web  Web price in the currency's minor unit.
     * @param  array{mode: string, rate: float}  $commission
     *
     * @throws LogicException When the mode is unknown or the rate leaves nothing to divide by.
     */
    private static function derive(int $web, array $commission): int
    {
        return match ($commission['mode']) {
            self::MODE_ABSORB => $web,
            self::MODE_GROSS_UP => self::grossUp($web, $commission['rate']),
            default => throw new LogicException(sprintf(
                'Commission mode [%s] is not one of [%s]; set [magic-starter.billing.pricing.commission.mode].',
                $commission['mode'],
                implode(', ', [self::MODE_ABSORB, self::MODE_GROSS_UP]),
            )),
        };
    }

    /**
     * `ceil(web / (1 - rate))` in integer minor units, rounded UP so the net
     * after the store's cut never falls below the web price.
     *
     * @param  float  $rate  The store's share, a fraction in [0, 1).
     */
    private static function grossUp(int $web, float $rate): int
    {
        $kept = self::RATE_SCALE - (int) round($rate * self::RATE_SCALE);

        if ($kept <= 0 || $kept > self::RATE_SCALE) {
            throw new LogicException(sprintf(
                'Commission rate [%s] must be at least 0 and below 1; '
                . 'set [magic-starter.billing.pricing.commission.rate].',
                $rate,
            ));
        }

        return intdiv($web * self::RATE_SCALE + $kept - 1, $kept);
    }

    /**
     * Keep the integer amounts of one channel, keyed by uppercase currency.
     *
     * @return array<string, int>
     */
    private static function amounts(mixed $configured): array
    {
        if (! is_array($configured)) {
            return [];
        }

        $amounts = [];

        foreach ($configured as $currency => $amount) {
            if (is_int($amount)) {
                $amounts[strtoupper((string) $currency)] = $amount;
            }
        }

        return $amounts;
    }
}
