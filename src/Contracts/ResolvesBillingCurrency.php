<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Contract for choosing the web currency a caller is shown and charged in.
 *
 * It answers the CALLER's currency, before any product is asked whether it is
 * priced in it: the plans screen and the checkout each fall back per product
 * from this answer, so the two always land on the same figure.
 *
 * It is asked only of a caller with no fixed currency: once the billable has a
 * pin, or a Stripe customer already billed in one, that currency is the
 * answer and the resolver is not consulted.
 *
 * Bound so an adopter can replace the rule (a currency chosen from the user's
 * own settings, a geolocation service instead of a proxy header) without
 * touching the endpoints that read it.
 *
 * @see \FlutterSdk\MagicStarter\Actions\ResolveBillingCurrency the default rule
 */
interface ResolvesBillingCurrency
{
    /**
     * The uppercase ISO 4217 code [$request]'s caller is shown prices in.
     *
     * @param  Model|null  $billable  The subject the caller bills, or null for a caller with
     *                                none (a guest, a team-less user), who still sees prices.
     */
    public function resolve(Request $request, ?Model $billable): string;
}
