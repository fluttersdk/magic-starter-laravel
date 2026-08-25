<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Actions\StoreSubscriptionGuardedDeleteTeam;
use FlutterSdk\MagicStarter\Contracts\ReportsUsage;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Http\Resources\SubscriptionResource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Policies\BillingPolicy;
use FlutterSdk\MagicStarter\Support\ReadsBillableAttributes;
use FlutterSdk\MagicStarter\Support\StripeSubscriptionState;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
 * - 422, the request named a tier the adopter does not sell, or named one they
 *   sell and have mapped no Stripe price to, or the adopter has published no
 *   tiers at all. Three faults in one channel because they share one remedy
 *   shape (fix the config, or ask for a tier that exists) and each carries its
 *   own sentence naming which of the three it is.
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
     * How many invoices one page of the invoice list carries.
     *
     * Untyped on purpose: the package's PHP floor is 8.2 and a typed class
     * constant is 8.3, so it would be a parse error on the oldest supported
     * runtime rather than a version warning.
     */
    public const INVOICES_PER_PAGE = 24;

    /**
     * Read the billable's current entitlement.
     */
    public function show(Request $request): SubscriptionResource
    {
        return SubscriptionResource::make($this->resolveBillable($request));
    }

    /**
     * Return the adopter's published tier catalogue, cheapest tier first.
     *
     * Served from config with no rail call and no per-subject state, so it is
     * safe on the hot path, and read through
     * {@see ReadsBillableAttributes::planCatalogue()} rather than straight from
     * `config()` so that the endpoint, the checkout validation and the paid-tier
     * floor all read exactly the same sanitised catalogue.
     *
     * Entries reach the client VERBATIM but for ONE key. The package names the
     * fields every billing screen needs (`id`, `name`, `tagline`, `monthly`,
     * `annual`, `currency`, `features`, `recommended`) and passes everything
     * else through untouched, which is where a tier's limits and any capability
     * copy live. The exception is `cycles`, which
     * {@see self::sellableCatalogue()} DERIVES from the price map and writes
     * over: it is a reserved key, said so in the config comment beside the
     * catalogue, and an adopter carrying their own would otherwise have it
     * silently replaced by a list computed from somewhere else. A
     * schema for those would mean this package owning product knowledge it does
     * not have, the same reason counting leaves through {@see ReportsUsage} and
     * the tier vocabulary is the consumer's throughout.
     *
     * An adopter who has published nothing gets an empty list rather than a 404.
     * The catalogue being empty is a legitimate state (a fresh install sells
     * nothing yet) and it is not the same fact as "this endpoint is not wired",
     * which is what `billing/usage` reports when nobody has bound its contract.
     */
    public function plans(): JsonResponse
    {
        return response()->json([
            'data' => $this->sellableCatalogue(),
        ]);
    }

    /**
     * The published catalogue, with each entry told which cycles it can be SOLD
     * on.
     *
     * The catalogue and the price map are two independent config keys, and
     * nothing on the wire related them. An adopter who fills in both display
     * figures for a tier but maps only its monthly price therefore ships an
     * annual button on every billing screen, and the customer learns that price
     * does not exist from a 422 AFTER committing to buy. That is the same
     * divergence between what a screen shows and what the rail will do that the
     * cycle itself was added to close, moved one step later, so it is closed on
     * the read the client already makes rather than at the point of sale.
     *
     * `cycles` is DERIVED, and it is the ONE key an entry does not carry to the
     * client verbatim: the write below is unconditional, so an adopter who put
     * their own `cycles` on a plan entry has it replaced by a list computed from
     * the price map. That is why it is declared reserved beside the catalogue in
     * `config/magic-starter.php` and in {@see self::plans()}; two values under
     * one key, one hand-written and one derived, is a disagreement with no
     * reader able to tell which they got. An entry with no mapped price at all
     * gets an empty list, which is the honest answer and the one a client needs
     * to hide a tier's purchase affordance rather than offer a refusal.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function sellableCatalogue(): array
    {
        $sellable = [];

        foreach (StripeSubscriptionState::catalogue() as $entry) {
            $sellable[$entry['tier']][$entry['cycle']] = true;
        }

        return array_map(
            static function (array $entry) use ($sellable): array {
                $id = is_string($entry['id'] ?? null) ? $entry['id'] : null;

                $entry['cycles'] = $id === null
                    ? []
                    : array_keys($sellable[$id] ?? []);

                return $entry;
            },
            $this->planCatalogue(),
        );
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
            Log::warning('Failed to read the billable payment method from Stripe.', [
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
                self::REASON_NO_BILLING_ACCOUNT,
                BillingProvider::fromWire($this->stringAttribute($billable, 'plan_provider')),
                __('magic-starter::billing.refusals.no_billing_account'),
            );
        }

        $returnUrl = $request->query('return_url');

        return response()->json([
            'portal_url' => $billable->billingPortalUrl(is_string($returnUrl) ? $returnUrl : null),
        ]);
    }

    /**
     * Begin a Stripe Checkout session for a tier the adopter publishes,
     * unwrapped to a JSON `{checkout_url, session_id}` shape.
     *
     * Cashier's `Checkout` object is never returned or redirected to directly.
     * It is `Responsable` and renders an HTML redirect, which is not an answer a
     * JSON client can follow, so only its two useful fields travel.
     *
     * WHAT MAY BE BOUGHT is the adopter's own published ranking and nothing
     * else. The application this was ported from validated against two literal
     * cases of a tier enum it owned; this package ships no tier vocabulary, so
     * naming one here would be inventing product knowledge it does not have. The
     * FLOOR tier is sellable like any other, because deciding that an adopter's
     * cheapest tier costs nothing is the same invention from the other end.
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

        // 3. Rail facts before input facts. A subject whose application never
        //    applied Cashier's trait has no card rail to check out on, and a
        //    direct call on such a model is a fatal `Error` on the billing screen
        //    rather than a refusal the client can render.
        //    The method it asks for is the one step 6 calls. Probing a different
        //    Cashier method would answer a question this step is not asking.
        if (! method_exists($billable, 'newSubscription')) {
            $this->abortWithBillingConflict(
                self::REASON_NO_BILLING_ACCOUNT,
                BillingProvider::fromWire($this->stringAttribute($billable, 'plan_provider')),
                __('magic-starter::billing.refusals.no_billing_account'),
            );
        }

        // 4. The tier must be one the adopter sells; the two URLs are where
        //    Stripe sends the customer back and are the client's to choose.
        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::in($this->sellableTiers())],
            // The cycle decides WHICH of the tier's prices is charged, so it is
            // required rather than defaulted. A default would be the whole
            // defect this parameter closes: a client showing an annual figure
            // and a producer choosing the monthly price is how a customer gets
            // billed an amount nothing on screen ever displayed.
            'cycle' => ['required', 'string', Rule::in(StripeSubscriptionState::CYCLES)],
            'success_url' => ['required', 'string', 'url'],
            'cancel_url' => ['required', 'string', 'url'],
        ]);

        // 5. A sellable tier with no price behind THIS CYCLE is a config gap and
        //    not a client fault, so it is refused with its own sentence rather
        //    than checked out against the tier's other price. An adopter who
        //    sells a tier one way only refuses the other way here, which is the
        //    honest answer: the alternative is charging a figure the customer
        //    was never shown.
        $priceId = $this->resolvePriceId($validated['plan'], $validated['cycle']);

        abort_if(
            $priceId === null,
            HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
            __('magic-starter::billing.refusals.unmapped_price', [
                // The cycle is the one dimension this sentence exists to name,
                // so it is named in the reader's language rather than in the
                // wire's. The wire word is unchanged and is what the lookup is
                // keyed by; only what a human is shown goes through the
                // catalogue.
                'cycle' => __('magic-starter::billing.cycles.' . $validated['cycle']),
            ]),
        );

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
        $checkout = $billable->newSubscription('default', $priceId)->checkout([
            'success_url' => $validated['success_url'],
            'cancel_url' => $validated['cancel_url'],
        ]);

        return response()->json([
            'checkout_url' => $checkout->url,
            'session_id' => $checkout->id,
        ]);
    }

    /**
     * Swap the billable's default subscription onto a different tier's price.
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

        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::in($this->sellableTiers())],
            // Required here as well as on checkout, and not because the two
            // endpoints should look alike: moving a customer from monthly to
            // annual on the SAME tier is a real change, and a swap that could
            // not express the cycle would answer 200 while leaving them on the
            // price they were trying to leave.
            'cycle' => ['required', 'string', Rule::in(StripeSubscriptionState::CYCLES)],
        ]);

        // 3. Reached only on a rail this application controls, so an absent
        //    subscription really does mean there is nothing to swap.
        $subscription = $this->actionableSubscription($billable, 'swap');

        // 4. Same config gap as checkout's, refused the same way.
        $priceId = $this->resolvePriceId($validated['plan'], $validated['cycle']);

        abort_if(
            $priceId === null,
            HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
            __('magic-starter::billing.refusals.unmapped_price', [
                // The cycle is the one dimension this sentence exists to name,
                // so it is named in the reader's language rather than in the
                // wire's. The wire word is unchanged and is what the lookup is
                // keyed by; only what a human is shown goes through the
                // catalogue.
                'cycle' => __('magic-starter::billing.cycles.' . $validated['cycle']),
            ]),
        );

        $subscription->swap($priceId);

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
        $user = $request->user();
        $billableClass = MagicStarter::billableModel();

        if ($user instanceof $billableClass) {
            return $user;
        }

        // Reached only under the team subject. An application whose user model
        // has no team trait has no `currentTeam` relation either, so the read
        // lands on a null attribute and answers 404 here rather than reaching
        // the membership call below.
        $billable = $user->currentTeam;

        abort_if(! $billable instanceof Model, HttpResponse::HTTP_NOT_FOUND);
        abort_if(! $user->belongsToTeam($billable), HttpResponse::HTTP_NOT_FOUND);

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
            self::REASON_MANAGED_BY_STORE,
            $provider,
            __('magic-starter::billing.refusals.managed_by_store'),
        );
    }

    /**
     * The tier ids a checkout or a swap may name, refusing when the adopter has
     * published none.
     *
     * Read through {@see ReadsBillableAttributes::tierOrder()} and never
     * straight from `magic-starter.billing.tier_order`, which is the key the
     * plan for this port named. The difference is not cosmetic: that reader
     * falls back to the CATALOGUE's entry ids when no explicit ranking is
     * published, because a catalogue carries the same cheapest-first convention
     * and publishing one is already a declaration of order. A reader that went
     * to the raw key would refuse every tier such an adopter sells while their
     * own billing screen rendered all of them, and the fault would look like the
     * client's.
     *
     * The empty case is the whole reason this method exists rather than a bare
     * `Rule::in()` at each call site. `Rule::in([])` refuses too, but with the
     * validator's generic sentence, which sends an adopter reading their
     * client's request body for a fault that is in their config. So the refusal
     * NAMES the situation and both keys that resolve it. Naming only one would
     * be wrong half the time now that either list answers.
     *
     * A tier is never named here. Which tiers exist and which of them cost money
     * is the adopter's knowledge, so an empty list refuses everything rather
     * than falling back to a default this package would have had to invent.
     *
     * @return list<string>
     *
     * @throws ValidationException When the adopter has published no tiers at all.
     */
    protected function sellableTiers(): array
    {
        $tiers = $this->tierOrder();

        if ($tiers === []) {
            throw ValidationException::withMessages([
                'plan' => [__('magic-starter::billing.refusals.no_published_catalogue')],
            ]);
        }

        return $tiers;
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
     * The Stripe price id that sells [$tier], or null when none does.
     *
     * The reverse direction of {@see StripeSubscriptionState::planForPrice()},
     * and it goes through that class's own reader rather than through `config()`
     * a second time. The application this was ported from kept the map under
     * `cashier.plans` and read it from two places: a webhook asking which tier a
     * price sells, and this one asking which price sells a tier. Two readers of
     * one key each decide for themselves what an unusable entry means, and only
     * one of them has to decide it wrong for an empty price id to become the
     * price of a paid tier. `prices()` strips those once, for both directions.
     *
     * The cast is not decorative. PHP stores a numeric-looking array key as an
     * INT, so a price id that happens to look like a number comes back from the
     * reverse lookup as one.
     */
    protected function resolvePriceId(string $tier, string $cycle): ?string
    {
        return StripeSubscriptionState::priceFor($tier, $cycle);
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
     * @param  string  $reason  One of the `REASON_*` constants on this class.
     * @param  BillingProvider  $provider  The rail the conflict concerns.
     * @param  string  $message  The localised sentence, rendered verbatim by the client.
     */
    protected function abortWithBillingConflict(string $reason, BillingProvider $provider, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'billing' => [
                'reason' => $reason,
                'provider' => $provider->value,
            ],
        ], HttpResponse::HTTP_CONFLICT));
    }

    /**
     * The other subject this caller owns that a store is billing right now, or
     * null.
     *
     * The predicate is {@see StoreSubscriptionGuardedDeleteTeam::storeIsBilling()}
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
                && StoreSubscriptionGuardedDeleteTeam::storeIsBilling($other),
        );
    }

    /**
     * The billable's default subscription, or null when it has none or carries
     * no Cashier trait.
     */
    protected function defaultSubscription(Model $billable): ?Model
    {
        if (! method_exists($billable, 'subscription')) {
            return null;
        }

        $subscription = $billable->subscription('default');

        return $subscription instanceof Model ? $subscription : null;
    }

    /**
     * When the subscription's current paid period ends, read live from the rail.
     *
     * One of this endpoint's rail retrievals, and the reason the endpoint is
     * rail-live at all: Cashier resolves the period per subscription ITEM, so
     * there is no local column to read it from. It is guarded because the
     * subscription model is resolvable by the consuming application and a call
     * on a model that does not carry the accessor would be a fatal rather than a
     * missing field.
     */
    protected function periodEnd(Model $subscription): ?CarbonInterface
    {
        if (! method_exists($subscription, 'currentPeriodEnd')) {
            return null;
        }

        $end = $subscription->currentPeriodEnd();

        return $end instanceof CarbonInterface ? $end : null;
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
