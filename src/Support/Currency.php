<?php

namespace FlutterSdk\MagicStarter\Support;

/**
 * ISO 4217 minor units, the one fact a price in minor units cannot carry.
 *
 * Every amount in the billing catalogue is an integer in the currency's minor
 * unit, which is what keeps a derivation free of float drift. The price of that
 * is that `3400` means 34.00 dollars, 3400 yen and 3.400 dinars, so anything
 * that shows an amount to a person has to know the exponent. Two is the answer
 * for most currencies and wrong for the ones listed here, where a hardcoded
 * `/ 100` shows a yen price a hundred times too small.
 */
final class Currency
{
    /**
     * Currencies with no minor unit at all (exponent 0).
     *
     * @var array<int, string>
     */
    public const ZERO_DECIMAL = [
        'BIF',
        'CLP',
        'DJF',
        'GNF',
        'ISK',
        'JPY',
        'KMF',
        'KRW',
        'PYG',
        'RWF',
        'UGX',
        'UYI',
        'VND',
        'VUV',
        'XAF',
        'XOF',
        'XPF',
    ];

    /**
     * Currencies divided into thousandths (exponent 3).
     *
     * @var array<int, string>
     */
    public const THREE_DECIMAL = [
        'BHD',
        'IQD',
        'JOD',
        'KWD',
        'LYD',
        'OMR',
        'TND',
    ];

    /**
     * The number of decimal places the currency's minor unit stands for.
     *
     * @param  string  $code  ISO 4217 alphabetic code, in either case.
     */
    public static function exponent(string $code): int
    {
        $code = strtoupper($code);

        if (in_array($code, self::ZERO_DECIMAL, true)) {
            return 0;
        }

        if (in_array($code, self::THREE_DECIMAL, true)) {
            return 3;
        }

        return 2;
    }

    /**
     * Format a minor-unit amount as `<major>.<minor> <CODE>`, e.g. `29.00 USD`.
     *
     * Integer arithmetic throughout, so the figure shown is the figure stored:
     * dividing by a power of ten in floats would print `0.30000000000000004`
     * territory for the wrong input. No thousands separator and no symbol,
     * because both are locale decisions this package leaves to the client.
     *
     * @param  int  $amountMinor  Amount in the currency's minor unit (cents, kurus, fils).
     */
    public static function display(int $amountMinor, string $code): string
    {
        $code = strtoupper($code);
        $exponent = self::exponent($code);
        $sign = $amountMinor < 0 ? '-' : '';
        $absolute = (string) abs($amountMinor);

        if ($exponent === 0) {
            return "{$sign}{$absolute} {$code}";
        }

        $padded = str_pad($absolute, $exponent + 1, '0', STR_PAD_LEFT);
        $major = substr($padded, 0, -$exponent);
        $minor = substr($padded, -$exponent);

        return "{$sign}{$major}.{$minor} {$code}";
    }
}
