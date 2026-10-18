<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Support\StripeWebhookEndpointReader;
use FlutterSdk\MagicStarter\Tests\TestCase;

/**
 * The Stripe webhook endpoint read `billing:doctor --remote` checks the lifecycle mail events against, driven
 * through Cashier's real Stripe client with only the transport faked: the one list call it makes, and how the
 * endpoints Stripe answers are reduced to the events of the enabled ones posting to the url asked about.
 */
final class StripeWebhookEndpointReaderTest extends TestCase
{
    private StripeHttpStub $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashier.secret' => 'sk_test_webhook_endpoint_reader']);

        $this->stripe = StripeHttpStub::install();
    }

    protected function tearDown(): void
    {
        StripeHttpStub::uninstall();

        parent::tearDown();
    }

    public function test_it_lists_the_endpoints_in_one_call(): void
    {
        $this->stripe->answer($this->page([]));

        (new StripeWebhookEndpointReader)->enabledEventsFor('https://app.example.test/stripe/webhook');

        $requests = $this->stripe->requestsTo('get', '/v1/webhook_endpoints');
        $this->assertCount(1, $requests);
        $this->assertSame(100, (int) $requests[0]['params']['limit']);
    }

    public function test_the_events_of_every_enabled_endpoint_on_the_url_are_merged(): void
    {
        $url = 'https://app.example.test/stripe/webhook';

        $this->stripe->answer($this->page([
            $this->endpoint($url, 'enabled', ['customer.subscription.trial_will_end', 'invoice.payment_succeeded']),
            $this->endpoint($url, 'enabled', ['invoice.payment_failed', 'invoice.payment_succeeded']),
            $this->endpoint($url, 'disabled', ['customer.deleted']),
            $this->endpoint('https://elsewhere.example.test/hook', 'enabled', ['charge.refunded']),
        ]));

        $events = (new StripeWebhookEndpointReader)->enabledEventsFor($url);

        $this->assertSame([
            'customer.subscription.trial_will_end',
            'invoice.payment_succeeded',
            'invoice.payment_failed',
        ], $events);
    }

    public function test_no_enabled_endpoint_on_the_url_answers_null(): void
    {
        $this->stripe->answer($this->page([
            $this->endpoint('https://app.example.test/stripe/webhook', 'disabled', ['invoice.payment_failed']),
        ]));

        $this->assertNull((new StripeWebhookEndpointReader)->enabledEventsFor('https://app.example.test/stripe/webhook'));
    }

    /**
     * @param  list<array<string, mixed>>  $endpoints
     * @return array<string, mixed>
     */
    private function page(array $endpoints): array
    {
        return [
            'object' => 'list',
            'url' => '/v1/webhook_endpoints',
            'has_more' => false,
            'data' => $endpoints,
        ];
    }

    /**
     * @param  list<string>  $events
     * @return array<string, mixed>
     */
    private function endpoint(string $url, string $status, array $events): array
    {
        return [
            'id' => 'we_' . substr(md5($url . $status . implode(',', $events)), 0, 12),
            'object' => 'webhook_endpoint',
            'url' => $url,
            'status' => $status,
            'enabled_events' => $events,
        ];
    }
}
