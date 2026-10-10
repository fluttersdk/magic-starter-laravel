<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\ResolvesBillingCurrency;
use FlutterSdk\MagicStarter\Support\BillingCatalogue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The default currency rule: the billable's pin, then the caller's country,
 * then the base currency.
 *
 * The pin comes first because it is not a preference. The Stripe webhook
 * writes it from the first subscription Stripe billed, and Stripe bills a
 * customer in that currency from then on, so offering anything else would be
 * a figure the customer cannot be charged.
 *
 * The country is read from the proxy header the adopter names in
 * `billing.pricing.country_header` (Cloudflare's `CF-IPCountry`, say), and only
 * while one is named: with none, a request carrying the header is still
 * answered in the base currency, which is the 0.0.25 behaviour.
 *
 * @phpstan-import-type Pricing from BillingCatalogue
 */
class ResolveBillingCurrency implements ResolvesBillingCurrency
{
    /**
     * Cloudflare's code for a location it could not determine. It is a valid
     * two-letter value, so it is refused by name rather than by shape: a
     * currency mapped to "unknown" would be a guess presented as a price.
     */
    private const UNKNOWN_COUNTRY = 'XX';

    public function resolve(Request $request, ?Model $billable): string
    {
        $pricing = BillingCatalogue::pricing();

        // 1. The currency Stripe already bills this subject in.
        $pin = $billable?->getAttribute('billing_currency');

        if (is_string($pin) && $pin !== '') {
            return strtoupper($pin);
        }

        // 2. The caller's country, while the adopter has named a header for it.
        return $this->countryCurrency($request, $pricing) ?? $pricing['currency'];
    }

    /**
     * The currency [$request]'s country maps to, or null when the feature is
     * off or the country is missing, unknown, a Tor exit (`T1`) or unmapped.
     *
     * @param  Pricing  $pricing
     */
    private function countryCurrency(Request $request, array $pricing): ?string
    {
        if ($pricing['country_header'] === null) {
            return null;
        }

        $country = strtoupper(trim((string) $request->header($pricing['country_header'], '')));

        if (preg_match('/^[A-Z]{2}$/', $country) !== 1 || $country === self::UNKNOWN_COUNTRY) {
            return null;
        }

        return $pricing['country_currencies'][$country] ?? null;
    }
}
