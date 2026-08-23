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
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\PaymentMethod;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeObject;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The billing subject's own endpoints: the entitlement read, the catalogue, the
 * usage report, the invoice page, the card, the store-conflict check and the
 * portal session.
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
     * The catalogue this package HAS is `magic-starter.billing.tier_order`, an
     * ordered list of the adopter's own tier ids, and that is all this endpoint
     * can honestly serve. The package ships no tier vocabulary at all, so it has
     * no name, no price and no limit for any tier: those live in the consuming
     * application, which is also the only place that knows what a tier caps.
     *
     * Served from config with no rail call and no per-subject state, so it is
     * safe on the hot path, and read through
     * {@see ReadsBillableAttributes::tierOrder()} rather than straight from
     * `config()` so that the endpoint, the checkout validation and the paid-tier
     * floor all rank against exactly the same sanitised list.
     */
    public function plans(): JsonResponse
    {
        return response()->json([
            'data' => $this->tierOrder(),
        ]);
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
            //    no trial, which is the one retrieval on this path.
            $subscription = $this->defaultSubscription($billable);
            $renewalDate = $subscription === null
                ? null
                : $this->dateAttribute($subscription, 'trial_ends_at') ?? $this->periodEnd($subscription);

            // 2. Only a Cashier PaymentMethod exposes a card; a null default or
            //    a legacy Stripe Source yields null card fields, and so does a
            //    billable whose application never applied Cashier's trait.
            $card = $this->defaultCard($billable);

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
     * The one retrieval on this endpoint, and the reason this endpoint is the
     * only rail-live one: Cashier resolves the period per subscription ITEM, so
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
    protected function defaultCard(Model $billable): ?StripeObject
    {
        if (! method_exists($billable, 'defaultPaymentMethod')) {
            return null;
        }

        $paymentMethod = $billable->defaultPaymentMethod();

        if (! $paymentMethod instanceof PaymentMethod) {
            return null;
        }

        $card = $paymentMethod->asStripePaymentMethod()->card;

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
