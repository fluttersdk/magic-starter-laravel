<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\ResolveBillingCurrency;
use FlutterSdk\MagicStarter\Contracts\ResolvesBillingCurrency;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which web currency a caller is shown and charged in, before any product is
 * asked whether it is priced in it.
 *
 * The order is the contract: a billable's pin first, because Stripe bills a
 * customer in one currency for good; then the country header, and only while
 * the adopter has named one; then the base currency. Every limb below is a
 * pair with the limb it must not be confused with, since a resolver that
 * always answered the base would pass every "falls back" case on its own.
 */
final class ResolveBillingCurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'magic-starter.billing.pricing' => [
                'currency' => 'USD',
                'country_header' => 'CF-IPCountry',
                'country_currencies' => [
                    'TR' => 'TRY',
                    'DE' => 'EUR',
                ],
                'commission' => [
                    'mode' => 'absorb',
                    'rate' => 0.15,
                ],
            ],
        ]);
    }

    public function test_the_contract_is_bound_to_the_default_action(): void
    {
        $this->assertInstanceOf(ResolveBillingCurrency::class, $this->app->make(ResolvesBillingCurrency::class));
    }

    public function test_a_mapped_country_resolves_to_its_currency(): void
    {
        $this->assertSame('TRY', $this->resolve(['CF-IPCountry' => 'TR']));
        $this->assertSame('EUR', $this->resolve(['CF-IPCountry' => 'DE']));
    }

    /**
     * A header Cloudflare always sends uppercase is still read whatever its
     * case, so a proxy that lowercases it does not move a customer to the base.
     */
    public function test_the_country_is_read_whatever_its_case(): void
    {
        $this->assertSame('TRY', $this->resolve(['CF-IPCountry' => 'tr']));
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function unknownCountries(): array
    {
        return [
            'no header' => [[]],
            'unknown location' => [['CF-IPCountry' => 'XX']],
            'Tor exit' => [['CF-IPCountry' => 'T1']],
            'unmapped country' => [['CF-IPCountry' => 'FR']],
            'not a country code' => [['CF-IPCountry' => 'TUR']],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('unknownCountries')]
    public function test_a_country_that_maps_to_nothing_resolves_to_the_base(array $headers): void
    {
        $this->assertSame('USD', $this->resolve($headers));
    }

    /**
     * `XX` is refused even when an adopter maps it, because it means the
     * location is unknown and a currency chosen for "unknown" is a guess.
     */
    public function test_an_unknown_location_is_not_mapped_even_when_configured(): void
    {
        config(['magic-starter.billing.pricing.country_currencies.XX' => 'TRY']);

        $this->assertSame('USD', $this->resolve(['CF-IPCountry' => 'XX']));
    }

    /**
     * With no header named the feature is off, so a request that happens to
     * carry one is still answered in the base currency.
     */
    public function test_the_header_is_ignored_while_no_header_is_configured(): void
    {
        config(['magic-starter.billing.pricing.country_header' => null]);

        $this->assertSame('USD', $this->resolve(['CF-IPCountry' => 'TR']));
    }

    /**
     * The pin is the currency Stripe already bills this customer in, so a
     * trip abroad must not move the screen away from it.
     */
    public function test_the_pin_beats_the_country(): void
    {
        $this->assertSame('USD', $this->resolve(['CF-IPCountry' => 'TR'], $this->billable('USD')));
        $this->assertSame('TRY', $this->resolve(['CF-IPCountry' => 'DE'], $this->billable('try')));
    }

    public function test_an_unpinned_billable_follows_the_country(): void
    {
        $this->assertSame('TRY', $this->resolve(['CF-IPCountry' => 'TR'], $this->billable(null)));
    }

    /**
     * A guest or a team-less caller has no billable and still sees a price.
     */
    public function test_a_caller_with_no_billable_follows_the_country(): void
    {
        $this->assertSame('TRY', $this->resolve(['CF-IPCountry' => 'TR'], null));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function resolve(array $headers, ?Model $billable = null): string
    {
        $request = Request::create('/billing/plans');

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $this->app->make(ResolvesBillingCurrency::class)->resolve($request, $billable);
    }

    private function billable(?string $pin): Model
    {
        $billable = new ResolveBillingCurrencyBillable;
        $billable->setAttribute('billing_currency', $pin);

        return $billable;
    }
}

class ResolveBillingCurrencyBillable extends Model
{
    protected $guarded = [];
}
