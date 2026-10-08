<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use FlutterSdk\MagicStarter\Console\BillingManifestCommand;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/**
 * The machine-readable description of every store and rail object the catalogue
 * needs, which an agent with asc, gplay, rc and stripe access applies.
 *
 * The secrets in this file are sentinels: every one of them is set, and every
 * output is searched for all of them, because the manifest's `env` section is
 * the one place a careless `config()` read would print a key.
 */
class BillingManifestCommandTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    public const SECRETS = [
        'magic-starter.billing.revenuecat.secret_api_key' => 'SENTINEL-rc-secret-api-key',
        'magic-starter.billing.revenuecat.api_v2_key' => 'SENTINEL-rc-api-v2-key',
        'magic-starter.billing.revenuecat.webhook_secret' => 'SENTINEL-rc-webhook-secret',
        'cashier.secret' => 'SENTINEL-stripe-secret',
        'cashier.webhook.secret' => 'SENTINEL-stripe-webhook-secret',
    ];

    /**
     * The catalogue both console suites read: a free floor and two paid tiers,
     * each sold monthly and annually on every rail.
     *
     * @return array<string, mixed>
     */
    public static function billing(): array
    {
        $product = static fn (string $tier, string $cycle, int $usd, int $try): array => [
            'type' => 'subscription',
            'tier' => $tier,
            'cycle' => $cycle,
            'prices' => [
                'web' => [
                    'USD' => $usd,
                    'TRY' => $try,
                ],
            ],
            'refs' => [
                'stripe_price' => "price_{$tier}_{$cycle}",
                'app_store' => "com.example.{$tier}.{$cycle}",
                'play' => "{$tier}_sub:{$cycle}",
            ],
        ];

        return [
            'billable' => 'user',
            'tiers' => [
                'free' => [
                    'name' => 'Free',
                ],
                'pro' => [
                    'name' => 'Pro',
                ],
                'business' => [
                    'name' => 'Business',
                ],
            ],
            'tier_order' => [
                'free',
                'pro',
                'business',
            ],
            'products' => [
                'pro_monthly' => $product('pro', 'monthly', 2900, 49900),
                'pro_annual' => $product('pro', 'annual', 29000, 499000),
                'business_monthly' => $product('business', 'monthly', 9900, 169900),
                'business_annual' => $product('business', 'annual', 99000, 1699000),
            ],
            'pricing' => [
                'currency' => 'USD',
                'commission' => [
                    'mode' => 'gross_up',
                    'rate' => 0.15,
                ],
            ],
            'reconcile' => [
                'cadence' => 'hourly',
            ],
            'revenuecat' => [
                'path' => 'webhooks/revenuecat',
                'project_id' => 'proj_test',
            ],
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'app.url' => 'https://app.example.test/',
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing' => self::billing(),
            ...self::SECRETS,
        ]);
    }

    public function test_app_store_levels_rank_the_highest_tier_first(): void
    {
        $appStore = $this->manifest()['app_store'];
        $levels = array_column($appStore['products'], 'group_level', 'key');
        $periods = array_column($appStore['products'], 'period', 'key');
        $proAnnual = $this->entry($appStore['products'], 'key', 'pro_annual');

        $this->assertSame(1, $levels['business_monthly']);
        $this->assertSame(1, $levels['business_annual']);
        $this->assertSame(2, $levels['pro_monthly']);
        $this->assertSame(2, $levels['pro_annual']);
        $this->assertSame('ONE_MONTH', $periods['pro_monthly']);
        $this->assertSame('ONE_YEAR', $periods['pro_annual']);
        $this->assertSame('com.example.pro.annual', $proAnnual['product_id']);
        $this->assertSame('nearest at or above target', $proAnnual['price_point_rule']);
        // Gross-up at 15%: ceil(29000 / 0.85) = 34118.
        $this->assertSame(34118, $proAnnual['prices']['USD']['amount_minor']);
        $this->assertArrayHasKey('TRY', $proAnnual['prices']);
    }

    public function test_play_holds_one_subscription_per_tier_with_both_base_plans(): void
    {
        $subscriptions = $this->manifest()['play']['subscriptions'];
        $pro = $this->entry($subscriptions, 'product_id', 'pro_sub');

        $this->assertCount(2, $subscriptions);
        $this->assertSame('pro', $pro['tier']);
        $this->assertSame(['monthly', 'annual'], array_column($pro['base_plans'], 'base_plan_id'));
        $this->assertSame(['P1M', 'P1Y'], array_column($pro['base_plans'], 'billing_period'));
        $this->assertSame(34118, $pro['base_plans'][1]['prices']['USD']['amount_minor']);
    }

    public function test_revenuecat_packages_are_keyed_by_product_key_under_a_current_default_offering(): void
    {
        $revenueCat = $this->manifest()['revenuecat'];
        $packages = $revenueCat['offering']['packages'];

        $this->assertSame(['app_store', 'play_store'], array_column($revenueCat['apps'], 'type'));
        $this->assertSame('default', $revenueCat['offering']['lookup_key']);
        $this->assertTrue($revenueCat['offering']['is_current']);
        $this->assertSame(
            ['pro_monthly', 'pro_annual', 'business_monthly', 'business_annual'],
            array_column($packages, 'lookup_key'),
        );
        $this->assertSame([1, 2, 3, 4], array_column($packages, 'position'));
        $this->assertSame(['com.example.pro.annual', 'pro_sub:annual'], $packages[1]['products']);
        $this->assertSame(['pro', 'business'], array_column($revenueCat['entitlements'], 'lookup_key'));
        $this->assertSame(
            ['com.example.pro.monthly', 'pro_sub:monthly', 'com.example.pro.annual', 'pro_sub:annual'],
            $revenueCat['entitlements'][0]['products'],
        );
        $this->assertContains(
            [
                'key' => 'pro_annual',
                'store_identifier' => 'pro_sub:annual',
                'app' => 'play_store',
                'type' => 'subscription',
            ],
            $revenueCat['products'],
        );
        $this->assertSame('https://app.example.test/webhooks/revenuecat', $revenueCat['webhook']['url']);
        $this->assertStringContainsString('HMAC', $revenueCat['webhook']['instruction']);
    }

    public function test_each_stripe_price_takes_its_product_key_as_lookup_key(): void
    {
        $products = $this->manifest()['stripe']['products'];
        $pro = $this->entry($products, 'tier', 'pro');
        $annual = $this->entry($pro['prices'], 'key', 'pro_annual');

        $this->assertSame(['pro', 'business'], array_column($products, 'tier'));
        $this->assertSame('pro_annual', $annual['lookup_key']);
        $this->assertSame('usd', $annual['currency']);
        $this->assertSame(29000, $annual['unit_amount']);
        $this->assertSame(['try' => ['unit_amount' => 499000]], $annual['currency_options']);
        $this->assertSame(['interval' => 'year'], $annual['recurring']);
        $this->assertSame('CASHIER_PRICE_PRO_ANNUAL', $annual['env_key']);
    }

    public function test_env_reports_presence_and_never_a_value(): void
    {
        config([
            'magic-starter.billing.revenuecat.project_id' => null,
            'magic-starter.billing.products.business_annual.refs.stripe_price' => '',
        ]);

        $env = $this->manifest()['env'];

        $this->assertSame('present', $env['STRIPE_SECRET']);
        $this->assertSame('present', $env['REVENUECAT_WEBHOOK_SECRET']);
        $this->assertSame('absent', $env['REVENUECAT_PROJECT_ID']);
        $this->assertSame('present', $env['CASHIER_PRICE_PRO_ANNUAL']);
        $this->assertSame('absent', $env['CASHIER_PRICE_BUSINESS_ANNUAL']);
        $this->assertSame([], array_diff(array_unique(array_values($env)), ['present', 'absent']));
    }

    /**
     * An agent applies the manifest, so a product kept only to map an old price
     * must be nowhere in it: a store product or a Stripe price created for it
     * would put the retired offer back on sale.
     */
    public function test_a_product_kept_for_mapping_is_left_out_of_every_section(): void
    {
        config([
            'magic-starter.billing.products.pro_monthly_2025' => [
                'type' => 'subscription',
                'tier' => 'pro',
                'cycle' => 'monthly',
                'sellable' => false,
                'prices' => ['web' => ['USD' => 1900]],
                'refs' => [
                    'stripe_price' => 'price_old',
                    'app_store' => 'com.example.pro.monthly.2025',
                    'play' => 'pro_sub:monthly_2025',
                ],
            ],
        ]);

        Artisan::call(BillingManifestCommand::NAME, ['--json' => true]);
        $json = Artisan::output();

        // The key, the App Store id, the Play base plan and the price variable.
        foreach (['pro_monthly_2025', 'pro.monthly.2025', 'monthly_2025', 'PRO_MONTHLY_2025'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }

        $manifest = $this->manifest();
        $pro = $this->entry($manifest['play']['subscriptions'], 'product_id', 'pro_sub');

        $this->assertSame(['pro_monthly', 'pro_annual'], array_column($pro['base_plans'], 'key'));
        $this->assertSame(
            ['pro_monthly', 'pro_annual', 'business_monthly', 'business_annual'],
            array_column($manifest['revenuecat']['offering']['packages'], 'lookup_key'),
        );
    }

    public function test_no_secret_reaches_either_output(): void
    {
        Artisan::call(BillingManifestCommand::NAME, ['--json' => true]);
        $json = Artisan::output();

        Artisan::call(BillingManifestCommand::NAME);
        $human = Artisan::output();

        $this->assertStringContainsString('business_annual', $human);

        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $json);
            $this->assertStringNotContainsString($secret, $human);
        }
    }

    public function test_a_section_prints_only_that_section(): void
    {
        $exit = Artisan::call(BillingManifestCommand::NAME, [
            '--json' => true,
            '--section' => 'stripe',
        ]);

        $manifest = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(['schema_version', 'stripe'], array_keys($manifest));
        $this->assertSame(1, $manifest['schema_version']);
    }

    public function test_an_unknown_section_is_refused(): void
    {
        $this->artisan(BillingManifestCommand::NAME, ['--section' => 'paypal'])
            ->expectsOutputToContain('app_store, play, revenuecat, stripe, env')
            ->assertExitCode(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $exit = Artisan::call(BillingManifestCommand::NAME, ['--json' => true]);
        $manifest = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($manifest);
        $this->assertSame(1, $manifest['schema_version']);

        return $manifest;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function entry(array $rows, string $field, string $value): array
    {
        foreach ($rows as $row) {
            if (($row[$field] ?? null) === $value) {
                return $row;
            }
        }

        $this->fail("No entry with [{$field}] = [{$value}].");
    }
}
