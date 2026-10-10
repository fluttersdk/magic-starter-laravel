<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Actions\SubscriptionGuardedDeleteTeam;
use FlutterSdk\MagicStarter\Contracts\ReportsUsage;
use FlutterSdk\MagicStarter\Enums\BillingChannel;
use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\Enums\ProductType;
use FlutterSdk\MagicStarter\Http\Resources\SubscriptionResource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\BillingTrial;
use FlutterSdk\MagicStarter\Policies\BillingPolicy;
use FlutterSdk\MagicStarter\Support\BillingCatalogue;
use FlutterSdk\MagicStarter\Support\BillingEventRecorder;
use FlutterSdk\MagicStarter\Support\BillingLog;
use FlutterSdk\MagicStarter\Support\JsonObject;
use FlutterSdk\MagicStarter\Support\PriceTable;
use FlutterSdk\MagicStarter\Support\ReadsBillableAttributes;
use FlutterSdk\MagicStarter\Support\StripeBillingState;
use FlutterSdk\MagicStarter\Support\StripeSubscriptionState;
use FlutterSdk\MagicStarter\Support\TrialEligibility;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\PaymentMethod;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeObject;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The billing subject's own endpoints: the entitlement read, the catalogue, the
 * usage report, the invoice page, the card, the store-conflict check and the
 * portal session, plus the three card-rail writes (checkout, swap, cancel).
 *
 * Every action resolves the SUBJECT from the acting user rather than from a
 * route parameter, and what that subject is depends on
 * `magic-starter.billing.billable`: under `'user'` the caller IS the billable,
 * under `'team'` it is the caller's `currentTeam`. Nothing here accepts a
 * billable id, so there is no id to tamper with.
 *
 * THE REFUSALS, AND WHY TELLING THEM APART IS THE POINT
 *
 * The reads are open to any member and the writes belong to the owner
 * ({@see BillingPolicy}), so a wrong caller or a wrong rail has four different
 * answers and each one drives a different next step in the client:
 *
 * - 404, there is no subject to act on. Either no current team at all, or a
 *   `current_team_id` pointing at a team the caller no longer belongs to. It is
 *   masked as absence rather than refused as forbidden, because a 403 would
 *   confirm the team exists.
 * - 403, the caller is a member and not the owner. Raised BEFORE anything about
 *   the subscription is read, so a refused caller learns nothing about whether
 *   one exists.
 * - 409 with {@see self::REASON_MANAGED_BY_STORE}, the subscription is real but
 *   sits on a rail this application cannot act on.
 * - 409 with {@see self::REASON_NO_BILLING_ACCOUNT}, there is no billing account
 *   behind the subject at all. A distinct fact from the one above and it leads
 *   somewhere else entirely: "manage this where you bought it" and "there is
 *   nothing to manage yet" are opposite instructions.
 * - 404 from a write, there is no card-rail subscription to change. Reached only
 *   AFTER the store guard, so it can never claim there is nothing to cancel
 *   while a store is still charging the customer every month.
 * - 422 with {@see self::REFUSAL_PRODUCT_NOT_SELLABLE}, the request named a
 *   catalogue key this rail cannot sell: unknown, not a subscription, the free
 *   floor, or a subscription with no Stripe price. Without a `code`, the
 *   adopter has published no tier ranking at all. Both share one remedy shape
 *   (fix the config, or ask for a product that exists) and each carries its
 *   own sentence.
 *
 * EVERY CASHIER CALL SITS BEHIND `method_exists()`, and that is not defensive
 * padding. This package ships the billing COLUMNS and the endpoints, not the
 * model that carries them: applying Cashier's `Billable` trait is the consuming
 * application's decision, and an application selling only through the two app
 * stores has no reason to have applied it. A direct call on such a model is a
 * fatal `Error` on the billing screen rather than a missing field, so an absent
 * trait reads as "this subject has no card rail" instead.
 *
 * Provenance columns are read through {@see ReadsBillableAttributes} for the
 * mirror-image reason: the package ships the column and the consumer decides
 * whether to cast it, so a typed read is a `TypeError` on one adopter's model
 * and correct on another's.
 */
class BillingController
{
    use ReadsBillableAttributes;

    /**
     * The subscription is real but a store sold it, so the store manages it.
     *
     * A machine-readable reason rather than only a sentence: the client has to
     * render "manage this in the store that sold it" instead of a dead-end
     * toast, and parsing a localised sentence to decide that is not a contract.
     * The rail itself travels beside it as `billing.provider`, so the client
     * names the right store without this constant having to multiply per rail.
     */
    public const REASON_MANAGED_BY_STORE = 'managed_by_store';

    /**
     * There is no billing account to manage: nothing has ever charged this
     * subject, so no Stripe customer exists behind it.
     *
     * DISTINCT from the reason above, because it is a distinct fact and leads
     * somewhere else. A single shared code would leave the client guessing
     * which of two opposite instructions it had been given.
     */
    public const REASON_NO_BILLING_ACCOUNT = 'no_billing_account';

    /**
     * This card rail is already billing the subject, so a checkout would open a
     * SECOND subscription beside the first.
     *
     * A third reason rather than reusing either above, because it leads
     * somewhere neither of those does: the customer is not being told to go
     * elsewhere and not being told there is nothing to manage, they are being
     * told to CHANGE what they already have. The client's next step is `swap`.
     */
    public const REASON_SUBSCRIPTION_EXISTS = 'subscription_exists';

    /**
     * The 422 `code` for a checkout or swap naming a product this rail cannot
     * sell.
     *
     * One code for every way a key can fail, because the client's next step is
     * the same for all of them (offer something else) and the sentence beside
     * it already tells an adopter which config closes the gap.
     */
    public const REFUSAL_PRODUCT_NOT_SELLABLE = 'product_not_sellable';

    /**
     * How many invoices one page of the invoice list carries.
     *
     * Untyped on purpose: the package's PHP floor is 8.2 and a typed class
     * constant is 8.3, so it would be a parse error on the oldest supported
     * runtime rather than a version warning.
     */
    public const INVOICES_PER_PAGE = 24;

    /**
     * @param  TrialEligibility  $trialEligibility  Who may start a trial; a container
     *                                              binding, so an adopter can replace it.
     * @param  BillingEventRecorder  $recorder  Where every write and every 409 refusal is
     *                                          recorded, always AFTER the rail has answered.
     */
    public function __construct(
        protected TrialEligibility $trialEligibility,
        protected BillingEventRecorder $recorder,
    ) {}

    /**
     * Read the billable's current entitlement.
     */
    public function show(Request $request): SubscriptionResource
    {
        return SubscriptionResource::make($this->resolveBillable($request));
    }

    /**
     * Return the adopter's tier rows, floor first, each carrying the catalogue
     * products that sell it.
     *
     * Served from config with no rail call, so it is safe on the hot path. The
     * one per-subject fact is each product row's `trial_days`, the trial THIS
     * caller would get ({@see self::trialOffered()}): read from the local
     * database once per request, and not at all while no product offers a
     * trial, so an adopter selling without trials pays no query and needs no
     * `billing_trials` table. The rows come from
     * {@see ReadsBillableAttributes::planCatalogue()}, which is
     * {@see BillingCatalogue::tiers()}: the `tier_order` ranking decides which
     * tiers exist and in what order, and the `tiers` map only describes them.
     *
     * `data` stays a LIST of tier rows rather than a list of products, because
     * the client decoder refuses anything else and a plan grid is drawn per
     * tier. A product rides inside the row of the tier it sells; a one-off
     * product names no tier and has no row to ride in.
     *
     * Entries reach the client as configured but for two things. `cycles` and
     * `products` are DERIVED by {@see self::sellableCatalogue()} from the
     * products and written over, and the display copy is translated into the
     * request locale ({@see self::translatedTierCopy()}). Everything else (a
     * tier's limits, any data keyed by name) is the adopter's product knowledge
     * and passes through untouched.
     *
     * An adopter who has published nothing gets an empty list rather than a 404.
     * The catalogue being empty is a legitimate state (a fresh install sells
     * nothing yet) and it is not the same fact as "this endpoint is not wired",
     * which is what `billing/usage` reports when nobody has bound its contract.
     */
    public function plans(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->sellableCatalogue($this->trialOffered($request)),
        ]);
    }

    /**
     * Whether the caller would be given a trial on any product that offers one.
     *
     * Resolved ONCE per request rather than per product row: eligibility is a
     * fact about the caller and their subject, never about a product, so every
     * row of one answer agrees.
     *
     * Two ways to answer false without asking {@see TrialEligibility}:
     *
     * - No catalogue product offers a trial. Asked first because it is config
     *   alone, and it is what keeps an adopter who never ran the `billing_trials`
     *   migration off that table entirely.
     * - There is no subject. A team-subject caller with no current team, or a
     *   pointer at a team they left, gets the plan grid as before; the other
     *   reads answer that caller 404, and this one never has.
     */
    protected function trialOffered(Request $request): bool
    {
        if (! BillingCatalogue::offersTrials()) {
            return false;
        }

        $billable = $this->currentBillable($request);

        if ($billable === null) {
            return false;
        }

        return $this->trialEligibility->allows($request->user(), $billable);
    }

    /**
     * The tier rows, each told which cycles the card rail can sell it on and
     * which subscription products it is sold as.
     *
     * `cycles` counts only SELLABLE subscriptions with a Stripe price, because
     * it is what a web billing screen offers: a tier priced on the stores only
     * would otherwise render a web button the customer learns about from a 422
     * AFTER committing to buy.
     *
     * `products` lists every subscription of the tier, on any rail and sellable
     * or not, each flagged `sellable` and carrying its `store_ids`. A product
     * kept only so an old price still maps is listed because a client ranks
     * what a customer HOLDS against the row, and a grandfathered product missing
     * from it could not be placed; the flag is what keeps the client from
     * offering it. A one-off product names no subscription of any tier, so it
     * rides in no row even when it carries a `tier`.
     *
     * Both writes are unconditional, so an adopter's own `cycles` or `products`
     * on a tier definition is replaced: two values under one key, one
     * hand-written and one derived, is a disagreement no reader can resolve.
     *
     * Each product's `trial_days` is the trial THIS caller would get, which is
     * the configured length only while [$trialOffered] holds: a row promising a
     * trial the checkout would then not start is a charge on day one against
     * what the screen said.
     *
     * @param  bool  $trialOffered  Whether the caller may start a trial ({@see self::trialOffered()}).
     * @return array<int, array<string, mixed>>
     */
    protected function sellableCatalogue(bool $trialOffered): array
    {
        $sellable = [];
        $products = [];
        $pricing = BillingCatalogue::pricing();

        foreach (BillingCatalogue::products() as $product) {
            if ($product['type'] !== ProductType::SUBSCRIPTION || $product['tier'] === null) {
                continue;
            }

            if ($product['sellable']
                && $product['refs']['stripe_price'] !== null
                && $product['cycle'] !== null
            ) {
                $sellable[$product['tier']][$product['cycle']->value] = true;
            }

            $products[$product['tier']][] = [
                'key' => $product['key'],
                'type' => $product['type']->value,
                'tier' => $product['tier'],
                'cycle' => $product['cycle']?->value,
                'sellable' => $product['sellable'],
                'trial_days' => $trialOffered ? $product['trial_days'] : 0,
                'store_ids' => [
                    BillingChannel::APP_STORE->value => $product['refs'][BillingChannel::APP_STORE->value],
                    BillingChannel::PLAY->value => $product['refs'][BillingChannel::PLAY->value],
                ],
                'prices' => [
                    BillingChannel::WEB->value => JsonObject::map(PriceTable::display(
                        PriceTable::for($product, BillingChannel::WEB, $pricing),
                    )),
                ],
            ];
        }

        return array_map(
            function (array $entry) use ($sellable, $products): array {
                $id = is_string($entry['id'] ?? null) ? $entry['id'] : null;

                $entry = $this->translatedTierCopy($entry);
                $entry['cycles'] = $id === null
                    ? []
                    : array_keys($sellable[$id] ?? []);
                $entry['products'] = $id === null
                    ? []
                    : $products[$id] ?? [];

                return $entry;
            },
            $this->planCatalogue(),
        );
    }

    /**
     * The tier row with its display copy in the request locale.
     *
     * The adopter's English copy is the translation KEY, the convention of a
     * Laravel JSON translation file: `lang/tr.json` maps the English sentence
     * to the Turkish one, and `__()` answers the input unchanged when no line
     * exists. Translated per request, never cached, because the locale is the
     * caller's.
     *
     * Every top-level string is copy except `id`, the identifier a client
     * matches the entitlement against, and so is every string inside a LIST
     * (the `features` bullets). An associative array is left alone: `limits`
     * and anything shaped like it is data the adopter's client reads by key.
     * `cycles` and `products` are written after this runs, so they are never
     * seen here.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    protected function translatedTierCopy(array $entry): array
    {
        foreach ($entry as $key => $value) {
            if ($key === 'id') {
                continue;
            }

            if (is_string($value)) {
                $entry[$key] = $this->translatedLine($value);

                continue;
            }

            if (is_array($value) && array_is_list($value)) {
                $entry[$key] = array_map(
                    fn (mixed $line): mixed => is_string($line) ? $this->translatedLine($line) : $line,
                    $value,
                );
            }
        }

        return $entry;
    }

    /**
     * One line of copy through `__()`, never anything but a string.
     *
     * `__()` falls back to the PHP translation GROUP of the same name when the
     * JSON lookup misses, so copy that happens to read `validation` would come
     * back as the whole validation file. Such a line is served as written.
     */
    private function translatedLine(string $line): string
    {
        $translated = __($line);

        return is_string($translated) ? $translated : $line;
    }

    /**
     * Report the billable's consumption against the limits its plan caps.
     *
     * The counting itself is consumer-domain and leaves the package entirely
     * through {@see ReportsUsage}: what a plan caps (seats, projects, monitors,
     * messages) and what a tier's limits are is knowledge this package does not
     * have and cannot fake. The route is registered only while a consumer has
     * bound that contract, so reaching this method means an implementation
     * exists; an unbound package answers 404 here, which is an honest "not wired
     * yet" rather than an empty map a cap would read as "you have used nothing".
     */
    public function usage(Request $request): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        return response()->json(app(ReportsUsage::class)->forBillable($billable));
    }

    /**
     * Report the caller's OTHER billable that a store account is already
     * funding, so the client can refuse a store purchase by NAME instead of
     * silently transferring one.
     *
     * The structural fact behind it: two store SKUs share a subscription group
     * so that upgrade and downgrade work at all, and a store account holds at
     * most one active subscription per group. So a second purchase from the same
     * account does not open a second subscription, it MOVES the one that exists,
     * and the subject that had it silently stops being funded. The client hides
     * its purchase CTA on this answer; the entitlement stays honest either way,
     * because the rail's transfer handling revokes the source and grants the
     * destination. This exists so a customer is not surprised.
     *
     * WHAT IT CANNOT SEE, said plainly: the store ACCOUNT. The store aggregator's
     * app user id is the billable's key, so from here every purchase looks like a
     * fresh customer, and the honest proxy is the subjects this caller OWNS. That
     * covers one person with two teams and one store account, which is the common
     * case. It does not cover two people sharing one store account, which needs
     * the store SDK's original app user id.
     *
     * Subjects the caller merely BELONGS TO are excluded, and that is not laxity:
     * only an owner can buy ({@see BillingPolicy}), so a member's team was funded
     * by ITS owner's store account and says nothing about this caller's. Counting
     * it would refuse a legitimate first purchase to anybody who has ever joined
     * a store-billed team.
     *
     * A READ, so it is open to any member like the other reads: it reports only
     * on subjects the caller already owns, and gating it on ownership would 403 a
     * mount-time fetch the client makes before it knows who is asking.
     */
    public function storeFundedTeam(Request $request): JsonResponse
    {
        $billable = $this->resolveBillable($request);
        $funded = $this->otherStoreFundedBillable($request->user(), $billable);

        return response()->json([
            'store_funded_team' => $funded === null ? null : [
                'id' => $funded->getKey(),
                'name' => $funded->getAttribute('name'),
            ],
        ]);
    }

    /**
     * Cursor-paginate the billable's Stripe invoices.
     *
     * Cashier takes the cursor as its FOURTH argument, so it is passed by name;
     * the encoded next cursor rides alongside the data for the client's "load
     * more". The query value is narrowed to a string first because a request
     * parameter can arrive as an array, and an array is not a cursor.
     *
     * A billable with no Cashier trait answers an empty page rather than an
     * error: invoices are a card-rail artefact, and an application that sells
     * only in the app stores has none to show. That is the same reading
     * {@see SubscriptionResource} gives an absent trait.
     */
    public function invoices(Request $request): JsonResponse
    {
        $billable = $this->resolveBillable($request);
        $cursor = $request->query('cursor');

        if (! method_exists($billable, 'cursorPaginateInvoices')) {
            return response()->json([
                'data' => [],
                'next_cursor' => null,
            ]);
        }

        // Positional and with both defaults written out, because the cursor is
        // Cashier's FOURTH parameter and the argument NAMES are not visible
        // through a consumer's model: named arguments here do not resolve for a
        // reader, static or human, that cannot see which trait supplied the
        // method. The closure below carries the element type for the same
        // reason.
        $invoices = $billable->cursorPaginateInvoices(
            self::INVOICES_PER_PAGE,
            [],
            'cursor',
            is_string($cursor) ? $cursor : null,
        );

        return response()->json([
            'data' => array_map(
                fn (Invoice $invoice): array => $this->invoiceWire($invoice),
                $invoices->items(),
            ),
            'next_cursor' => $invoices->nextCursor()?->encode(),
        ]);
    }

    /**
     * Return the billable's default card and renewal date.
     *
     * This is the ONLY rail-live billing endpoint and it is kept off the
     * entitlement hot path. It soft-fails on a Stripe API error only: the error
     * is logged and the four card fields return null with a 200, so a Stripe
     * outage degrades this one card instead of 500-ing the whole billing screen.
     * Any other exception propagates, because folding an unrelated bug into the
     * same 200 would hide it behind an outage that never happened.
     *
     * `available` is the field that lets the two failure shapes be told apart on
     * the wire: `false` means the rail could not be asked (the catch fired),
     * `true` with the four card fields null means the rail answered and there is
     * genuinely no card on file. Without it the two bodies are byte-identical, so
     * a Stripe outage reads to the client as "no card" and the client is left
     * reconstructing the difference from an unrelated field.
     */
    public function paymentMethod(Request $request): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        try {
            // 1. The renewal date favours the local trial end (a plain column
            //    read) and only falls back to the live period end when there is
            //    no trial, which is a rail retrieval.
            //
            //    This endpoint used to make exactly one, and the docblocks below
            //    said so. It no longer does: on the common post-checkout state
            //    it retrieves the customer (for the default payment method), the
            //    subscription (for the card the checkout left there) and the
            //    period per item. That is the cost of answering honestly for a
            //    customer whose card Stripe filed on the subscription, and it is
            //    still the only rail-live read on the billing screen.
            $subscription = $this->defaultSubscription($billable);
            $renewalDate = $subscription === null
                ? null
                : $this->dateAttribute($subscription, 'trial_ends_at') ?? $this->periodEnd($subscription);

            // 2. Only a Cashier PaymentMethod exposes a card, and a billable
            //    whose application never applied Cashier's trait has none to
            //    read. The subscription is passed because a hosted checkout
            //    leaves the card there rather than on the customer, and a legacy
            //    Stripe Source now falls THROUGH to it rather than yielding null
            //    outright: a Source fails the `instanceof PaymentMethod` test,
            //    which is the same door the customer-has-no-default case takes.
            $card = $this->defaultCard($billable, $subscription);

            // 3. The rail answered. Whether it had a card to show is the card
            //    fields' business, not this flag's.
            return response()->json([
                'available' => true,
                'renewal_date' => $renewalDate?->toIso8601String(),
                'brand' => $card?->brand,
                'last4' => $card?->last4,
                'exp_month' => $card?->exp_month,
                'exp_year' => $card?->exp_year,
            ]);
        } catch (ApiErrorException $exception) {
            // Stripe's ApiConnectionException extends this one, so a downed
            // network or a bad TLS certificate is caught here too; this is the
            // only outage shape this endpoint soft-fails on.
            BillingLog::warning('Failed to read the billable payment method from Stripe.', [
                'billable_id' => $billable->getKey(),
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'available' => false,
                'renewal_date' => null,
                'brand' => null,
                'last4' => null,
                'exp_month' => null,
                'exp_year' => null,
            ]);
        }
    }

    /**
     * Return the Stripe billing portal URL for the billable.
     *
     * The customer guard is not defensive, it is the ordinary path. Cashier's
     * `billingPortalUrl()` opens with `assertCustomerExists()`, which throws the
     * moment `hasStripeId()` is false, so every subject that has never been
     * charged (which is most of them) would 500 out of this endpoint.
     *
     * A write, so it is the owner's: opening a portal session hands the caller a
     * surface that can cancel a subscription and remove a card.
     */
    public function portal(Request $request): JsonResponse
    {
        $billable = $this->resolveBillableForBillingChange($request);

        // Before the customer check, not after: a store-billed subject keeps
        // whatever `stripe_id` an earlier card subscription left behind, so
        // `hasStripeId()` is true and the portal would open onto a Stripe
        // subscription that is not the one charging them. The rail is the more
        // specific and the more actionable fact of the two.
        $this->guardStoreOwnedSubscription($billable);

        if (! method_exists($billable, 'billingPortalUrl') || ! $billable->hasStripeId()) {
            $this->abortWithBillingConflict(
                $billable,
                self::REASON_NO_BILLING_ACCOUNT,
                BillingProvider::fromWire($this->stringAttribute($billable, 'plan_provider')),
                __('magic-starter::billing.refusals.no_billing_account'),
            );
        }

        $returnUrl = $request->query('return_url');
        $portalUrl = $billable->billingPortalUrl(is_string($returnUrl) ? $returnUrl : null);

        // Recorded once the rail has minted the URL: a session Stripe refused to
        // open is not one the customer was given.
        $this->recordRequest(BillingEventType::PORTAL_OPENED, $request, $billable);

        return response()->json([
            'portal_url' => $portalUrl,
        ]);
    }

    /**
     * Begin a Stripe Checkout session for a catalogue product, unwrapped to a
     * JSON `{checkout_url, session_id}` shape.
     *
     * Cashier's `Checkout` object is never returned or redirected to directly.
     * It is `Responsable` and renders an HTML redirect, which is not an answer a
     * JSON client can follow, so only its two useful fields travel.
     *
     * WHAT MAY BE BOUGHT is a product KEY from the adopter's catalogue, the same
     * key a store purchase names, and only one this rail can honestly sell
     * ({@see self::sellablePriceId()}). The key fixes the tier and the cycle
     * together, so the price charged is the one the screen showed.
     */
    public function checkout(Request $request): JsonResponse
    {
        // 1. Authorized before the body is read: an unauthorized caller's input
        //    is not worth validating, and a 422 ahead of the 403 would tell them
        //    the request shape was the only thing wrong with it.
        $billable = $this->resolveBillableForBillingChange($request);

        // 2. A store already charging this subject must not be able to acquire a
        //    second, parallel Stripe subscription. The entitlement writer warns
        //    when two rails claim one billable, but a warning arrives after the
        //    money has moved; this refuses at the point of sale. The client hides
        //    its CTA too, and a client gate is an affordance rather than the
        //    enforcement.
        $this->guardStoreOwnedSubscription($billable);

        // 2b. A subject this rail is ALREADY billing must not open a second
        //     subscription beside the first. `newSubscription()` happily creates
        //     one, so without this a customer who cancels and buys again ends up
        //     holding two live Stripe subscriptions and paying both, and the
        //     damage does not stop at the double charge: both rows carry
        //     `type = 'default'`, so `subscription('default')` becomes ambiguous
        //     for `swap`, `cancel` and the period read, and the eventual
        //     deletion of the older one revokes the entitlement the newer one is
        //     paying for. Measured end to end against a live Stripe test
        //     account, on a cancelled-but-still-active subscription.
        //
        //     A CANCELLED subscription counts as live while it still grants,
        //     which is the case this guard exists for: that is exactly when a
        //     customer is most likely to buy again, and it is the one moment the
        //     store guard above has nothing to say about. `swap` is the way to
        //     change what such a customer is paying for, and it un-cancels as a
        //     side effect, so nothing is unreachable behind this refusal.
        $this->guardExistingCardSubscription($billable);

        // 3. Rail facts before input facts. A subject whose application never
        //    applied Cashier's trait has no card rail to check out on, and a
        //    direct call on such a model is a fatal `Error` on the billing screen
        //    rather than a refusal the client can render.
        //    The method it asks for is the one step 6 calls. Probing a different
        //    Cashier method would answer a question this step is not asking.
        if (! method_exists($billable, 'newSubscription')) {
            $this->abortWithBillingConflict(
                $billable,
                self::REASON_NO_BILLING_ACCOUNT,
                BillingProvider::fromWire($this->stringAttribute($billable, 'plan_provider')),
                __('magic-starter::billing.refusals.no_billing_account'),
            );
        }

        // 4. The product is a catalogue key; the two URLs are where Stripe sends
        //    the customer back and are the client's to choose.
        $this->guardPublishedRanking();

        $validated = $request->validate([
            'product' => ['required', 'string'],
            'success_url' => ['required', 'string', 'url'],
            'cancel_url' => ['required', 'string', 'url'],
        ]);

        // 5. Only a paid subscription with a Stripe price reaches the rail. A
        //    store-only product is a config gap and not a client fault, so it is
        //    refused by a sentence naming the ref that closes it rather than
        //    checked out against some other product's price.
        $priceId = $this->sellablePriceId($validated['product']);

        // 6. One price, through the SUBSCRIPTION builder. Quantity is not the
        //    client's to send: a request body carrying it would let a caller
        //    decide what they are charged.
        //
        //    `Billable::checkout()` is the wrong door and cannot be made to
        //    work. It routes to `Checkout::create`, whose mode defaults to
        //    `Session::MODE_PAYMENT`, and Stripe refuses a recurring price in
        //    payment mode: "You specified `payment` mode but passed a recurring
        //    price." Every price a subscription catalogue maps is recurring, so
        //    that call opened no session for anything this package sells. The
        //    builder is what asks for `mode: subscription`.
        //
        //    The subscription NAME matters as much as the mode: `swap` and
        //    `cancel` below both act on `subscription('default')`, so a checkout
        //    opened under any other name would sell a subscription neither of
        //    them could reach.
        $subscription = $billable->newSubscription(StripeSubscriptionState::SUBSCRIPTION_TYPE, $priceId);

        // 7. The product's trial, for a caller who may have one. Eligibility is
        //    asked only when the product offers days, so a catalogue without
        //    trials never reads `billing_trials`. An ineligible caller (a guest,
        //    somebody who trialed, a returning customer) is not refused: they
        //    buy at the full price, which is what they were shown. The metadata
        //    names the acting USER, because under the team subject the Stripe
        //    customer is the team and the webhook needs the person.
        $trialDays = $this->trialDaysFor($validated['product']);
        $user = $request->user();
        $offeredTrialDays = 0;

        if ($trialDays > 0 && $this->trialEligibility->allows($user, $billable)) {
            $offeredTrialDays = $trialDays;

            $subscription
                ->trialDays($trialDays)
                ->withMetadata([
                    BillingTrial::USER_METADATA_KEY => (string) $user->getKey(),
                ]);
        }

        // 8. A card is collected whatever the first total, and said explicitly
        //    rather than left to Stripe's default: a trial's first invoice is
        //    zero, a session that skipped the card would end the trial on a
        //    customer with nothing to charge, and the card is what the trial
        //    check fingerprints. Sent on every checkout, trial or not.
        $checkout = $subscription->checkout([
            'success_url' => $validated['success_url'],
            'cancel_url' => $validated['cancel_url'],
            'payment_method_collection' => 'always',
        ]);

        // 9. Recorded once the session exists, never before: a Stripe error above
        //    leaves no row, and the row's external id is the session itself. The
        //    trial is the days OFFERED to this caller, which is 0 for one the
        //    product's advertised days were withheld from.
        $this->recordRequest(
            BillingEventType::CHECKOUT_STARTED,
            $request,
            $billable,
            externalId: $checkout->id,
            properties: [
                'product' => $validated['product'],
                'price_id' => $priceId,
                'trial_days' => $offeredTrialDays,
            ],
        );

        return response()->json([
            'checkout_url' => $checkout->url,
            'session_id' => $checkout->id,
        ]);
    }

    /**
     * Swap the billable's default subscription onto a different catalogue
     * product's price.
     *
     * A product key carries its cycle, so moving from monthly to annual on the
     * same tier is a swap like any other rather than a change this endpoint
     * cannot express.
     *
     * The entitlement on the wire afterwards is still the LOCAL one, because a
     * swap is a Stripe write and the provenance columns are written by the
     * webhook that follows it. That is deliberate: this endpoint returning a
     * hand-patched tier would make the billing screen disagree with the rail for
     * as long as it took the event to arrive, and disagree permanently if it
     * never did.
     */
    public function swap(Request $request): SubscriptionResource
    {
        // 1. Authorized first, for the reason checkout gives.
        $billable = $this->resolveBillableForBillingChange($request);

        // 2. The rail before the input, which is the order the application this
        //    was ported from used on two of its three writes and not on this
        //    one. Normalised rather than preserved: a store-billed customer told
        //    their request body was malformed learns nothing they can act on, and
        //    which rail owns the subscription does not depend on the body.
        $this->guardStoreOwnedSubscription($billable);

        $this->guardPublishedRanking();

        $validated = $request->validate([
            'product' => ['required', 'string'],
        ]);

        // 3. The same sellability rule as checkout's, and before the
        //    subscription is read: a product a checkout could not have named
        //    must not be reachable through a swap either.
        $priceId = $this->sellablePriceId($validated['product']);

        // 4. Reached only on a rail this application controls, so an absent
        //    subscription really does mean there is nothing to swap.
        $subscription = $this->actionableSubscription($billable, 'swap');

        // 5. Recorded after the rail answers, and on BOTH of its two outcomes
        //    that mean the plan moved. Cashier throws `IncompletePayment` from
        //    the END of `swap()`, after Stripe has already updated the
        //    subscription and the local row, so the swap happened and the
        //    customer still owes an action: it is recorded flagged, and the
        //    exception goes on to the caller as the same instance, so the answer
        //    is exactly what it was. Every other failure records nothing, and
        //    not every one of them means nothing moved: an incomplete
        //    subscription refused up front, or a Stripe API error on the update
        //    itself, leaves the plan where it was, but an API error AFTER the
        //    update (the meter lookup for a metered item) leaves Stripe and the
        //    local row on the new price with no request row. The webhook that
        //    update triggers still records the outcome as `entitlement_applied`.
        try {
            $subscription->swap($priceId);
        } catch (IncompletePayment $exception) {
            $this->recordSwap($request, $billable, $subscription, $validated['product'], $priceId, [
                'payment' => 'incomplete',
            ]);

            throw $exception;
        }

        $this->recordSwap($request, $billable, $subscription, $validated['product'], $priceId);

        return SubscriptionResource::make($billable);
    }

    /**
     * Cancel the billable's default subscription at the end of the period it has
     * already paid for.
     *
     * `cancel()` and not `cancelNow()`: the customer has bought the period they
     * are in, and taking it away the moment they click is a refund this
     * application is not offering.
     */
    public function cancel(Request $request): SubscriptionResource
    {
        // 1. Authorized first, for the reason checkout gives.
        $billable = $this->resolveBillableForBillingChange($request);

        // 2. Before the subscription read, always. Without it a store-billed
        //    subject falls through to the 404 below, which tells a customer a
        //    store is charging every month that they have nothing to cancel.
        $this->guardStoreOwnedSubscription($billable);

        // 3. So the 404 here is honest: this rail has nothing to cancel.
        $subscription = $this->actionableSubscription($billable, 'cancel');

        $subscription->cancel();

        // 4. After the rail, so a Stripe error leaves no row. `ends_at` is read
        //    back from the subscription because that is the period the customer
        //    keeps, which is what a support question about it asks.
        $this->recordRequest(
            BillingEventType::SUBSCRIPTION_CANCELLED,
            $request,
            $billable,
            externalId: $this->stringAttribute($subscription, 'stripe_id'),
            properties: [
                'ends_at' => $this->dateAttribute($subscription, 'ends_at')?->toIso8601String(),
            ],
        );

        return SubscriptionResource::make($billable);
    }

    /**
     * Resolve the subject this request bills, 404-ing when there is none to act
     * on.
     *
     * Two arms, because `magic-starter.billing.billable` decides what a billable
     * IS. Under `'user'` the caller is the subject and there is nothing to
     * resolve or to mask: a caller cannot ask about somebody else's user row
     * through a route that takes no id. Under `'team'` the subject is the
     * caller's current team, and there are two ways there is none, both of which
     * answer 404 rather than 403. `current_team_id` is a plain nullable column,
     * so it can be unset, and it also SURVIVES the membership it points at being
     * removed: an ex-member keeps a pointer at a team that is no longer theirs.
     * Masking that as absence is the house rule, because a 403 would confirm the
     * team exists and a 200 would hand a stranger the billing state of a team
     * they were removed from.
     *
     * The membership question and the ownership question are deliberately
     * separate. This one asks whether the caller may see the subject at all;
     * whether they may spend its money is {@see self::resolveBillableForBillingChange()}.
     *
     * Neither 404 carries a sentence. Two absences answering with one message
     * would be one more string to keep identical in every locale, and an empty
     * body cannot leak which of the two fired.
     */
    protected function resolveBillable(Request $request): Model
    {
        $billable = $this->currentBillable($request);

        abort_if($billable === null, HttpResponse::HTTP_NOT_FOUND);

        return $billable;
    }

    /**
     * The subject this request bills, or null when there is none to act on.
     *
     * The single definition of "the caller's billable" for both
     * {@see self::resolveBillable()}, which masks a null as 404, and the plans
     * endpoint, which has always answered such a caller 200 and keeps doing so.
     * Both absences {@see self::resolveBillable()} describes are null here.
     */
    protected function currentBillable(Request $request): ?Model
    {
        $user = $request->user();
        $billableClass = MagicStarter::billableModel();

        if ($user instanceof $billableClass) {
            return $user;
        }

        // Reached only under the team subject. An application whose user model
        // has no team trait has no `currentTeam` relation either, so the read
        // lands on a null attribute and answers null here rather than reaching
        // the membership call below.
        $billable = $user->currentTeam;

        if (! $billable instanceof Model || ! $user->belongsToTeam($billable)) {
            return null;
        }

        return $billable;
    }

    /**
     * Resolve the subject for a billing WRITE, 403-ing a member who does not own
     * it.
     *
     * Ordered deliberately: the 404 mask runs first (a subject that is not there
     * cannot be refused for the wrong reason), then the ownership gate, and only
     * then does the caller reach anything that reads the subscription. An
     * unauthorized caller must not be able to tell from the response whether the
     * subject has a subscription at all.
     *
     * `Gate::forUser()` rather than the ambient `Gate::authorize()`, matching how
     * the package's team endpoints call their own policy: the user is already
     * resolved here and the request's guard is not worth re-resolving. The
     * ability is a NAMED one and never the billable model's policy, for the
     * reason {@see BillingPolicy} spells out.
     */
    protected function resolveBillableForBillingChange(Request $request): Model
    {
        $billable = $this->resolveBillable($request);

        Gate::forUser($request->user())->authorize('manageBilling', $billable);

        return $billable;
    }

    /**
     * Refuse a change to a subscription a store sold and therefore manages.
     *
     * Read through {@see BillingProvider::fromWire()} because `plan_provider` is
     * an UNCAST column by design: a rail this build has never heard of has to
     * land on `NONE` rather than raise, so it cannot turn a billing screen into
     * an outage. `NONE` and `STRIPE` both fall through to the caller, which is
     * correct: nothing is being managed elsewhere in either case.
     */
    protected function guardStoreOwnedSubscription(Model $billable): void
    {
        $provider = BillingProvider::fromWire($this->stringAttribute($billable, 'plan_provider'));

        if (! $provider->isStore()) {
            return;
        }

        $this->abortWithBillingConflict(
            $billable,
            self::REASON_MANAGED_BY_STORE,
            $provider,
            __('magic-starter::billing.refusals.managed_by_store'),
        );
    }

    /**
     * Refuse a checkout for a subject this card rail is already billing.
     *
     * `newSubscription()` does not care that one exists, so without this a
     * customer who cancels and buys again holds TWO live Stripe subscriptions
     * and pays both. The double charge is not the worst of it: Cashier stores
     * both under `type = 'default'`, so `subscription('default')` becomes
     * ambiguous for `swap`, `cancel` and the period read, and when the older one
     * finally deletes, its event revokes the entitlement the newer one is paying
     * for. Measured end to end against a live Stripe test account.
     *
     * A subscription counts as existing while it still GRANTS, which includes a
     * cancelled one inside its paid period. That is the whole point: it is the
     * moment a customer is most likely to buy again, and the only one the store
     * guard above says nothing about. `swap` remains open to them and un-cancels
     * as a side effect, so nothing is unreachable behind this refusal.
     *
     * The status is read from the LOCAL rows rather than from the rail: this runs
     * on the checkout path, the rows are what Cashier's own webhook handlers keep
     * in step, and a network read here would put a Stripe round trip in front of
     * every purchase.
     *
     * The cost of that choice, stated because it is a REGRESSION in one case
     * rather than merely a limitation: nothing in this package heals a local row
     * that Stripe has moved past. {@see \FlutterSdk\MagicStarter\Console\ReconcileBillingEntitlements} re-reads
     * the local subscription and never calls the Stripe API, so a dropped
     * `customer.subscription.deleted` leaves `stripe_status = 'active'` here
     * forever. Before this guard existed that customer could simply buy again;
     * now they are refused, and `swap` fails at the API against a subscription
     * that no longer exists, so they cannot buy at all. Rare (Stripe retries
     * deliveries for days) but unrecoverable without operator action, so an
     * adopter seeing a customer stuck behind `subscription_exists` should check
     * Stripe before looking anywhere else, and the fix when the rail disagrees
     * is to re-sync that row, not to loosen this guard.
     */
    protected function guardExistingCardSubscription(Model $billable): void
    {
        if (! method_exists($billable, 'subscriptions')) {
            return;
        }

        foreach ($billable->subscriptions as $subscription) {
            // SCOPED TO `default`, because that is the only subscription any
            // other write in this package can reach: `swap` and `cancel` both
            // resolve `subscription('default')`, which Cashier filters by type.
            // Refusing on a granting subscription of some OTHER type would close
            // the escape hatch this refusal points at, since `swap` would find
            // nothing and answer 409 `no_subscription`, and that customer could
            // not buy at all. Cashier's named types are an adopter-facing
            // feature and a subject may legitimately hold one.
            if ($subscription->type !== StripeSubscriptionState::SUBSCRIPTION_TYPE) {
                continue;
            }

            $status = $subscription->stripe_status;

            if (is_string($status) && StripeSubscriptionState::grants($status)) {
                $this->abortWithBillingConflict(
                    $billable,
                    self::REASON_SUBSCRIPTION_EXISTS,
                    BillingProvider::STRIPE,
                    __('magic-starter::billing.refusals.subscription_exists'),
                );
            }
        }
    }

    /**
     * Refuse every checkout and swap while the adopter publishes no tier
     * ranking.
     *
     * Boot refuses an empty `tier_order` under the billing feature, so this is
     * reached only by a config changed at runtime. It stays a refusal of its own
     * rather than folding into {@see self::sellablePriceId()}, because the fault
     * is in the adopter's config and not in the product the client named: the
     * sentence names the three catalogue keys that resolve it, where a
     * per-product refusal would send them reading their client's request body.
     *
     * @throws ValidationException When no tier ranking is published.
     */
    protected function guardPublishedRanking(): void
    {
        if ($this->tierOrder() !== []) {
            return;
        }

        throw ValidationException::withMessages([
            'product' => [__('magic-starter::billing.refusals.no_published_catalogue')],
        ]);
    }

    /**
     * The Stripe price of the catalogue product [$key], refusing a product this
     * rail cannot sell.
     *
     * Sellable here means five things at once, each closing a different way to
     * charge for the wrong thing: the key is in the catalogue; it is a
     * SUBSCRIPTION, because this rail opens subscription sessions only and a
     * one-off price would be refused by Stripe after the customer committed; it
     * is not marked `sellable: false`, since a retired price is kept mapped for
     * its existing subscribers and must not be bought again; its tier is ranked
     * and is not the free floor, which nothing sells; and it
     * carries a Stripe price, since a product without one is sold on the stores
     * only and reaching past it for another product's price would charge a
     * figure the screen did not show.
     *
     * The ranking is read per request rather than trusted from boot, so a tier
     * removed from `tier_order` at runtime stops selling at once.
     *
     * @throws ValidationException When the product is not sellable on this rail.
     */
    protected function sellablePriceId(string $key): string
    {
        $product = BillingCatalogue::product($key);

        if ($product === null
            || $product['type'] !== ProductType::SUBSCRIPTION
            || ! $product['sellable']
        ) {
            $this->refuseUnsellableProduct($key);
        }

        $tierOrder = $this->tierOrder();
        $tier = $product['tier'];
        $priceId = $product['refs']['stripe_price'];

        if ($tier === null
            || $tier === ($tierOrder[0] ?? null)
            || ! in_array($tier, $tierOrder, true)
            || $priceId === null
        ) {
            $this->refuseUnsellableProduct($key);
        }

        return $priceId;
    }

    /**
     * The trial length catalogue product [$key] offers, 0 when it offers none.
     *
     * Read after {@see self::sellablePriceId()} has accepted the key, so the
     * product exists; the fallback only keeps the type honest.
     */
    protected function trialDaysFor(string $key): int
    {
        return BillingCatalogue::product($key)['trial_days'] ?? 0;
    }

    /**
     * Refuse a product key with a 422 carrying a machine `code` beside the
     * localised sentence.
     *
     * Raised as a {@see ValidationException} so the `errors` bag still points a
     * form at the `product` field, with the body replaced so the `code` travels:
     * the client branches on it and never on the prose.
     *
     * @throws ValidationException Always.
     */
    protected function refuseUnsellableProduct(string $key): never
    {
        $message = (string) __('magic-starter::billing.refusals.product_not_sellable', ['product' => $key]);

        $exception = ValidationException::withMessages([
            'product' => [$message],
        ]);

        $exception->response = new JsonResponse([
            'message' => $message,
            'code' => self::REFUSAL_PRODUCT_NOT_SELLABLE,
            'errors' => $exception->errors(),
        ], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);

        throw $exception;
    }

    /**
     * The card-rail subscription a write acts on, 404-ing when there is none.
     *
     * The 404 is a DIFFERENT fact from the store 409 rather than a milder
     * version of it: one says a subscription exists on a rail this application
     * cannot act on, the other says none exists here at all. Both callers run
     * the store guard first, which is what keeps this answer honest; reversed,
     * it would tell a customer a store is billing every month that they have
     * nothing to cancel.
     *
     * The verb check sits beside the null check because the subscription model
     * is resolvable by the consuming application, so a model that does not carry
     * the verb would be a fatal on the billing screen rather than a refusal. It
     * reads as "there is nothing here to change", which is what a subject with
     * no such rail actually has.
     *
     * @param  string  $method  The Cashier verb the caller is about to invoke.
     */
    protected function actionableSubscription(Model $billable, string $method): Model
    {
        $subscription = $this->defaultSubscription($billable);

        if ($subscription === null || ! method_exists($subscription, $method)) {
            abort(HttpResponse::HTTP_NOT_FOUND, __('magic-starter::billing.refusals.no_subscription'));
        }

        return $subscription;
    }

    /**
     * Refuse a billing write with a machine-readable reason and the rail it
     * concerns.
     *
     * 409 rather than 403 or 422: the caller is authorized and the request is
     * well formed, the STATE of the resource is what conflicts with it. Thrown
     * as an {@see HttpResponseException} so the JSON body survives intact; an
     * `abort()` with a message would flatten it back to the prose-only shape
     * this exists to replace.
     *
     * Recorded as a `request_refused` row before the throw, so a customer who
     * was turned away is as visible in the history as one who was served. Only
     * these 409s are: a 422 is the caller's own mistake and a 404 an honest
     * absence, and neither is a billing outcome.
     *
     * @param  Model  $billable  The subject the request was refused for.
     * @param  string  $reason  One of the `REASON_*` constants on this class.
     * @param  BillingProvider  $provider  The rail the conflict concerns.
     * @param  string  $message  The localised sentence, rendered verbatim by the client.
     */
    protected function abortWithBillingConflict(
        Model $billable,
        string $reason,
        BillingProvider $provider,
        string $message,
    ): never {
        $this->recorder->record(
            BillingEventType::REQUEST_REFUSED,
            BillingSource::REQUEST,
            $billable,
            provider: $provider,
            reason: $reason,
        );

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'billing' => [
                'reason' => $reason,
                'provider' => $provider->value,
            ],
        ], HttpResponse::HTTP_CONFLICT));
    }

    /**
     * Record a swap, with whatever the rail's answer adds to the properties.
     *
     * @param  array<string, mixed>  $extra  Merged after the product and the price, e.g. `payment`.
     */
    protected function recordSwap(
        Request $request,
        Model $billable,
        Model $subscription,
        string $product,
        string $priceId,
        array $extra = [],
    ): void {
        $this->recordRequest(
            BillingEventType::SUBSCRIPTION_SWAPPED,
            $request,
            $billable,
            externalId: $this->stringAttribute($subscription, 'stripe_id'),
            properties: [
                'product' => $product,
                'price_id' => $priceId,
                ...$extra,
            ],
        );
    }

    /**
     * Record a successful card-rail write: the acting user is the actor and the
     * rail is Stripe, because every caller here has just spoken to it.
     *
     * @param  array<string, mixed>  $properties
     */
    protected function recordRequest(
        BillingEventType $type,
        Request $request,
        Model $billable,
        ?string $externalId = null,
        array $properties = [],
    ): void {
        $this->recorder->record(
            $type,
            BillingSource::REQUEST,
            $billable,
            provider: BillingProvider::STRIPE,
            externalId: $externalId,
            properties: $properties,
            actor: $request->user(),
        );
    }

    /**
     * The other subject this caller owns that a store is billing right now, or
     * null.
     *
     * The predicate is {@see SubscriptionGuardedDeleteTeam::storeIsBilling()}
     * and not a local copy, deliberately: one of them refuses a deletion and this
     * one hides a purchase button, and two definitions of "a store is billing
     * this subject" would be free to disagree with each other while both sides'
     * tests stayed green. It also already answers false for a model that is not
     * the configured billable subject, which is what makes the `'user'` arm
     * correct without a branch: a caller owns exactly one user row, their own,
     * and it is excluded below, so nothing is ever reported.
     *
     * Identity through Eloquent's `is()` rather than a key comparison, matching
     * {@see BillingPolicy}: it requires the key, the table AND the connection to
     * agree, so a team keyed 7 is not mistaken for the caller's own account 7.
     *
     * @param  Authenticatable  $user  The acting user.
     */
    protected function otherStoreFundedBillable(Authenticatable $user, Model $billable): ?Model
    {
        if (! method_exists($user, 'ownedTeams')) {
            return null;
        }

        $owned = $user->ownedTeams;

        // Typed `Model` and not a team class, because the relation is declared
        // over the configured team model: the narrowing belongs in the predicate,
        // which already answers false for anything that is not the billable
        // subject.
        return $owned->first(
            fn (Model $other): bool => ! $other->is($billable)
                && SubscriptionGuardedDeleteTeam::storeIsBilling($other),
        );
    }

    /**
     * The billable's default subscription, or null when it has none or carries
     * no Cashier trait; see {@see StripeBillingState::defaultSubscription()}.
     */
    protected function defaultSubscription(Model $billable): ?Model
    {
        return StripeBillingState::defaultSubscription($billable);
    }

    /**
     * When the subscription's current paid period ends, read live from the rail;
     * see {@see StripeBillingState::periodEnd()}. One of this endpoint's rail
     * retrievals, and the reason the endpoint is rail-live at all.
     */
    protected function periodEnd(Model $subscription): ?CarbonInterface
    {
        return StripeBillingState::periodEnd($subscription);
    }

    /**
     * The Stripe card behind the billable's default payment method, or null.
     *
     * Null covers three different absences on purpose, because the wire treats
     * them alike: no Cashier trait, no default payment method, and a legacy
     * Stripe Source, which is a payment method with no card object on it.
     *
     * The absent-trait case therefore reaches the wire as `available: true` with
     * null card fields, and that is the right answer rather than a loophole in
     * the flag. `false` means "ask again later"; an application that sells only
     * in the app stores has no card rail to ask ever, so reporting an outage
     * would send its client retrying something that cannot succeed, while "there
     * is no card on file" is simply true.
     */
    protected function defaultCard(Model $billable, ?Model $subscription = null): ?StripeObject
    {
        if (method_exists($billable, 'defaultPaymentMethod')) {
            $paymentMethod = $billable->defaultPaymentMethod();

            if ($paymentMethod instanceof PaymentMethod) {
                $card = $paymentMethod->asStripePaymentMethod()->card;

                if ($card instanceof StripeObject) {
                    return $card;
                }
            }
        }

        return $subscription === null ? null : $this->subscriptionCard($subscription);
    }

    /**
     * The card the SUBSCRIPTION itself carries, or null.
     *
     * The fallback exists because a hosted checkout does not leave a card where
     * Cashier looks for one. Stripe Checkout attaches the payment method to the
     * subscription it creates and leaves the customer's
     * `invoice_settings.default_payment_method` null, while
     * `Billable::defaultPaymentMethod()` reads the customer alone. So every
     * customer who bought through the hosted page was told there was no card on
     * file, moments after paying with one, and the card in question is the one
     * that renews them.
     *
     * It runs SECOND rather than first, and the order carries the correctness.
     * A portal update sets the customer's default, and Stripe does not
     * retroactively rewrite the subscription's, so consulting the subscription
     * first would show the card the customer had just replaced.
     *
     * This is a NEW round trip, not a reuse of one already made. The `expand`
     * is what stops it becoming two: an unexpanded `default_payment_method` is a
     * bare id, and resolving that into a card would cost a further retrieve.
     */
    protected function subscriptionCard(Model $subscription): ?StripeObject
    {
        if (! method_exists($subscription, 'asStripeSubscription')) {
            return null;
        }

        $paymentMethod = $subscription->asStripeSubscription(['default_payment_method'])
            ->default_payment_method;

        if (! $paymentMethod instanceof StripeObject) {
            return null;
        }

        $card = $paymentMethod->card ?? null;

        return $card instanceof StripeObject ? $card : null;
    }

    /**
     * Transform one Cashier invoice into its wire shape.
     *
     * Money is rendered server-side ({@see Invoice::total()} returns the
     * formatted, currency-aware string) so no client does amount math, and
     * `pdf_url` is the Stripe-hosted link a receipt action opens.
     *
     * @return array<string, mixed>
     */
    protected function invoiceWire(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'date' => $invoice->date()->toIso8601String(),
            'amount' => $invoice->total(),
            'status' => $invoice->status,
            'pdf_url' => $invoice->invoice_pdf,
        ];
    }
}
