<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Console\BillingDoctorCommand;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Support\BillingCatalogue;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Log;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The one reader of the billing catalogue, and the boot refusal that guards it.
 *
 * Each validation rule gets its own test because each one closes a different
 * way of selling the wrong thing: a removed key a published config still carries
 * (the merge is shallow, so the new keys would silently be absent), a floor that
 * can be bought, a Play subscription whose base plans grant two tiers. A rule
 * proven only through a shared fixture would pass with any one of them missing.
 */
class BillingCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['magic-starter.billing' => $this->validBilling()]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function removedKeys(): array
    {
        return [
            'plans' => ['plans', 'tiers'],
            'prices' => ['prices', 'refs.stripe_price'],
            'store_products' => ['store_products', 'refs.app_store'],
        ];
    }

    #[DataProvider('removedKeys')]
    public function test_a_removed_key_is_refused_naming_its_replacement(string $key, string $replacement): void
    {
        // An EMPTY value is still refused: the key's presence is the evidence of
        // a config published before the catalogue existed.
        config(["magic-starter.billing.{$key}" => []]);

        $message = $this->refusal();

        $this->assertStringContainsString("magic-starter.billing.{$key}", $message);
        $this->assertStringContainsString($replacement, $message);
    }

    public function test_an_empty_tier_order_is_refused(): void
    {
        config(['magic-starter.billing.tier_order' => []]);

        $this->assertStringContainsString('magic-starter.billing.tier_order', $this->refusal());
    }

    public function test_a_floor_with_a_sellable_product_is_refused_naming_the_floor(): void
    {
        config(['magic-starter.billing.tier_order' => ['pro', 'business']]);

        $message = $this->refusal();

        $this->assertStringContainsString('floor', $message);
        $this->assertStringContainsString('[pro]', $message);
    }

    public function test_an_unknown_product_type_is_refused(): void
    {
        config(['magic-starter.billing.products.pro_monthly.type' => 'lifetime']);

        $message = $this->refusal();

        $this->assertStringContainsString('[pro_monthly]', $message);
        $this->assertStringContainsString('[lifetime]', $message);
    }

    public function test_a_subscription_without_a_tier_is_refused(): void
    {
        config(['magic-starter.billing.products.pro_monthly.tier' => null]);

        $this->assertStringContainsString('[pro_monthly]', $this->refusal());
    }

    public function test_a_subscription_without_a_known_cycle_is_refused(): void
    {
        // A typo is the realistic case: defaulting 'anual' to monthly would sell
        // the annual price at the monthly cycle's request.
        config(['magic-starter.billing.products.pro_monthly.cycle' => 'anual']);

        $message = $this->refusal();

        $this->assertStringContainsString('[pro_monthly]', $message);
        $this->assertStringContainsString('cycle', $message);
    }

    public function test_a_subscription_for_an_unranked_tier_is_refused(): void
    {
        config(['magic-starter.billing.products.pro_monthly.tier' => 'enterprise']);

        $message = $this->refusal();

        $this->assertStringContainsString('[enterprise]', $message);
        $this->assertStringContainsString('tier_order', $message);
    }

    public function test_one_play_subscription_under_two_tiers_is_refused(): void
    {
        // Play upgrades and downgrades between BASE PLANS of one subscription
        // without telling anybody the tier moved, so one subId must be one tier.
        config([
            'magic-starter.billing.products.pro_monthly.refs.play' => 'pro_sub:monthly',
            'magic-starter.billing.products.business_monthly.refs.play' => 'pro_sub:annual',
        ]);

        $message = $this->refusal();

        $this->assertStringContainsString('[pro_sub]', $message);
        $this->assertStringContainsString('[pro]', $message);
        $this->assertStringContainsString('[business]', $message);
    }

    public function test_a_store_id_on_two_products_is_refused(): void
    {
        config(['magic-starter.billing.products.business_monthly.refs.app_store' => 'com.example.pro.monthly']);

        $this->assertStringContainsString('[com.example.pro.monthly]', $this->refusal());
    }

    public function test_a_valid_catalogue_passes_validation(): void
    {
        BillingCatalogue::validate();

        $this->addToAssertionCount(1);
    }

    /**
     * One Stripe price on two products would let a webhook name whichever
     * product config order happens to put first, so the tier it grants depends
     * on the order the adopter wrote the file in.
     */
    public function test_a_stripe_price_on_two_products_is_refused_naming_both(): void
    {
        config(['magic-starter.billing.products.business_monthly.refs.stripe_price' => 'price_pro_monthly']);

        $message = $this->refusal();

        $this->assertStringContainsString('[price_pro_monthly]', $message);
        $this->assertStringContainsString('[pro_monthly]', $message);
        $this->assertStringContainsString('[business_monthly]', $message);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedPlayRefs(): array
    {
        return [
            'bare subscription id' => ['pro_sub'],
            'empty base plan' => ['pro_sub:'],
            'empty subscription id' => [':monthly'],
            'three parts' => ['pro_sub:monthly:extra'],
        ];
    }

    #[DataProvider('malformedPlayRefs')]
    public function test_a_play_ref_that_is_not_subscription_and_base_plan_is_refused(string $ref): void
    {
        config(['magic-starter.billing.products.pro_monthly.refs.play' => $ref]);

        $message = $this->refusal();

        $this->assertStringContainsString('[pro_monthly]', $message);
        $this->assertStringContainsString('refs.play', $message);
    }

    public function test_an_app_store_ref_carrying_a_colon_is_refused(): void
    {
        // The Play form pasted into the App Store slot: it would never match a
        // purchase, so the product would silently sell nothing on iOS.
        config(['magic-starter.billing.products.pro_monthly.refs.app_store' => 'pro_sub:monthly']);

        $message = $this->refusal();

        $this->assertStringContainsString('[pro_monthly]', $message);
        $this->assertStringContainsString('refs.app_store', $message);
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function malformedPrices(): array
    {
        return [
            'prices not a map' => ['29.00', 'prices'],
            'unknown channel' => [['stripe' => ['USD' => 2900]], '[stripe]'],
            'channel not a map' => [['web' => 2900], '[web]'],
            'currency not three letters' => [['web' => ['DOLLAR' => 2900]], '[DOLLAR]'],
            'amount a float' => [['web' => ['USD' => 29.0]], '[USD]'],
            'amount a string' => [['web' => ['USD' => '2900']], '[USD]'],
            'amount negative' => [['web' => ['USD' => -1]], '[USD]'],
        ];
    }

    #[DataProvider('malformedPrices')]
    public function test_a_malformed_price_is_refused_naming_the_product(mixed $prices, string $named): void
    {
        config(['magic-starter.billing.products.pro_monthly.prices' => $prices]);

        $message = $this->refusal();

        $this->assertStringContainsString('[pro_monthly]', $message);
        $this->assertStringContainsString($named, $message);
    }

    public function test_a_zero_price_on_every_channel_is_accepted(): void
    {
        config(['magic-starter.billing.products.pro_monthly.prices' => [
            'web' => ['usd' => 0],
            'app_store' => ['USD' => 0],
            'play' => ['USD' => 0],
        ]]);

        BillingCatalogue::validate();

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function malformedCommission(): array
    {
        return [
            'unknown mode' => ['mode', 'pass_through'],
            'rate of one' => ['rate', 1],
            'rate above one' => ['rate', 1.5],
            'negative rate' => ['rate', -0.1],
            'rate as a string' => ['rate', '0.15'],
        ];
    }

    #[DataProvider('malformedCommission')]
    public function test_a_malformed_commission_is_refused_naming_its_key(string $key, mixed $value): void
    {
        config(["magic-starter.billing.pricing.commission.{$key}" => $value]);

        $this->assertStringContainsString(
            "magic-starter.billing.pricing.commission.{$key}",
            $this->refusal(),
        );
    }

    public function test_the_play_subscription_id_is_the_part_before_the_base_plan(): void
    {
        $this->assertSame('pro_sub', BillingCatalogue::playSubscriptionId('pro_sub:monthly'));
        $this->assertSame('pro_sub', BillingCatalogue::playSubscriptionId('pro_sub'));
    }

    /**
     * A rail id is a Stripe price first and a store id second, for every
     * reader: two readers trying them in opposite orders would name two
     * different products for one stored id.
     */
    public function test_a_rail_id_resolves_as_a_stripe_price_before_a_store_id(): void
    {
        config(['magic-starter.billing.products.business_monthly.refs.app_store' => 'price_pro_monthly']);

        $this->assertSame('pro_monthly', BillingCatalogue::productForRailId('price_pro_monthly')['key'] ?? null);
        $this->assertSame(
            'business_monthly',
            BillingCatalogue::productForRailId('business_sub:monthly')['key'] ?? null,
        );
        $this->assertNull(BillingCatalogue::productForRailId('price_unmapped'));
        $this->assertNull(BillingCatalogue::productForRailId(null));
    }

    /**
     * The validation is wired into boot, and only under the billing feature.
     *
     * The disarming limb is the billing-off boot: the shipped config carries an
     * empty `tier_order`, so an application that does not bill must not be
     * refused for a catalogue it never needed.
     */
    public function test_boot_refuses_an_invalid_catalogue_only_while_billing_is_on(): void
    {
        config([
            'magic-starter.billing.plans' => [],
            'magic-starter.features' => [],
        ]);

        $this->bootProvider();

        config(['magic-starter.features' => [Features::billing()]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('magic-starter.billing.plans');

        $this->bootProvider();
    }

    /**
     * `billing:doctor` is the command that reports an invalid catalogue, so its
     * own process must boot far enough to run it. Every other console process
     * still refuses: a deploy's `migrate` failing on the catalogue is the
     * signal that keeps a broken config from reaching the web.
     */
    public function test_boot_logs_an_invalid_catalogue_only_while_the_doctor_runs(): void
    {
        $argv = $_SERVER['argv'] ?? [];
        Log::spy();

        config([
            'magic-starter.billing.plans' => [],
            'magic-starter.features' => [Features::billing()],
        ]);

        try {
            $_SERVER['argv'] = ['artisan', BillingDoctorCommand::NAME, '--json'];
            $this->bootProvider();

            Log::shouldHaveReceived('error')
                ->once()
                ->withArgs(fn (string $message): bool => str_contains($message, 'magic-starter.billing.plans'));

            $_SERVER['argv'] = ['artisan', 'migrate'];
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('magic-starter.billing.plans');

            $this->bootProvider();
        } finally {
            $_SERVER['argv'] = $argv;
        }
    }

    public function test_tiers_follow_the_ranking_and_carry_their_id(): void
    {
        // Written in the other order on purpose: the map's order never ranks.
        config(['magic-starter.billing.tiers' => [
            'pro' => ['name' => 'Pro', 'limits' => ['seats' => 10]],
            'free' => ['name' => 'Free'],
        ]]);

        $this->assertSame(['free', 'pro', 'business'], BillingCatalogue::tierOrder());
        $this->assertSame('free', BillingCatalogue::floor());
        $this->assertSame(
            [
                'free' => ['id' => 'free', 'name' => 'Free'],
                'pro' => ['id' => 'pro', 'name' => 'Pro', 'limits' => ['seats' => 10]],
                'business' => ['id' => 'business'],
            ],
            BillingCatalogue::tiers(),
        );
    }

    public function test_the_ranking_keeps_only_usable_tier_ids(): void
    {
        config(['magic-starter.billing.tier_order' => ['free', '', 42, 'pro']]);

        $this->assertSame(['free', 'pro'], BillingCatalogue::tierOrder());
    }

    public function test_a_product_is_found_by_its_stripe_price(): void
    {
        config(['magic-starter.billing.products.business_monthly.refs.stripe_price' => '0']);

        $this->assertSame('pro_monthly', BillingCatalogue::productForStripePrice('price_pro_monthly')['key'] ?? null);
        $this->assertSame('business_monthly', BillingCatalogue::productForStripePrice('0')['key'] ?? null);
        $this->assertNull(BillingCatalogue::productForStripePrice('price_unmapped'));
        $this->assertNull(BillingCatalogue::productForStripePrice(null));
    }

    /**
     * An unset environment variable writes an empty ref, and an empty ref must
     * never be the price that sells a paid tier.
     */
    public function test_an_empty_stripe_price_maps_nothing(): void
    {
        config(['magic-starter.billing.products.pro_monthly.refs.stripe_price' => '']);

        $product = BillingCatalogue::product('pro_monthly');

        $this->assertNotNull($product);
        $this->assertNull($product['refs']['stripe_price']);
        $this->assertNull(BillingCatalogue::productForStripePrice(''));
    }

    public function test_a_store_id_is_matched_exactly(): void
    {
        $this->assertSame('pro_monthly', BillingCatalogue::productForStoreId('com.example.pro.monthly')['key'] ?? null);
        $this->assertSame('pro_monthly', BillingCatalogue::productForStoreId('pro_sub:monthly')['key'] ?? null);

        // Play sends the whole `subId:basePlanId`; the bare subId names no product.
        $this->assertNull(BillingCatalogue::productForStoreId('pro_sub'));
        $this->assertNull(BillingCatalogue::productForStoreId(''));
    }

    public function test_a_product_is_sellable_unless_it_says_otherwise(): void
    {
        config(['magic-starter.billing.products.business_monthly.sellable' => false]);

        $this->assertTrue(BillingCatalogue::product('pro_monthly')['sellable'] ?? null);
        $this->assertFalse(BillingCatalogue::product('business_monthly')['sellable'] ?? null);

        BillingCatalogue::validate();
    }

    #[DataProvider('nonBooleanSellable')]
    public function test_a_sellable_flag_that_is_not_a_boolean_is_refused_naming_the_product(mixed $value): void
    {
        config(['magic-starter.billing.products.pro_monthly.sellable' => $value]);

        $message = $this->refusal();

        $this->assertStringContainsString('[pro_monthly]', $message);
        $this->assertStringContainsString('sellable', $message);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonBooleanSellable(): array
    {
        return [
            'string' => ['no'],
            'integer' => [0],
            'null' => [null],
        ];
    }

    /**
     * A grandfathered price is kept mapped so its subscribers keep their tier:
     * the reverse lookups every webhook and reconcile run must still find it.
     */
    public function test_a_product_kept_for_mapping_still_resolves_by_its_rail_refs(): void
    {
        config([
            'magic-starter.billing.products.pro_monthly_2025' => [
                'type' => 'subscription',
                'tier' => 'pro',
                'cycle' => 'monthly',
                'sellable' => false,
                'refs' => [
                    'stripe_price' => 'price_old',
                    'app_store' => 'com.example.pro.monthly.2025',
                    'play' => 'pro_sub:monthly_2025',
                ],
            ],
        ]);

        BillingCatalogue::validate();

        $this->assertSame('pro_monthly_2025', BillingCatalogue::productForStripePrice('price_old')['key'] ?? null);
        $this->assertSame(
            'pro_monthly_2025',
            BillingCatalogue::productForStoreId('com.example.pro.monthly.2025')['key'] ?? null,
        );
        $this->assertSame(
            'pro_monthly_2025',
            BillingCatalogue::productForStoreId('pro_sub:monthly_2025')['key'] ?? null,
        );
    }

    public function test_two_sellable_products_for_one_tier_and_cycle_are_refused(): void
    {
        config([
            'magic-starter.billing.products.pro_monthly_b' => [
                'type' => 'subscription',
                'tier' => 'pro',
                'cycle' => 'monthly',
                'refs' => ['stripe_price' => 'price_pro_monthly_b'],
            ],
        ]);

        $message = $this->refusal();

        $this->assertStringContainsString('[pro_monthly]', $message);
        $this->assertStringContainsString('[pro_monthly_b]', $message);
        $this->assertStringContainsString('[pro]', $message);
        $this->assertStringContainsString('[monthly]', $message);

        // The disarming limb: retiring either one makes the pair legal.
        config(['magic-starter.billing.products.pro_monthly_b.sellable' => false]);

        BillingCatalogue::validate();

        $this->addToAssertionCount(1);
    }

    public function test_a_product_is_read_by_key_with_normalised_refs(): void
    {
        $product = BillingCatalogue::product('credits_100');

        $this->assertNotNull($product);
        $this->assertSame(BillingCatalogue::TYPE_CONSUMABLE, $product['type']);
        $this->assertSame(100, $product['credits']);
        $this->assertNull($product['tier']);
        $this->assertSame(
            ['stripe_price' => null, 'app_store' => 'com.example.credits.100', 'play' => null],
            $product['refs'],
        );
        $this->assertNull(BillingCatalogue::product('missing'));
        $this->assertSame(
            ['pro_monthly', 'business_monthly', 'credits_100'],
            array_keys(BillingCatalogue::products()),
        );
    }

    public function test_pricing_reads_the_currency_and_commission(): void
    {
        $this->assertSame(
            ['currency' => 'USD', 'commission' => ['mode' => 'gross_up', 'rate' => 0.15]],
            BillingCatalogue::pricing(),
        );
    }

    /**
     * A catalogue that passes every rule: one free floor, two sellable tiers and
     * a consumable, on all three channels.
     *
     * @return array<string, mixed>
     */
    private function validBilling(): array
    {
        return [
            'billable' => 'user',
            'tiers' => [
                'free' => ['name' => 'Free'],
                'pro' => ['name' => 'Pro'],
                'business' => ['name' => 'Business'],
            ],
            'tier_order' => ['free', 'pro', 'business'],
            'products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'prices' => ['web' => ['USD' => 2900]],
                    'refs' => [
                        'stripe_price' => 'price_pro_monthly',
                        'app_store' => 'com.example.pro.monthly',
                        'play' => 'pro_sub:monthly',
                    ],
                ],
                'business_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'business',
                    'cycle' => 'monthly',
                    'prices' => ['web' => ['USD' => 9900]],
                    'refs' => [
                        'stripe_price' => 'price_business_monthly',
                        'app_store' => 'com.example.business.monthly',
                        'play' => 'business_sub:monthly',
                    ],
                ],
                'credits_100' => [
                    'type' => 'consumable',
                    'credits' => 100,
                    'prices' => ['web' => ['USD' => 500]],
                    'refs' => [
                        'app_store' => 'com.example.credits.100',
                    ],
                ],
            ],
            'pricing' => [
                'currency' => 'USD',
                'commission' => ['mode' => 'gross_up', 'rate' => 0.15],
            ],
        ];
    }

    /**
     * Run the validation and hand back the refusal's message, failing when the
     * catalogue was accepted.
     */
    private function refusal(): string
    {
        try {
            BillingCatalogue::validate();
        } catch (LogicException $exception) {
            return $exception->getMessage();
        }

        $this->fail('The catalogue was accepted; a LogicException was expected.');
    }

    private function bootProvider(): void
    {
        $this->app['router']->setRoutes(new RouteCollection);

        (new MagicStarterServiceProvider($this->app))->boot();
    }
}
