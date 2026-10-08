<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use FlutterSdk\MagicStarter\Console\BillingDoctorCommand;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Support\RevenueCatProjectClient;
use FlutterSdk\MagicStarter\Support\StripePriceReader;
use FlutterSdk\MagicStarter\Tests\TestCase;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * The checks an agent runs before and after applying the manifest.
 *
 * Every secret is a sentinel, and the RevenueCat fakes hand back a webhook
 * integration carrying `signing_secret` (which the real API does), so the
 * whitelist is proven against a response that would leak if anything passed a
 * remote object through.
 */
class BillingDoctorCommandTest extends TestCase
{
    private const PROJECT = 'proj_test';

    /**
     * The four product keys, in catalogue order.
     *
     * @var list<string>
     */
    private const KEYS = [
        'pro_monthly',
        'pro_annual',
        'business_monthly',
        'business_annual',
    ];

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'app.url' => 'https://app.example.test',
            'magic-starter.use_uuids' => true,
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing' => BillingManifestCommandTest::billing(),
            ...BillingManifestCommandTest::SECRETS,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->app->instance(StripePriceReader::class, $this->stripe($this->stripePrices()));
    }

    public function test_a_complete_configuration_passes_its_local_checks(): void
    {
        $doctor = $this->doctor();

        $this->assertSame(0, $doctor['exit']);
        $this->assertTrue($doctor['report']['ok']);
        $this->assertSame('ok', $doctor['checks']['catalogue.valid']['status']);
        $this->assertSame('ok', $doctor['checks']['revenuecat.hmac_secret']['status']);
        $this->assertSame('ok', $doctor['checks']['stripe.price.pro_annual']['status']);
        $this->assertSame('ok', $doctor['checks']['billable.uuid']['status']);
        $this->assertArrayNotHasKey('revenuecat.remote', $doctor['checks']);
    }

    public function test_a_sellable_product_without_a_stripe_price_is_an_error(): void
    {
        config(['magic-starter.billing.products.pro_annual.refs.stripe_price' => null]);

        $doctor = $this->doctor();

        $this->assertSame(1, $doctor['exit']);
        $this->assertFalse($doctor['report']['ok']);
        $this->assertSame('error', $doctor['checks']['stripe.price.pro_annual']['status']);
        $this->assertStringContainsString(
            'CASHIER_PRICE_PRO_ANNUAL',
            $doctor['checks']['stripe.price.pro_annual']['message'],
        );
    }

    /**
     * A product kept only to map an old price has no Stripe price or store id to
     * be asked for: the checks that demand them are for what is sold.
     */
    public function test_a_product_kept_for_mapping_is_not_asked_for_a_price_or_store_ids(): void
    {
        config([
            'magic-starter.billing.products.pro_monthly_2025' => [
                'type' => 'subscription',
                'tier' => 'pro',
                'cycle' => 'monthly',
                'sellable' => false,
                'refs' => ['stripe_price' => null],
            ],
        ]);

        $doctor = $this->doctor();

        $this->assertSame(0, $doctor['exit']);
        $this->assertSame('ok', $doctor['checks']['catalogue.valid']['status']);
        $this->assertArrayNotHasKey('stripe.price.pro_monthly_2025', $doctor['checks']);
        $this->assertArrayNotHasKey('store.pro_monthly_2025', $doctor['checks']);
        $this->assertArrayHasKey('stripe.price.pro_monthly', $doctor['checks']);
        $this->assertArrayHasKey('store.pro_monthly', $doctor['checks']);
    }

    public function test_a_configured_store_rail_without_an_hmac_secret_is_an_error(): void
    {
        config(['magic-starter.billing.revenuecat.webhook_secret' => '']);

        $doctor = $this->doctor();

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('error', $doctor['checks']['revenuecat.hmac_secret']['status']);
    }

    public function test_a_subscription_on_no_store_is_an_error_while_the_store_rail_is_on(): void
    {
        config([
            'magic-starter.billing.products.pro_monthly.refs.app_store' => null,
            'magic-starter.billing.products.pro_monthly.refs.play' => null,
            'magic-starter.billing.products.pro_annual.refs.play' => null,
        ]);

        $doctor = $this->doctor();

        $this->assertSame('error', $doctor['checks']['store.pro_monthly']['status']);
        $this->assertSame('warning', $doctor['checks']['store.pro_annual']['status']);
        $this->assertSame('ok', $doctor['checks']['store.business_annual']['status']);
    }

    public function test_integer_billable_keys_and_a_daily_store_sweep_are_warnings(): void
    {
        config([
            'magic-starter.use_uuids' => false,
            'magic-starter.billing.reconcile.cadence' => 'daily',
        ]);

        $doctor = $this->doctor();

        $this->assertSame(0, $doctor['exit']);
        $this->assertSame('warning', $doctor['checks']['billable.uuid']['status']);
        $this->assertSame('warning', $doctor['checks']['reconcile.cadence']['status']);
    }

    public function test_remote_agrees_with_a_project_that_matches_the_manifest(): void
    {
        $this->fakeRevenueCat();

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(0, $doctor['exit'], json_encode($doctor['report'], JSON_PRETTY_PRINT) ?: '');
        $this->assertSame('ok', $doctor['checks']['revenuecat.app.play_store']['status']);
        $this->assertSame('ok', $doctor['checks']['revenuecat.product.pro_sub:annual']['status']);
        $this->assertSame('ok', $doctor['checks']['revenuecat.entitlement.business']['status']);
        $this->assertSame('ok', $doctor['checks']['revenuecat.offering.default']['status']);
        $this->assertSame('ok', $doctor['checks']['revenuecat.package.business_annual']['status']);
        $this->assertSame('ok', $doctor['checks']['revenuecat.webhook']['status']);
        $this->assertSame('ok', $doctor['checks']['stripe.remote.price.pro_annual']['status']);
        $this->assertSame('agent_check', $doctor['checks']['app_store.remote']['status']);
        $this->assertStringStartsWith('asc ', $doctor['checks']['app_store.remote']['command']);
        $this->assertSame('agent_check', $doctor['checks']['play.remote']['status']);
        $this->assertStringStartsWith('gplay ', $doctor['checks']['play.remote']['command']);
    }

    public function test_remote_reports_a_package_missing_from_revenuecat(): void
    {
        $this->fakeRevenueCat(packages: ['pro_monthly', 'pro_annual', 'business_monthly']);

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('error', $doctor['checks']['revenuecat.package.business_annual']['status']);
        $this->assertSame('ok', $doctor['checks']['revenuecat.package.pro_monthly']['status']);
    }

    public function test_remote_reports_an_hmac_less_webhook_and_a_wrong_url(): void
    {
        $this->fakeRevenueCat(signingSecret: null);

        $this->assertSame('error', $this->doctor(['--remote' => true])['checks']['revenuecat.webhook']['status']);

        Http::swap(new Factory);
        $this->fakeRevenueCat(webhookUrl: 'https://elsewhere.example.test/hook');

        $this->assertSame('error', $this->doctor(['--remote' => true])['checks']['revenuecat.webhook']['status']);
    }

    public function test_remote_reports_a_lookup_key_resolving_to_another_price(): void
    {
        $this->fakeRevenueCat();
        $prices = $this->stripePrices();
        $prices['pro_annual']['id'] = 'price_somebody_else';
        $prices['business_monthly']['unit_amount'] = 9800;
        unset($prices['business_annual']);
        $this->app->instance(StripePriceReader::class, $this->stripe($prices));

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('error', $doctor['checks']['stripe.remote.price.pro_annual']['status']);
        $this->assertSame('error', $doctor['checks']['stripe.remote.price.business_monthly']['status']);
        $this->assertSame('error', $doctor['checks']['stripe.remote.price.business_annual']['status']);
        $this->assertSame('ok', $doctor['checks']['stripe.remote.price.pro_monthly']['status']);
    }

    public function test_remote_without_a_v2_key_is_an_error_naming_the_variable(): void
    {
        config(['magic-starter.billing.revenuecat.api_v2_key' => null]);
        Http::fake();

        $doctor = $this->doctor(['--remote' => true]);

        $this->assertSame(1, $doctor['exit']);
        $this->assertSame('error', $doctor['checks']['revenuecat.remote']['status']);
        $this->assertStringContainsString('REVENUECAT_API_V2_KEY', $doctor['checks']['revenuecat.remote']['message']);
        Http::assertNothingSent();
    }

    public function test_remote_only_ever_reads(): void
    {
        $this->fakeRevenueCat();

        $this->doctor(['--remote' => true]);

        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_no_secret_reaches_any_output(): void
    {
        $outputs = [];

        $this->fakeRevenueCat();
        Artisan::call(BillingDoctorCommand::NAME, ['--remote' => true, '--json' => true]);
        $outputs[] = Artisan::output();
        Artisan::call(BillingDoctorCommand::NAME, ['--remote' => true]);
        $outputs[] = Artisan::output();
        Artisan::call(BillingDoctorCommand::NAME, ['--json' => true]);
        $outputs[] = Artisan::output();
        Artisan::call(BillingDoctorCommand::NAME);
        $outputs[] = Artisan::output();

        // A failing RevenueCat whose error body echoes the secret: Laravel's
        // RequestException message carries the body, so passing it through
        // would print what RevenueCat printed.
        $secrets = BillingManifestCommandTest::SECRETS;
        Http::swap(new Factory);
        Http::fake([
            '*' => Http::response([
                'message' => 'broken',
                'signing_secret' => $secrets['magic-starter.billing.revenuecat.webhook_secret'],
                'key' => $secrets['magic-starter.billing.revenuecat.api_v2_key'],
            ], 400),
        ]);
        Artisan::call(BillingDoctorCommand::NAME, ['--remote' => true, '--json' => true]);
        $outputs[] = Artisan::output();
        Artisan::call(BillingDoctorCommand::NAME, ['--remote' => true]);
        $outputs[] = Artisan::output();

        $this->assertStringContainsString('revenuecat.remote', $outputs[4]);

        foreach ($outputs as $output) {
            foreach (BillingManifestCommandTest::SECRETS as $secret) {
                $this->assertStringNotContainsString($secret, $output);
            }
        }
    }

    public function test_the_human_report_names_each_failing_check(): void
    {
        config(['magic-starter.billing.products.pro_annual.refs.stripe_price' => null]);

        $this->artisan(BillingDoctorCommand::NAME)
            ->expectsOutputToContain('stripe.price.pro_annual')
            ->assertExitCode(1);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{exit: int, report: array<string, mixed>, checks: array<string, array<string, mixed>>}
     */
    private function doctor(array $options = []): array
    {
        $exit = Artisan::call(BillingDoctorCommand::NAME, ['--json' => true, ...$options]);
        $report = json_decode(Artisan::output(), true);

        $this->assertIsArray($report);
        $this->assertSame(1, $report['schema_version']);

        $checks = [];

        foreach ($report['checks'] as $check) {
            $this->assertSame([], array_diff(array_keys($check), ['id', 'status', 'message', 'command']));
            $checks[$check['id']] = $check;
        }

        return [
            'exit' => $exit,
            'report' => $report,
            'checks' => $checks,
        ];
    }

    /**
     * Fake a RevenueCat project that matches the manifest, minus whatever the
     * arguments take away.
     *
     * @param  list<string>  $packages
     */
    private function fakeRevenueCat(
        array $packages = self::KEYS,
        ?string $signingSecret = BillingManifestCommandTest::SECRETS['magic-starter.billing.revenuecat.webhook_secret'],
        string $webhookUrl = 'https://app.example.test/webhooks/revenuecat',
    ): void {
        $base = RevenueCatProjectClient::BASE_URL . '/projects/' . self::PROJECT;
        $storeIds = static fn (string $key): array => [
            'com.example.' . str_replace('_', '.', $key),
            str_replace('_', '_sub:', $key),
        ];
        $product = static fn (string $storeId): array => [
            'object' => 'product',
            'id' => 'prod_' . $storeId,
            'store_identifier' => $storeId,
            'type' => 'subscription',
            'app_id' => str_contains($storeId, ':') ? 'app_android' : 'app_ios',
        ];
        $allStoreIds = array_merge(...array_map($storeIds, self::KEYS));

        Http::fake([
            "{$base}/apps*" => $this->page([
                [
                    'id' => 'app_ios',
                    'type' => 'app_store',
                ],
                [
                    'id' => 'app_android',
                    'type' => 'play_store',
                ],
            ]),
            "{$base}/products*" => $this->page(array_map($product, $allStoreIds)),
            "{$base}/entitlements*" => $this->page(array_map(
                static fn (string $tier): array => [
                    'object' => 'entitlement',
                    'id' => "entl_{$tier}",
                    'lookup_key' => $tier,
                    'products' => [
                        'object' => 'list',
                        'items' => array_map(
                            $product,
                            array_merge($storeIds("{$tier}_monthly"), $storeIds("{$tier}_annual")),
                        ),
                    ],
                ],
                ['pro', 'business'],
            )),
            "{$base}/offerings/ofrng_default/packages*" => $this->page(array_map(
                static fn (string $key): array => [
                    'object' => 'package',
                    'id' => "pkg_{$key}",
                    'lookup_key' => $key,
                    'position' => array_search($key, self::KEYS, true) + 1,
                    'products' => [
                        'object' => 'list',
                        'items' => array_map(
                            static fn (string $storeId): array => [
                                'product' => $product($storeId),
                                'eligibility_criteria' => 'all',
                            ],
                            $storeIds($key),
                        ),
                    ],
                ],
                $packages,
            )),
            "{$base}/offerings*" => $this->page([
                [
                    'object' => 'offering',
                    'id' => 'ofrng_default',
                    'lookup_key' => 'default',
                    'is_current' => true,
                ],
            ]),
            "{$base}/integrations/webhooks*" => $this->page([
                [
                    'object' => 'webhook_integration',
                    'id' => 'wh_1',
                    'url' => $webhookUrl,
                    'environment' => 'production',
                    'signing_secret' => $signingSecret,
                ],
            ]),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function page(array $items): PromiseInterface
    {
        return Http::response([
            'object' => 'list',
            'items' => $items,
            'next_page' => null,
        ]);
    }

    /**
     * What Stripe holds when every lookup key resolves to the configured price.
     *
     * @return array<string, array<string, mixed>>
     */
    private function stripePrices(): array
    {
        $prices = [];

        foreach (BillingManifestCommandTest::billing()['products'] as $key => $product) {
            $prices[$key] = [
                'id' => $product['refs']['stripe_price'],
                'currency' => 'usd',
                'unit_amount' => $product['prices']['web']['USD'],
                'currency_options' => [
                    'usd' => $product['prices']['web']['USD'],
                    'try' => $product['prices']['web']['TRY'],
                ],
                'interval' => $product['cycle'] === 'annual' ? 'year' : 'month',
            ];
        }

        return $prices;
    }

    /**
     * @param  array<string, array<string, mixed>>  $prices
     */
    private function stripe(array $prices): StripePriceReader
    {
        return new class($prices) extends StripePriceReader
        {
            /**
             * @param  array<string, array<string, mixed>>  $prices
             */
            public function __construct(private array $prices) {}

            public function byLookupKeys(array $lookupKeys): array
            {
                return array_intersect_key($this->prices, array_flip($lookupKeys));
            }
        };
    }
}
