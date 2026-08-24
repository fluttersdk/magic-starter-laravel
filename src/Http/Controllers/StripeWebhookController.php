<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use FlutterSdk\MagicStarter\Contracts\WritesEntitlement;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Models\ProcessedWebhookEvent;
use FlutterSdk\MagicStarter\Support\EntitlementWrite;
use FlutterSdk\MagicStarter\Support\ReadsBillableAttributes;
use FlutterSdk\MagicStarter\Support\StripeSubscriptionState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Stripe webhook feeder for the billable's entitlement columns.
 *
 * Cashier keeps the local `subscriptions` table in sync with Stripe; this
 * subclass layers two guarantees on top of that:
 *
 *  1. Idempotency: the dedup record ({@see ProcessedWebhookEvent::recordIfNew()})
 *     and the event's side effect run inside one transaction, so a re-delivered
 *     event (queue retry, Stripe re-send) is a total no-op while a mid-handler
 *     failure rolls the dedup row back and lets Stripe's retry reprocess it.
 *  2. Entitlement projection: subscription and paid-invoice events claim the
 *     authoritative entitlement columns through {@see WritesEntitlement}, making
 *     Stripe ONE feeder of a column several rails may feed rather than the truth
 *     itself.
 *
 * The price to tier map lives under `magic-starter.billing.prices` and is read
 * through {@see StripeSubscriptionState}, never here.
 *
 * ## The subject is whatever the application bills
 *
 * Nothing in this controller names a team or a user. The billable is resolved
 * through Cashier's own `findBillable()`, which reads the model
 * `Cashier::useCustomerModel()` was pointed at; the package's provider points it
 * at {@see \FlutterSdk\MagicStarter\MagicStarter::billableModel()} during
 * `register()`. Without that wiring Cashier searches `App\Models\User` on a
 * team-billing application and matches nobody, which is a Stripe rail that
 * silently finds no customer.
 *
 * ## The route is Cashier's path, not a package-owned one
 *
 * `src/routes/webhooks.php` registers this controller under
 * `config('cashier.path')`, so the served path is `stripe/webhook` and it does
 * NOT move with `magic-starter.route_prefix`. A webhook URL lives in a vendor
 * dashboard and cannot move with a deploy; that file carries the reasoning.
 *
 * ## Signature verification is inherited, never routed
 *
 * {@see CashierWebhookController::__construct()} attaches
 * {@see \Laravel\Cashier\Http\Middleware\VerifyWebhookSignature} whenever
 * `cashier.webhook.secret` is set, so the constructor below MUST call
 * `parent::__construct()`; skipping it would unsign this route while every
 * happy-path test stayed green. The secret stays a stock Cashier key rather
 * than moving to a package one for the same reason: Cashier's own constructor
 * is what reads it, so a package key would silently disarm the middleware.
 *
 * ## What this feeder decides, and what it does not
 *
 * Which tier a price grants, and whether a status grants at all, are decided
 * here: they are questions about Stripe's vocabulary and only a Stripe feeder
 * can answer them. WHETHER the claim lands is not, because that answer depends
 * on what another rail has already granted. So every write goes through the
 * contract as an {@see EntitlementWrite} carrying its provenance, and the
 * implementation's ordering rules decide.
 *
 * Stripe's status word is mapped onto the neutral {@see PlanStatus} vocabulary
 * and the raw word travels alongside it in `plan_provider_status`, which gates
 * nothing. An unknown word therefore cannot entitle by accident and cannot be
 * lost either.
 *
 * ## The projection never calls the Stripe API
 *
 * Every field the entitlement projection writes comes out of the payload it was
 * handed. That is a hard constraint rather than a preference: Cashier's
 * `Subscription::currentPeriodEnd()` and `billingPortalUrl()` are both live
 * Stripe round-trips (the first one PER subscription item, via
 * `SubscriptionItem::asStripeSubscriptionItem()`), and this runs inside a
 * `DB::transaction` that Stripe expects an answer from quickly. Reading either
 * one here would put N synchronous network calls inside that transaction and
 * let a Stripe outage break the GRANTING path for the sake of a provenance
 * column.
 *
 * The claim is scoped to the projection on purpose, because it is NOT true of
 * the request as a whole: Cashier's own `handleCustomerSubscriptionUpdated()`
 * answers a truthy `cancel_at_period_end` with `$subscription->currentPeriodEnd()`,
 * so a cancellation event already dials Stripe from inside this transaction,
 * upstream of anything here. Do not read the heading as an invariant for the
 * whole route, and do not build a timeout or transaction-boundary assumption on
 * top of it.
 */
class StripeWebhookController extends CashierWebhookController
{
    /**
     * The provenance columns are decoded through the shared readers rather than
     * read straight off the model. The package ships the columns and not the
     * model that casts them, so an adopter may leave `plan_current_period_end` a
     * raw string and `plan_renews` an integer; a `?CarbonInterface` parameter is
     * then a TypeError on a payment path, and `1 !== true` disagrees with the
     * column forever.
     */
    use ReadsBillableAttributes;

    /**
     * @param  WritesEntitlement  $entitlements  the only code path allowed to
     *                                           write the entitlement columns
     */
    public function __construct(protected WritesEntitlement $entitlements)
    {
        // Cashier's own constructor attaches VerifyWebhookSignature when a
        // webhook secret is configured; skipping it would unsign this route.
        parent::__construct();
    }

    /**
     * Handle customer subscription created.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        return $this->processOnce($payload, function (array $payload): Response {
            $response = parent::handleCustomerSubscriptionCreated($payload);
            $this->syncEntitlementFromSubscription($payload['data']['object'], $this->eventAt($payload));

            return $response;
        });
    }

    /**
     * Handle customer subscription updated.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        return $this->processOnce($payload, function (array $payload): Response {
            // Parent returns null on the incomplete_expired branch (it deletes
            // the subscription); the projection still revokes the tier there.
            $response = parent::handleCustomerSubscriptionUpdated($payload);
            $this->syncEntitlementFromSubscription($payload['data']['object'], $this->eventAt($payload));

            return $response ?? $this->successMethod();
        });
    }

    /**
     * Handle the cancellation of a customer subscription.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        return $this->processOnce($payload, function (array $payload): Response {
            $response = parent::handleCustomerSubscriptionDeleted($payload);
            $this->revokeEntitlement($payload['data']['object'], $this->eventAt($payload));

            return $response;
        });
    }

    /**
     * Handle invoice payment succeeded.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleInvoicePaymentSucceeded(array $payload): Response
    {
        return $this->processOnce($payload, function (array $payload): Response {
            $response = parent::handleInvoicePaymentSucceeded($payload);
            $this->reaffirmEntitlementFromInvoice($payload['data']['object'], $this->eventAt($payload));

            return $response;
        });
    }

    /**
     * Insert-then-handle guard around a single event's side effects.
     *
     * The two transactions here are two separate properties and neither may be
     * collapsed into the other or reordered. A reader who sees `DB::transaction`
     * inside `DB::transaction` and flattens it, or who commits the claim before
     * running the handler, breaks both, and breaking the outer one leaves a
     * cancelled customer on a paid tier permanently, for free, with every
     * happy-path test still green.
     *
     * @param  array<string, mixed>  $payload
     * @param  Closure(array<string, mixed>): Response  $handler
     */
    protected function processOnce(array $payload, Closure $handler): Response
    {
        // The dedup insert and the handler share one transaction: a mid-handler
        // failure rolls the dedup row back with the side effect, so Stripe's
        // retry reprocesses the event instead of hitting a permanent no-op that
        // would leave a canceled billable on its paid tier for free. The unique
        // `event_id` index still serializes concurrent deliveries: a losing
        // racer blocks on the winner's row lock and only sees the violation
        // once the winner commits. The INNER transaction lives inside
        // recordIfNew() and scopes that violation to a SAVEPOINT.
        return DB::transaction(function () use ($payload, $handler): Response {
            // 1. Claim the event id first; a losing re-delivery skips every side
            //    effect (parent sync AND entitlement projection) and returns 200.
            if (! ProcessedWebhookEvent::recordIfNew($payload['id'], $payload['type'])) {
                return $this->successMethod();
            }

            // 2. First delivery: run Cashier's sync, then project the entitlement.
            return $handler($payload);
        });
    }

    /**
     * Project a subscription object onto the billable's entitlement columns.
     *
     * @param  array<string, mixed>  $object
     */
    protected function syncEntitlementFromSubscription(array $object, CarbonInterface $eventAt): void
    {
        $billable = $this->resolveBillable($object['customer'] ?? null);

        if ($billable === null) {
            return;
        }

        $status = $object['status'] ?? 'incomplete';
        $priceId = $object['items']['data'][0]['price']['id'] ?? null;

        // 1. A genuinely non-granting status (canceled/unpaid/incomplete) really
        //    does revoke the entitlement, and the claim carries NO tier: Stripe
        //    is saying this customer is owed nothing, which is not the same
        //    statement as selling them the cheapest tier. The check stays on
        //    Stripe's own word rather than on the mapped status: it is the list
        //    that has always decided this, and re-deriving it from the neutral
        //    vocabulary would be a second definition of "granting" for the same
        //    outcome, free to disagree with the first one.
        if (! StripeSubscriptionState::grants($status)) {
            $this->claim($this->subscriptionClaim($billable, null, $status, $object, $eventAt));

            return;
        }

        // 2. A granting status whose price is unmapped is a config gap, not a
        //    downgrade: skip the write so the config gap never revokes a paid
        //    tier, and surface the missing price to tier mapping for operators.
        $plan = StripeSubscriptionState::planForPrice($priceId);

        if ($plan === null) {
            $this->warnUnmappedPrice($priceId, $billable);

            return;
        }

        $this->claim($this->subscriptionClaim($billable, $plan, $status, $object, $eventAt));
    }

    /**
     * A paid subscription invoice re-affirms the active entitlement tier read
     * from the billable's synced Cashier subscription price.
     *
     * @param  array<string, mixed>  $object
     */
    protected function reaffirmEntitlementFromInvoice(array $object, CarbonInterface $eventAt): void
    {
        $billable = $this->resolveBillable($object['customer'] ?? null);

        if ($billable === null) {
            return;
        }

        // The billable model belongs to the consuming application, so Cashier's
        // trait is a call this package checks for rather than assumes, exactly
        // as the billing read endpoints do. Cashier's own parent handlers make
        // no such check, so a trait-less model still fatals on the subscription
        // events; what this guard buys is that the one Cashier call THIS class
        // makes is not the one that does it.
        if (! method_exists($billable, 'subscription')) {
            return;
        }

        $subscription = $billable->subscription('default');

        if (! $subscription instanceof Model) {
            return;
        }

        // Read through the shared decoder rather than off the attribute: this
        // row is the consuming application's model too, since a consumer may
        // point Cashier::useSubscriptionModel() at one of their own.
        $priceId = $this->stringAttribute($subscription, 'stripe_price');

        // A paid invoice must never downgrade the payer: an unmapped price is a
        // config gap, so leave the entitlement untouched and warn instead.
        $plan = StripeSubscriptionState::planForPrice($priceId);

        if ($plan === null) {
            $this->warnUnmappedPrice($priceId, $billable);

            return;
        }

        // Carrying the stored period forward is only meaningful while Stripe is
        // the rail on record. A web-to-store migration leaves a billable holding
        // a store grant AND an old Cashier row at once, and a cross-rail claim
        // from here would then re-stamp the STORE's period under `stripe`.
        $stripeIsOnRecord = BillingProvider::fromWire($this->stringAttribute($billable, 'plan_provider'))
            === BillingProvider::STRIPE;

        $this->claim(new EntitlementWrite(
            billable: $billable,
            plan: $plan,
            // A paid invoice says exactly one thing about the lifecycle: the
            // money arrived. Stripe reports the status itself on a subscription
            // event, which is where it is read from.
            status: PlanStatus::ACTIVE,
            provider: BillingProvider::STRIPE,
            eventAt: $eventAt,
            // A PROJECTION, and the only one this controller makes: the tier
            // above comes from the local Cashier row's `stripe_price`, which
            // Cashier may not have resynced for the change this very invoice
            // paid for. It may refresh the record; it may not decide that Stripe
            // is the rail billing this subject.
            authoritative: false,
            providerStatus: 'active',
            productId: $priceId,
            // An invoice object carries no subscription items, so this path has
            // no period of its own to read and the local Cashier row has no
            // period column to fall back on. On the Stripe rail the stored
            // values are carried forward, which is what leaves the two columns
            // as they were: the write path sets all ten columns on every apply,
            // so passing nothing would blank a period a subscription event had
            // already established. Off it, null is the honest answer, because
            // this feeder does not know Stripe's period and reading Cashier's
            // accessor for it would be a live Stripe call (see the class
            // docblock).
            currentPeriodEnd: $stripeIsOnRecord
                ? $this->dateAttribute($billable, 'plan_current_period_end')
                : null,
            renews: $stripeIsOnRecord
                ? $this->booleanAttribute($billable, 'plan_renews')
                : null,
        ));
    }

    /**
     * A deleted subscription revokes the entitlement: nothing is owed any more.
     *
     * @param  array<string, mixed>  $object
     */
    protected function revokeEntitlement(array $object, CarbonInterface $eventAt): void
    {
        $billable = $this->resolveBillable($object['customer'] ?? null);

        if ($billable === null) {
            return;
        }

        // A deletion says THIS subscription stopped billing, not that the
        // customer stopped paying. A subject can hold more than one Stripe
        // subscription (a checkout opened beside an existing one, a migration,
        // a portal-side change), and revoking on the older one's deletion takes
        // the tier away from somebody the newer one is still charging.
        //
        // Measured, not hypothesised: a cancelled monthly subscription plus a
        // fresh annual one, the monthly deleted at its period end, and the
        // entitlement went to `plan=free, subscribed=false` while Stripe was
        // charging $348/year. The hourly reconciler healed it, so the damage is
        // an hour of a paying customer refused by every cap gate rather than a
        // permanent loss, which is exactly why nothing ever noticed.
        //
        // Cashier's own deleted handler has already run by the time this does
        // (see the caller) and has marked the deleted row canceled, so a
        // surviving granting row can only be a DIFFERENT subscription.
        if ($this->stillGrantedByAnotherSubscription($billable)) {
            Log::warning('Skipped a Stripe revocation: another subscription still grants.', [
                'billable_id' => $billable->getKey(),
                'deleted_subscription' => $object['id'] ?? null,
            ]);

            return;
        }

        $this->claim(new EntitlementWrite(
            billable: $billable,
            // No tier, rather than the cheapest one. A deleted subscription is
            // Stripe saying it is billing nothing, and a claim naming the floor
            // tier would be indistinguishable from selling that tier.
            plan: null,
            status: PlanStatus::CANCELED,
            provider: BillingProvider::STRIPE,
            eventAt: $eventAt,
            // Stripe telling us directly, on its own event.
            authoritative: true,
            // Stripe does not put a status on the deletion itself; the deletion
            // IS the status, and `canceled` is the word Stripe uses for it.
            providerStatus: 'canceled',
            productId: $object['items']['data'][0]['price']['id'] ?? null,
            // Deliberately not carried forward: a deleted subscription has no
            // period left to run and nothing left to renew, so both fields are
            // now unknown rather than merely unread.
        ));
    }

    /**
     * Whether the billable holds another Stripe subscription that still grants.
     *
     * Read from the LOCAL rows rather than the rail: Cashier's own webhook
     * handlers keep them in step, this runs inside a webhook transaction, and a
     * network read here would put a Stripe round trip inside every deletion.
     *
     * `StripeSubscriptionState::grants()` is the same predicate every other
     * feeder uses, so a status added to that list is honoured here without a
     * second edit. A subject with no local rows answers false and revokes
     * normally, which is the ordinary single-subscription case.
     */
    protected function stillGrantedByAnotherSubscription(Model $billable): bool
    {
        if (! method_exists($billable, 'subscriptions')) {
            return false;
        }

        foreach ($billable->subscriptions as $subscription) {
            $status = $subscription->stripe_status;

            if (is_string($status) && StripeSubscriptionState::grants($status)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The claim a subscription object makes about a billable's entitlement.
     *
     * `$plan` is a parameter rather than derived here because the two callers
     * reach it differently: a non-granting status owes NOTHING whatever the price
     * says, which is what null means, and a granting one takes the tier its price
     * maps to. `$status` is passed in for a stricter reason: the caller has
     * already defaulted an absent one, and defaulting it a second time here would
     * be two definitions of the same word, free to disagree.
     *
     * @param  array<string, mixed>  $object
     */
    protected function subscriptionClaim(
        Model $billable,
        ?string $plan,
        string $status,
        array $object,
        CarbonInterface $eventAt,
    ): EntitlementWrite {
        return new EntitlementWrite(
            billable: $billable,
            plan: $plan,
            status: StripeSubscriptionState::planStatusFor($status),
            provider: BillingProvider::STRIPE,
            eventAt: $eventAt,
            // Every field here is read out of the event payload Stripe signed,
            // so this is Stripe speaking rather than us remembering.
            authoritative: true,
            providerStatus: $status,
            productId: $object['items']['data'][0]['price']['id'] ?? null,
            currentPeriodEnd: $this->periodEndFromPayload($object),
            renews: $this->renewsFromPayload($object),
            // Two columns the Stripe rail has no source for. Cashier's
            // `onGracePeriod()` means "cancelled but still entitled", which is
            // already said by `renews = false` plus a future period end, and
            // Stripe's real dunning window is a Stripe Billing setting that
            // appears nowhere on a webhook payload. `plan_manage_url` holds
            // only durable values; the Stripe rail mints a short-lived portal
            // session per request through the billing portal endpoint instead.
        );
    }

    /**
     * Hand one claim to the single entitlement write path.
     */
    protected function claim(EntitlementWrite $write): void
    {
        $this->entitlements->write($write);
    }

    /**
     * The event's OWN timestamp, which is what orders one delivery against
     * another.
     *
     * Never `now()`. Receipt time only ever increases, so a feeder stamping it
     * would read every write as the freshest truth on record and disarm the
     * monotonic rule while satisfying every type check, which is the failure
     * {@see EntitlementWrite::$eventAt} warns about. Stripe puts `created` on
     * every Event object, so the fallback is unreachable in practice; it is the
     * epoch rather than `now()` because a degenerate timestamp has to LOSE to a
     * real one, not beat it.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function eventAt(array $payload): CarbonInterface
    {
        return CarbonImmutable::createFromTimestamp((int) ($payload['created'] ?? 0));
    }

    /**
     * When the paid period ends, read out of the payload the handler already
     * holds.
     *
     * The current Stripe API version carries this on the subscription ITEM,
     * which is the same array the price id is read from a few lines up and the
     * place Cashier reads it from too; older versions carry it at the
     * subscription level, hence the fallback. Absent on both, the period is
     * unknown and stays NULL.
     *
     * Named for its SOURCE rather than for the field, so that it cannot be
     * mistaken for Cashier's `Subscription::currentPeriodEnd()`. That one is a
     * live Stripe retrieve per subscription item and is exactly what must never
     * be called from this handler.
     *
     * @param  array<string, mixed>  $object
     */
    protected function periodEndFromPayload(array $object): ?CarbonInterface
    {
        $timestamp = $object['items']['data'][0]['current_period_end']
            ?? $object['current_period_end']
            ?? null;

        return $timestamp === null ? null : CarbonImmutable::createFromTimestamp((int) $timestamp);
    }

    /**
     * Whether the subscription rolls over, which is Stripe's
     * `cancel_at_period_end` inverted.
     *
     * An ABSENT key leaves the column NULL rather than assuming it renews: null
     * means the rail has not said, which is a different claim from false, and
     * defaulting either way would invent one.
     *
     * @param  array<string, mixed>  $object
     */
    protected function renewsFromPayload(array $object): ?bool
    {
        if (! array_key_exists('cancel_at_period_end', $object)) {
            return null;
        }

        return ! (bool) $object['cancel_at_period_end'];
    }

    /**
     * Resolve the billable acting as the Stripe customer.
     *
     * The parameter is `mixed` because the payload is: Stripe writes `customer`
     * as a plain id on every event this controller handles, and as an EXPANDED
     * customer object when an integration asks for one. Narrowing here is what
     * keeps the three call sites from each deciding what a non-string means.
     *
     * The empty check is explicit rather than `! $customerId`, matching
     * {@see StripeSubscriptionState::planForPrice()}: the two forms differ on
     * the string `'0'`, and one rule written two ways is how a pair of readers
     * starts disagreeing.
     */
    protected function resolveBillable(mixed $customerId): ?Model
    {
        if (! is_string($customerId) || $customerId === '') {
            return null;
        }

        $billable = $this->getUserByStripeId($customerId);

        return $billable instanceof Model ? $billable : null;
    }

    /**
     * Surface a granting subscription whose price id has no tier mapping, so a
     * production config gap is observable instead of silently downgrading a
     * paying customer.
     */
    protected function warnUnmappedPrice(?string $priceId, Model $billable): void
    {
        Log::warning('Stripe price id is not mapped to a plan; entitlement left untouched.', [
            'price_id' => $priceId,
            'billable_id' => $billable->getKey(),
        ]);
    }
}
