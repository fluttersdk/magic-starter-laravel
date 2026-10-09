<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Support\StripePriceReader;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Stripe\ApiRequestor;
use Stripe\Exception\AuthenticationException;
use Stripe\HttpClient\ClientInterface;

/**
 * The Stripe price read `billing:doctor --remote` diffs against the manifest,
 * driven through Cashier's real Stripe client with only the transport faked:
 * what is asked of Stripe, and how its answer is reduced to the fields the
 * doctor compares.
 */
class StripePriceReaderTest extends TestCase
{
    private StripeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashier.secret' => 'sk_test_price_reader']);

        $this->transport = new StripeTransport;
        ApiRequestor::setHttpClient($this->transport);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_active_prices_are_asked_for_with_their_currency_options(): void
    {
        $this->transport->answers[] = $this->page([]);

        (new StripePriceReader)->byLookupKeys([
            'pro_monthly',
            'pro_annual',
        ]);

        $this->assertCount(1, $this->transport->requests);
        $request = $this->transport->requests[0];
        $this->assertSame('get', $request['method']);
        $this->assertStringEndsWith('/v1/prices', $request['url']);
        $this->assertSame(['pro_monthly', 'pro_annual'], $request['params']['lookup_keys']);
        // The SDK puts a boolean on the wire as the string Stripe parses.
        $this->assertSame('true', $request['params']['active']);
        $this->assertSame(['data.currency_options'], $request['params']['expand']);
    }

    public function test_each_price_is_keyed_by_its_lookup_key_with_amounts_per_currency(): void
    {
        $this->transport->answers[] = $this->page([
            [
                'id' => 'price_pro_annual',
                'object' => 'price',
                'lookup_key' => 'pro_annual',
                'currency' => 'usd',
                'unit_amount' => 29000,
                'recurring' => ['interval' => 'year'],
                'currency_options' => [
                    'usd' => ['unit_amount' => 29000],
                    'try' => ['unit_amount' => 499000],
                    // A customer-chosen amount carries no unit_amount.
                    'eur' => ['unit_amount' => null],
                ],
            ],
            [
                'id' => 'price_one_off',
                'object' => 'price',
                'lookup_key' => 'setup_fee',
                'currency' => 'usd',
                'unit_amount' => 500,
                'recurring' => null,
                'currency_options' => [
                    'usd' => ['unit_amount' => 500],
                ],
            ],
            [
                'id' => 'price_unkeyed',
                'object' => 'price',
                'lookup_key' => null,
                'currency' => 'usd',
                'unit_amount' => 100,
                'recurring' => null,
                'currency_options' => [
                    'usd' => ['unit_amount' => 100],
                ],
            ],
        ]);

        $prices = (new StripePriceReader)->byLookupKeys([
            'pro_annual',
            'setup_fee',
        ]);

        $this->assertSame(
            [
                'pro_annual' => [
                    'id' => 'price_pro_annual',
                    'currency' => 'usd',
                    'unit_amount' => 29000,
                    'currency_options' => [
                        'usd' => 29000,
                        'try' => 499000,
                        'eur' => null,
                    ],
                    'interval' => 'year',
                ],
                'setup_fee' => [
                    'id' => 'price_one_off',
                    'currency' => 'usd',
                    'unit_amount' => 500,
                    'currency_options' => [
                        'usd' => 500,
                    ],
                    'interval' => null,
                ],
            ],
            $prices,
        );
    }

    /**
     * Stripe refuses a list call naming more than ten lookup keys, so an
     * eleventh product would otherwise fail the whole read.
     */
    public function test_more_than_ten_lookup_keys_are_read_ten_at_a_time(): void
    {
        $keys = array_map(static fn (int $n): string => "plan_{$n}", range(1, 11));
        $this->transport->answers[] = $this->page([]);
        $this->transport->answers[] = $this->page([
            [
                'id' => 'price_11',
                'object' => 'price',
                'lookup_key' => 'plan_11',
                'currency' => 'usd',
                'unit_amount' => 1100,
                'recurring' => ['interval' => 'month'],
                'currency_options' => [
                    'usd' => ['unit_amount' => 1100],
                ],
            ],
        ]);

        $prices = (new StripePriceReader)->byLookupKeys($keys);

        $this->assertSame(
            [
                array_slice($keys, 0, 10),
                ['plan_11'],
            ],
            array_map(
                static fn (array $request): array => $request['params']['lookup_keys'],
                $this->transport->requests,
            ),
        );
        $this->assertSame(['plan_11'], array_keys($prices));
    }

    public function test_a_stripe_refusal_is_raised_to_the_caller(): void
    {
        $this->transport->answers[] = [
            (string) json_encode([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'Invalid API Key provided.',
                ],
            ]),
            401,
            [],
        ];

        $this->expectException(AuthenticationException::class);

        (new StripePriceReader)->byLookupKeys(['pro_monthly']);
    }

    /**
     * @param  list<array<string, mixed>>  $prices
     * @return array{0: string, 1: int, 2: array<string, string>}
     */
    private function page(array $prices): array
    {
        return [
            (string) json_encode([
                'object' => 'list',
                'url' => '/v1/prices',
                'has_more' => false,
                'data' => $prices,
            ]),
            200,
            [],
        ];
    }
}

/**
 * Stands in for Stripe's curl transport: records each request and answers the
 * next queued response.
 */
class StripeTransport implements ClientInterface
{
    /**
     * @var list<array{method: string, url: string, params: array<string, mixed>}>
     */
    public array $requests = [];

    /**
     * Raw body, HTTP status and headers, in the order they are answered.
     *
     * @var list<array{0: string, 1: int, 2: array<string, string>}>
     */
    public array $answers = [];

    /**
     * @param  'delete'|'get'|'post'  $method
     * @param  string  $absUrl
     * @param  array<int, string>  $headers
     * @param  array<string, mixed>  $params
     * @param  bool  $hasFile
     * @param  'v1'|'v2'  $apiMode
     * @param  int|null  $maxNetworkRetries
     * @return array{0: string, 1: int, 2: array<string, string>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $absUrl,
            'params' => $params,
        ];

        return array_shift($this->answers) ?? [
            '{}',
            500,
            [],
        ];
    }
}
