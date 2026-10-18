<?php

namespace FlutterSdk\MagicStarter\Support;

use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;

/**
 * The Stripe objects the billing flow reads, through Cashier's Stripe client:
 * the prices that answer to a set of lookup keys, the account's webhook
 * endpoints, and one completed Checkout Session.
 *
 * `billing:doctor --remote` and the Stripe webhook controller resolve it from
 * the container, so a test binds a subclass instead of reaching Stripe.
 * Read-only: every call is a list or a retrieve.
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
     * Every webhook endpoint the Stripe account holds.
     *
     * @return list<array{url: string, enabled_events: list<string>}> `enabled_events` holds `*` for an
     *                                                                endpoint that listens to everything.
     *
     * @throws ApiErrorException When Stripe refuses or cannot be reached.
     */
    public function webhookEndpoints(): array
    {
        $endpoints = [];

        foreach (Cashier::stripe()->webhookEndpoints->all(['limit' => 100])->autoPagingIterator() as $endpoint) {
            $endpoints[] = [
                'url' => $endpoint->url,
                'enabled_events' => array_values($endpoint->enabled_events),
            ];
        }

        return $endpoints;
    }

    /**
     * One Checkout Session with its `discounts` expanded, as the array Stripe sends.
     *
     * For a `checkout.session.completed` delivery whose payload carries no
     * discounts: the promotion code is the id string in `discounts.*.promotion_code`.
     *
     * @return array<string, mixed>
     *
     * @throws ApiErrorException When Stripe refuses or cannot be reached.
     */
    public function checkoutSession(string $sessionId): array
    {
        return Cashier::stripe()->checkout->sessions
            ->retrieve($sessionId, ['expand' => ['discounts']])
            ->toArray();
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
