<?php

namespace FlutterSdk\MagicStarter\Support;

use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;

/**
 * The Stripe prices that answer to a set of lookup keys, read through Cashier's
 * Stripe client.
 *
 * `billing:doctor --remote` resolves it from the container, so a test binds a
 * subclass instead of reaching Stripe. Read-only: a price list is the only call.
 *
 * @phpstan-type RemotePrice array{
 *     id: string,
 *     currency: string,
 *     unit_amount: ?int,
 *     currency_options: array<string, ?int>,
 *     interval: ?string,
 * }
 */
class StripePriceReader
{
    /**
     * Stripe accepts at most ten lookup keys per list call.
     */
    protected const LOOKUP_KEYS_PER_CALL = 10;

    /**
     * The ACTIVE price per lookup key; a key Stripe does not know is absent.
     *
     * @param  list<string>  $lookupKeys
     * @return array<string, RemotePrice> Keyed by lookup key. Currencies are lowercase, as Stripe writes them;
     *                                    amounts are in the currency's minor unit.
     *
     * @throws ApiErrorException When Stripe refuses or cannot be reached.
     */
    public function byLookupKeys(array $lookupKeys): array
    {
        $prices = [];

        foreach (array_chunk($lookupKeys, self::LOOKUP_KEYS_PER_CALL) as $chunk) {
            $page = Cashier::stripe()->prices->all([
                'lookup_keys' => $chunk,
                'active' => true,
                'limit' => self::LOOKUP_KEYS_PER_CALL,
                // Not returned unless asked for.
                'expand' => ['data.currency_options'],
            ]);

            foreach ($page->data as $price) {
                if (is_string($price->lookup_key)) {
                    $prices[$price->lookup_key] = $this->normalise($price);
                }
            }
        }

        return $prices;
    }

    /**
     * @return RemotePrice
     */
    protected function normalise(Price $price): array
    {
        $options = [];

        foreach ($price->currency_options?->toArray() ?? [] as $currency => $option) {
            $amount = is_array($option) ? ($option['unit_amount'] ?? null) : null;
            $options[(string) $currency] = is_int($amount) ? $amount : null;
        }

        return [
            'id' => $price->id,
            'currency' => $price->currency,
            'unit_amount' => $price->unit_amount,
            'currency_options' => $options,
            'interval' => $price->recurring?->interval,
        ];
    }
}
