<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Support\RevenueCatProjectClient;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * The read-only RevenueCat v2 project reader `billing:doctor --remote` diffs
 * against the manifest: bearer-keyed GETs, one at a time, following
 * `starting_after` cursors and waiting out a 429 for as long as RevenueCat asks.
 */
class RevenueCatProjectClientTest extends TestCase
{
    private const API_KEY = 'sk_test_revenuecat_v2_secret';

    private const PROJECT = 'proj_test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'magic-starter.billing.revenuecat.api_v2_key' => self::API_KEY,
            'magic-starter.billing.revenuecat.project_id' => self::PROJECT,
        ]);

        Sleep::fake();
    }

    public function test_a_list_is_read_with_a_bearer_key_under_the_project(): void
    {
        Http::fake([
            '*' => Http::response([
                'object' => 'list',
                'items' => [
                    [
                        'id' => 'app_ios',
                        'type' => 'app_store',
                    ],
                ],
                'next_page' => null,
            ]),
        ]);

        $apps = (new RevenueCatProjectClient)->apps();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === RevenueCatProjectClient::BASE_URL . '/projects/' . self::PROJECT . '/apps'
            && $request->hasHeader('Authorization', 'Bearer ' . self::API_KEY));

        $this->assertSame('app_store', $apps[0]['type'] ?? null);
    }

    public function test_pagination_follows_the_starting_after_cursor(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'object' => 'list',
                    'items' => [
                        [
                            'id' => 'prod_1',
                        ],
                    ],
                    'next_page' => '/v2/projects/' . self::PROJECT . '/products?starting_after=prod_1',
                ])
                ->push([
                    'object' => 'list',
                    'items' => [
                        [
                            'id' => 'prod_2',
                        ],
                    ],
                    'next_page' => null,
                ]),
        ]);

        $products = (new RevenueCatProjectClient)->products();

        $this->assertSame(['prod_1', 'prod_2'], array_column($products, 'id'));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url()
            === RevenueCatProjectClient::BASE_URL . '/projects/' . self::PROJECT . '/products?starting_after=prod_1');
    }

    public function test_a_429_waits_for_retry_after_and_then_reads(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['message' => 'slow down'], 429, ['Retry-After' => '7'])
                ->push([
                    'object' => 'list',
                    'items' => [],
                    'next_page' => null,
                ]),
        ]);

        $this->assertSame([], (new RevenueCatProjectClient)->offerings());

        Http::assertSentCount(2);
        Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalSeconds === 7);
    }

    public function test_a_permanent_failure_is_raised_without_a_retry(): void
    {
        Http::fake(['*' => Http::response(['message' => 'forbidden'], 403)]);

        $this->expectException(RequestException::class);

        try {
            (new RevenueCatProjectClient)->apps();
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_entitlements_and_packages_expand_their_products(): void
    {
        Http::fake([
            '*' => Http::response([
                'object' => 'list',
                'items' => [],
                'next_page' => null,
            ]),
        ]);

        $client = new RevenueCatProjectClient;
        $client->entitlements();
        $client->packages('ofrng_default');

        Http::assertSent(fn (Request $request): bool => str_contains(
            $request->url(),
            '/entitlements?expand=items.product',
        ));
        Http::assertSent(fn (Request $request): bool => str_contains(
            $request->url(),
            '/offerings/ofrng_default/packages?expand=items.product',
        ));
    }

    public function test_webhook_integrations_are_read_from_the_integrations_endpoint(): void
    {
        Http::fake([
            '*' => Http::response([
                'object' => 'list',
                'items' => [],
                'next_page' => null,
            ]),
        ]);

        (new RevenueCatProjectClient)->webhookIntegrations();

        Http::assertSent(fn (Request $request): bool => $request->url()
            === RevenueCatProjectClient::BASE_URL . '/projects/' . self::PROJECT . '/integrations/webhooks');
    }

    public function test_an_unconfigured_key_refuses_to_ask(): void
    {
        config(['magic-starter.billing.revenuecat.api_v2_key' => '']);
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REVENUECAT_API_V2_KEY');

        try {
            (new RevenueCatProjectClient)->apps();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_an_unconfigured_project_refuses_to_ask(): void
    {
        config(['magic-starter.billing.revenuecat.project_id' => null]);
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REVENUECAT_PROJECT_ID');

        (new RevenueCatProjectClient)->apps();
    }
}
