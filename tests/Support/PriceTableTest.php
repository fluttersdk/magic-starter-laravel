<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Support\Currency;
use FlutterSdk\MagicStarter\Support\PriceTable;
use FlutterSdk\MagicStarter\Tests\TestCase;
use LogicException;

/**
 * Store prices derived from the web price, and the minor-unit arithmetic under
 * them.
 *
 * Every amount is an integer in the currency's minor unit, and every assertion
 * below is an exact figure: a derivation that drifted by one kurus through a
 * float would still read as "about right" to a looser check, and a store price
 * one unit off is a price nobody configured.
 */
class PriceTableTest extends TestCase
{
    private const ABSORB = [
        'currency' => 'USD',
        'commission' => ['mode' => 'absorb', 'rate' => 0.15],
    ];

    private const GROSS_UP = [
        'currency' => 'USD',
        'commission' => ['mode' => 'gross_up', 'rate' => 0.15],
    ];

    public function test_absorb_keeps_the_web_price_on_a_store_channel(): void
    {
        $product = ['prices' => ['web' => ['USD' => 2900]]];

        $this->assertSame(
            ['USD' => ['amount_minor' => 2900, 'source' => PriceTable::SOURCE_DERIVED]],
            PriceTable::for($product, 'app_store', self::ABSORB),
        );
    }

    public function test_gross_up_divides_by_what_the_store_leaves(): void
    {
        // 3400 / 0.85 is exactly 4000; a float division lands a hair above it
        // and a ceil would then charge 4001.
        $product = ['prices' => ['web' => ['USD' => 3400]]];

        $this->assertSame(
            ['USD' => ['amount_minor' => 4000, 'source' => PriceTable::SOURCE_DERIVED]],
            PriceTable::for($product, 'app_store', self::GROSS_UP),
        );
    }

    public function test_gross_up_rounds_up_to_the_next_minor_unit(): void
    {
        // 2900 / 0.85 = 3411.76..., rounded up so the net never falls short.
        $product = ['prices' => ['web' => ['USD' => 2900]]];

        $this->assertSame(
            ['USD' => ['amount_minor' => 3412, 'source' => PriceTable::SOURCE_DERIVED]],
            PriceTable::for($product, 'play', self::GROSS_UP),
        );
    }

    public function test_an_explicit_store_price_wins_over_the_derivation(): void
    {
        $product = ['prices' => [
            'web' => ['TRY' => 49900],
            'app_store' => ['TRY' => 99900],
        ]];

        $this->assertSame(
            ['TRY' => ['amount_minor' => 99900, 'source' => PriceTable::SOURCE_EXPLICIT]],
            PriceTable::for($product, 'app_store', self::GROSS_UP),
        );
    }

    public function test_a_currency_with_no_web_price_is_absent_and_never_converted(): void
    {
        $product = ['prices' => ['web' => ['USD' => 2900]]];

        $play = PriceTable::for($product, 'play', self::GROSS_UP);

        $this->assertArrayNotHasKey('EUR', $play);
        $this->assertSame(['USD'], array_keys($play));
    }

    public function test_the_web_channel_is_never_derived(): void
    {
        $product = ['prices' => ['app_store' => ['USD' => 3400]]];

        $this->assertSame([], PriceTable::for($product, 'web', self::GROSS_UP));
        $this->assertSame(
            ['USD' => ['amount_minor' => 3400, 'source' => PriceTable::SOURCE_EXPLICIT]],
            PriceTable::for(['prices' => ['web' => ['USD' => 3400]]], 'web', self::GROSS_UP),
        );
    }

    public function test_an_unknown_commission_mode_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('[split]');

        PriceTable::for(
            ['prices' => ['web' => ['USD' => 2900]]],
            'app_store',
            ['currency' => 'USD', 'commission' => ['mode' => 'split', 'rate' => 0.15]],
        );
    }

    public function test_the_exponent_follows_iso_4217(): void
    {
        $this->assertSame(0, Currency::exponent('JPY'));
        $this->assertSame(0, Currency::exponent('krw'));
        $this->assertSame(0, Currency::exponent('VND'));
        $this->assertSame(0, Currency::exponent('CLP'));
        $this->assertSame(3, Currency::exponent('KWD'));
        $this->assertSame(3, Currency::exponent('BHD'));
        $this->assertSame(3, Currency::exponent('JOD'));
        $this->assertSame(3, Currency::exponent('OMR'));
        $this->assertSame(3, Currency::exponent('TND'));
        $this->assertSame(2, Currency::exponent('USD'));
        $this->assertSame(2, Currency::exponent('TRY'));
    }

    public function test_display_scales_the_minor_amount_by_the_exponent(): void
    {
        $this->assertSame('3400 JPY', Currency::display(3400, 'JPY'));
        $this->assertSame('1.500 KWD', Currency::display(1500, 'KWD'));
        $this->assertSame('29.00 USD', Currency::display(2900, 'USD'));
        $this->assertSame('0.05 USD', Currency::display(5, 'usd'));
    }
}
