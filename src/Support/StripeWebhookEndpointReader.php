<?php

namespace FlutterSdk\MagicStarter\Support;

use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;

/**
 * The events the Stripe webhook endpoint for a url is subscribed to, read through
 * Cashier's Stripe client.
 *
 * `billing:doctor --remote` resolves it from the container, so a test binds a
 * subclass instead of reaching Stripe. Read-only: an endpoint list is the only call.
 *
 * @phpstan-type RemoteEndpoint array{url: string, status: string, enabled_events: list<string>}
 */
class StripeWebhookEndpointReader
{
    /**
     * Stripe allows far fewer endpoints per account than one page holds.
     */
    protected const ENDPOINTS_PER_CALL = 100;

    /**
     * The union of the events of every ENABLED endpoint posting to [$url], or
     * null when none does. `*` is kept as Stripe writes it: it subscribes the
     * endpoint to every event.
     *
     * @return list<string>|null
     *
     * @throws ApiErrorException When Stripe refuses or cannot be reached.
     */
    public function enabledEventsFor(string $url): ?array
    {
        $events = null;

        foreach ($this->endpoints() as $endpoint) {
            if ($endpoint['url'] !== $url || $endpoint['status'] !== 'enabled') {
                continue;
            }

            $events = array_values(array_unique([...($events ?? []), ...$endpoint['enabled_events']]));
        }

        return $events;
    }

    /**
     * @return iterable<RemoteEndpoint>
     *
     * @throws ApiErrorException When Stripe refuses or cannot be reached.
     */
    protected function endpoints(): iterable
    {
        $page = Cashier::stripe()->webhookEndpoints->all(['limit' => self::ENDPOINTS_PER_CALL]);

        foreach ($page->data as $endpoint) {
            yield [
                'url' => $endpoint->url,
                'status' => $endpoint->status,
                'enabled_events' => array_values($endpoint->enabled_events),
            ];
        }
    }
}
