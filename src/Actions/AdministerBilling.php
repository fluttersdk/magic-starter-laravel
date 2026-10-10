<?php

namespace FlutterSdk\MagicStarter\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use FlutterSdk\MagicStarter\Console\ExpireBillingGrantsCommand;
use FlutterSdk\MagicStarter\Console\ReconcileBillingEntitlements;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Contracts\WritesEntitlement;
use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\Enums\GrantEndReason;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Jobs\SyncRevenueCatEntitlement;
use FlutterSdk\MagicStarter\Models\BillingGrant;
use FlutterSdk\MagicStarter\Support\BillingAdministrationRefused;
use FlutterSdk\MagicStarter\Support\BillingCatalogue;
use FlutterSdk\MagicStarter\Support\BillingEventRecorder;
use FlutterSdk\MagicStarter\Support\BillingLog;
use FlutterSdk\MagicStarter\Support\EntitlementWrite;
use FlutterSdk\MagicStarter\Support\ReadsBillableAttributes;
use FlutterSdk\MagicStarter\Support\RevenueCatClient;
use FlutterSdk\MagicStarter\Support\StoreRailConfiguration;
use FlutterSdk\MagicStarter\Support\StripeBillingState;
use FlutterSdk\MagicStarter\Support\StripeSubscriptionState;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription as CashierSubscription;
use LogicException;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Refund;
use Stripe\Subscription as StripeSubscription;

/**
 * The package's operator side of billing: a manual grant, its revoke and its
 * expiry, the Stripe operations on a billable's subscription, and a sync that
 * re-reads a rail now.
 *
 * ## A grant is a projection that yields to anybody paying
 *
 * A grant is refused while a paid rail grants (see {@see self::paidRail()}) and
 * is written `authoritative: false`, so {@see WriteEntitlement}'s rule 2b keeps
 * it from taking the record over from a rail that is still billing. The check
 * runs twice: once up front for a cheap refusal, and again on a
 * `lockForUpdate()` re-read inside the transaction that writes, because a
 * checkout can land between the two.
 *
 * ## A grant only ends what it still holds
 *
 * The grant's id travels in `plan_product_id` (`grant:{id}`). A revoke or an
 * expiry writes only while the record is MANUAL and names THAT grant; a record
 * a paid rail or a newer grant has since taken over is left alone and the old
 * grant is closed as superseded. After a grant ends, the paid rails are
 * re-projected: a checkout made during the comp was dropped while the comp held
 * the record, and the reconciler never walks a manual record to find it.
 *
 * ## Every operator write is strictly newer than the record
 *
 * Rule 1 drops a same-rail write that is older than the record and rule 1b a
 * same-second one that takes access away, so a revoke in the grant's own second
 * would be dropped. Writes on the rail already on record are therefore stamped
 * one second past the stored stamp when `now()` is not later; see
 * {@see self::eventAtAfterRecord()}.
 *
 * ## A Stripe operation acts on the local subscription, never on the record
 *
 * Trial, cancel, resume and refund each need the billable's local `default`
 * Cashier subscription and refuse `no_subscription` without it, whatever
 * `plan_provider` says (see {@see self::actionableSubscription()}). They change
 * Stripe and Cashier's row and leave the entitlement to the webhook that
 * follows, as the customer's own billing endpoints do. Every way the rail
 * fails becomes a recorded `rail_error` ({@see self::onStripe()}).
 *
 * ## A refusal is recorded outside the transaction that refused
 *
 * Every refusal leaves a `request_refused` row (source `admin`) and is then
 * thrown as {@see BillingAdministrationRefused}. A refusal raised inside a
 * transaction is recorded only after that transaction rolled back, or the row
 * would roll back with it; the panel then halts without rolling back its own
 * transaction for the same reason.
 */
class AdministerBilling implements AdministersBilling
{
    use ReadsBillableAttributes;

    /**
     * The rails whose granting record proves somebody is paying. MANUAL is an
     * operator's word and NONE is nobody, so neither is proof.
     *
     * @var list<BillingProvider>
     */
    protected const PAID_RAILS = [
        BillingProvider::STRIPE,
        BillingProvider::APP_STORE,
        BillingProvider::PLAY_STORE,
    ];

    /**
     * The rail's word {@see SyncRevenueCatEntitlement} leaves in
     * `plan_provider_status` when an operator's act, not a delivery, asked for
     * the store re-read.
     */
    protected const STORE_REREAD = 'ADMIN_REPROJECTION';

    /**
     * The refund reasons an operator may give: Stripe's own, less `fraudulent`,
     * which also feeds Stripe's fraud signals and is not a support decision.
     *
     * @var list<string>
     */
    protected const REFUND_REASONS = [
        'requested_by_customer',
        'duplicate',
    ];

    /**
     * @param  WritesEntitlement  $entitlements  the only code path that writes the entitlement columns
     * @param  BillingEventRecorder  $recorder  leaves every `billing_events` row this action owes
     * @param  RevenueCatClient  $revenueCat  the store rail's authoritative read
     * @param  ReconcileBillingEntitlements  $reconciler  the Stripe rail's local projection, re-run after a grant
     */
    public function __construct(
        protected WritesEntitlement $entitlements,
        protected BillingEventRecorder $recorder,
        protected RevenueCatClient $revenueCat,
        protected ReconcileBillingEntitlements $reconciler,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function grant(
        Authenticatable $actor,
        Model $billable,
        string $plan,
        string $reason,
        ?CarbonInterface $expiresAt,
    ): BillingGrant {
        // 1. The refusals that need no lock, recorded before anything is opened.
        $paidRail = $this->paidRail($this->currentCopy($billable));

        if ($paidRail !== null) {
            $this->refuse(new BillingAdministrationRefused('paid_rail_active', $paidRail), $actor, $billable, 'grant');
        }

        if (! $this->isGrantablePlan($plan)) {
            $this->refuse(new BillingAdministrationRefused('unknown_plan'), $actor, $billable, 'grant');
        }

        if ($expiresAt !== null && ! $expiresAt->isFuture()) {
            $this->refuse(new BillingAdministrationRefused('expiry_in_past'), $actor, $billable, 'grant');
        }

        // 2. Everything else under the billable's row lock, in one transaction.
        return $this->transaction(
            $actor,
            $billable,
            'grant',
            fn (): BillingGrant => $this->applyGrant($actor, $billable, $plan, $reason, $expiresAt),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function revoke(Authenticatable $actor, Model $billable, string $reason): void
    {
        $current = $this->currentCopy($billable);
        $provider = BillingProvider::fromWire($this->stringAttribute($current, 'plan_provider'));

        // 1. The billable is on an open manual grant: end it, then let the paid
        //    rails speak again outside the transaction.
        if ($this->grantOnRecord($current) !== null) {
            $revoked = $this->transaction(
                $actor,
                $billable,
                'revoke',
                fn (): Model => $this->applyRevoke($actor, $billable, $reason),
            );

            $this->reprojectPaidRails($revoked);

            return;
        }

        // 2. A store record no production subscription stands behind, which
        //    the store job will never revoke on its own.
        if ($provider->isStore()) {
            $this->revokeSandboxOnlyStoreRecord($actor, $billable, $provider, $reason);

            return;
        }

        // 3. A paid rail's record is cancelled through that rail, not here.
        $this->refuse(new BillingAdministrationRefused('not_manual', $provider), $actor, $billable, 'revoke');
    }

    /**
     * {@inheritDoc}
     *
     * A cancelled trial is refused: its `ends_at` holds the end it was
     * cancelled at, which a moved trial end would leave stale.
     *
     * @throws BillingAdministrationRefused `no_subscription`, `not_trialing`, `already_cancelled`,
     *                                      `date_in_past`, `not_later` or `rail_error`
     */
    public function extendTrial(Authenticatable $actor, Model $billable, CarbonInterface $until): void
    {
        $subscription = $this->actionableSubscription($actor, $billable, 'extendTrial');

        if (! $subscription->onTrial()) {
            $this->refuseOnStripe('not_trialing', $actor, $billable, 'extendTrial');
        }

        if ($subscription->onGracePeriod()) {
            $this->refuseOnStripe('already_cancelled', $actor, $billable, 'extendTrial');
        }

        if (! $until->isFuture()) {
            $this->refuseOnStripe('date_in_past', $actor, $billable, 'extendTrial');
        }

        $trialEndsAt = $this->dateAttribute($subscription, 'trial_ends_at');

        if ($trialEndsAt !== null && ! $until->greaterThan($trialEndsAt)) {
            $this->refuseOnStripe('not_later', $actor, $billable, 'extendTrial');
        }

        $this->onStripe($actor, $billable, 'extendTrial', fn () => $subscription->extendTrial($until));

        $this->recordOnStripe(BillingEventType::TRIAL_EXTENDED, $actor, $billable, $subscription, [
            'until' => $until->toIso8601ZuluString(),
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * Stripe invoices the subscription the moment the trial ends.
     *
     * @throws BillingAdministrationRefused `no_subscription`, `not_trialing`, `already_cancelled` or `rail_error`
     */
    public function endTrial(Authenticatable $actor, Model $billable): void
    {
        $subscription = $this->actionableSubscription($actor, $billable, 'endTrial');

        if (! $subscription->onTrial()) {
            $this->refuseOnStripe('not_trialing', $actor, $billable, 'endTrial');
        }

        if ($subscription->onGracePeriod()) {
            $this->refuseOnStripe('already_cancelled', $actor, $billable, 'endTrial');
        }

        $this->onStripe($actor, $billable, 'endTrial', fn () => $subscription->endTrial());

        $this->recordOnStripe(BillingEventType::TRIAL_ENDED, $actor, $billable, $subscription, [
            'note' => 'stripe_bills_now',
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * @throws BillingAdministrationRefused `no_subscription`, `already_cancelled` or `rail_error`
     */
    public function cancel(Authenticatable $actor, Model $billable): void
    {
        $subscription = $this->actionableSubscription($actor, $billable, 'cancel');

        if ($subscription->onGracePeriod() || $subscription->ended()) {
            $this->refuseOnStripe('already_cancelled', $actor, $billable, 'cancel');
        }

        $this->onStripe($actor, $billable, 'cancel', fn () => $subscription->cancel());

        $this->recordOnStripe(BillingEventType::SUBSCRIPTION_CANCELLED, $actor, $billable, $subscription, [
            'ends_at' => $this->dateAttribute($subscription, 'ends_at')?->toIso8601String(),
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * Not Cashier's `resume()`: off trial it also sends `trial_end: now`, which
     * can invoice the customer and move their billing date when all the
     * operator asked for was to lift the cancellation. Only
     * `cancel_at_period_end` is sent, and the local half of Cashier's resume
     * (the rail's status, no end) is mirrored onto the row.
     *
     * That flag only lifts a cancellation at the period's end. One on a fixed
     * `cancel_at` date would survive it, so the live subscription is read first
     * and such a cancellation is refused rather than reported as resumed.
     *
     * @throws BillingAdministrationRefused `no_subscription`, `not_on_grace_period`, `scheduled_cancel`
     *                                      or `rail_error`
     */
    public function resume(Authenticatable $actor, Model $billable): void
    {
        // 1. Only a cancelled subscription still inside its period resumes.
        $subscription = $this->actionableSubscription($actor, $billable, 'resume');

        if (! $subscription->onGracePeriod()) {
            $this->refuseOnStripe('not_on_grace_period', $actor, $billable, 'resume');
        }

        // 2. A cancellation on a fixed date is the provider's to lift.
        $current = $this->onStripe(
            $actor,
            $billable,
            'resume',
            fn (): StripeSubscription => $subscription->asStripeSubscription(),
        );

        if ($current->cancel_at !== null && $current->cancel_at_period_end !== true) {
            $this->refuseOnStripe('scheduled_cancel', $actor, $billable, 'resume');
        }

        // 3. Lift the period-end cancellation, then mirror Cashier's local half.
        $live = $this->onStripe(
            $actor,
            $billable,
            'resume',
            fn (): StripeSubscription => $subscription->updateStripeSubscription([
                'cancel_at_period_end' => false,
            ]),
        );

        $subscription->forceFill([
            'stripe_status' => $live->status,
            'ends_at' => null,
        ])->save();

        $this->recordOnStripe(BillingEventType::SUBSCRIPTION_RESUMED, $actor, $billable, $subscription, [
            'stripe_status' => $live->status,
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * `$reason` is Stripe's refund reason, `requested_by_customer` or
     * `duplicate`, sent to Stripe and kept on the event row.
     *
     * The target is {@see StripeBillingState::latestRefundablePayment()}, the
     * newest paid invoice of the local `default` subscription, and the refund
     * is created under the key `admin-refund:{invoice}:{reason}`: a second
     * click, or a retry after a timeout, is answered with the refund the first
     * one created instead of refunding twice. The reason is part of the key
     * because Stripe refuses a replayed key whose parameters differ; a second
     * reason against an already refunded charge is refused by Stripe itself. That replay is also why the row is recorded
     * once per refund id, with the refund's own amount and currency. The
     * subscription is left alone.
     *
     * @throws BillingAdministrationRefused `no_subscription`, `invalid_reason`, `nothing_refundable`,
     *                                      `stale_target` or `rail_error`
     */
    public function refundLastInvoice(
        Authenticatable $actor,
        Model $billable,
        string $reason,
        ?string $expectedInvoiceId = null,
    ): string {
        // 1. A Stripe subscription to stand behind the refund, and a reason
        //    Stripe takes.
        $this->actionableSubscription($actor, $billable, 'refundLastInvoice');

        if (! in_array($reason, self::REFUND_REASONS, true)) {
            $this->refuseOnStripe('invalid_reason', $actor, $billable, 'refundLastInvoice');
        }

        // 2. The newest invoice that moved money, or nothing.
        $payment = $this->onStripe(
            $actor,
            $billable,
            'refundLastInvoice',
            fn (): ?array => StripeBillingState::latestRefundablePayment($this->currentCopy($billable)),
        );

        if ($payment === null) {
            $this->refuseOnStripe('nothing_refundable', $actor, $billable, 'refundLastInvoice');
        }

        // An invoice paid since the operator confirmed is not what they confirmed.
        if ($expectedInvoiceId !== null && $payment['invoice_id'] !== $expectedInvoiceId) {
            $this->refuseOnStripe('stale_target', $actor, $billable, 'refundLastInvoice');
        }

        // 3. The refund, keyed on the invoice so it can happen once.
        $refund = $this->onStripe(
            $actor,
            $billable,
            'refundLastInvoice',
            fn (): Refund => Cashier::stripe()->refunds->create([
                'payment_intent' => $payment['payment_intent'],
                'reason' => $reason,
                'metadata' => [
                    'source' => 'magic-starter-admin',
                    'invoice' => $payment['invoice_id'],
                ],
            ], [
                'idempotency_key' => 'admin-refund:' . $payment['invoice_id'] . ':' . $reason,
            ]),
        );

        // 4. One row per refund, however often the key replayed it.
        if (! $this->recorder->recorded(BillingEventType::INVOICE_REFUNDED, $refund->id, null)) {
            $this->recorder->record(
                BillingEventType::INVOICE_REFUNDED,
                BillingSource::ADMIN,
                $billable,
                provider: BillingProvider::STRIPE,
                externalId: $refund->id,
                properties: [
                    'invoice_id' => $payment['invoice_id'],
                    'amount' => $refund->amount,
                    'currency' => $refund->currency,
                    'reason' => $reason,
                ],
                actor: $actor,
            );
        }

        return $refund->id;
    }

    /**
     * {@inheritDoc}
     *
     * Stripe when the billable has a local `default` subscription, the store
     * where {@see self::storeMayAnswer()} holds, both when both hold. Each
     * leaves an `entitlement_synced` row saying whether the entitlement now
     * means something else.
     *
     * @throws BillingAdministrationRefused `nothing_to_sync`, `unmapped_price` or `rail_error`
     */
    public function sync(Authenticatable $actor, Model $billable): void
    {
        $current = $this->currentCopy($billable);
        $subscription = StripeBillingState::defaultSubscription($current);
        $provider = BillingProvider::fromWire($this->stringAttribute($current, 'plan_provider'));
        $readsStore = $this->storeMayAnswer($current, $provider);

        if (! $subscription instanceof CashierSubscription && ! $readsStore) {
            $this->refuse(new BillingAdministrationRefused('nothing_to_sync'), $actor, $billable, 'sync');
        }

        if ($subscription instanceof CashierSubscription) {
            $this->syncStripe($actor, $billable, $subscription);
        }

        if ($readsStore) {
            $this->syncStore($actor, $billable, $provider->isStore() ? $provider : null);
        }
    }

    /**
     * Whether the store rail may have something to say about the billable: it
     * is configured, and the record is a store's, or a record of nobody or of
     * an ended comp. A store payer can sit behind the last two: a purchase made
     * during a comp was dropped while the comp held the record, and when the
     * re-read after the comp ended failed, the record was left MANUAL, which
     * the reconciler never walks. While a comp is still open there is nothing
     * to read, because the write rules would drop the store's word anyway.
     */
    protected function storeMayAnswer(Model $billable, BillingProvider $provider): bool
    {
        if (! StoreRailConfiguration::railIsConfigured()) {
            return false;
        }

        if ($provider->isStore()) {
            return true;
        }

        return in_array($provider, [BillingProvider::MANUAL, BillingProvider::NONE], true)
            && ! BillingGrant::forBillable($billable)->open()->exists();
    }

    /**
     * {@inheritDoc}
     *
     * @return array{invoice_id: string, payment_intent: string, amount: int, currency: string}|null
     *
     * @throws ApiErrorException when Stripe cannot be asked; this is a read for display, with no
     *                           operator act to refuse
     */
    public function refundablePayment(Model $billable): ?array
    {
        return StripeBillingState::latestRefundablePayment($this->currentCopy($billable));
    }

    /**
     * {@inheritDoc}
     */
    public function paidRailGrants(Model $billable): bool
    {
        return $this->paidRail($billable) !== null;
    }

    /**
     * Close one open grant the way {@see ExpireBillingGrantsCommand} needs: as
     * superseded when the billable is no longer on it, as expired (with the
     * revocation written and the paid rails re-projected) when it is and its
     * expiry has passed, and not at all otherwise.
     *
     * Not on the contract, because it is the sweep's step rather than an
     * operator's act. The grant ends whatever {@see WritesEntitlement::write()}
     * answers: a revocation the rules drop would otherwise be retried every
     * hour, forever, and the row that says so is the `entitlement_dropped` one.
     *
     * @return GrantEndReason|null how the grant ended, or null when it stays open
     *                             (or was already closed by somebody else)
     */
    public function settleGrant(BillingGrant $grant): ?GrantEndReason
    {
        $billable = $grant->billable;

        /** @var array{0: GrantEndReason|null, 1: Model|null} $settled */
        $settled = DB::transaction(function () use ($grant, $billable): array {
            // 1. The billable's row first, as grant and revoke take it, so the
            //    three cannot interleave; then the grant as it stands now.
            $fresh = $billable instanceof Model
                ? $billable->newQuery()->whereKey($billable->getKey())->lockForUpdate()->first()
                : null;

            $open = BillingGrant::query()->whereKey($grant->getKey())->open()->first();

            if (! $open instanceof BillingGrant) {
                return [null, null];
            }

            // 2. A record a paid rail or a newer grant took over is not this
            //    grant's to end, so nothing is written over it.
            if (! $fresh instanceof Model || ! $this->isOnGrant($fresh, $open)) {
                $this->endGrant($open, GrantEndReason::SUPERSEDED);

                return [GrantEndReason::SUPERSEDED, null];
            }

            if ($open->expires_at === null || $open->expires_at->isFuture()) {
                return [null, null];
            }

            // 3. Still on it and expired: revoke, close and record.
            $written = $this->entitlements->write(new EntitlementWrite(
                billable: $fresh,
                plan: null,
                status: PlanStatus::EXPIRED,
                provider: BillingProvider::MANUAL,
                eventAt: $this->eventAtAfterRecord($fresh, BillingProvider::MANUAL),
                authoritative: true,
                source: BillingSource::ADMIN,
                productId: $open->productId(),
                renews: false,
            ));

            $this->endGrant($open, GrantEndReason::EXPIRED);

            $this->recorder->record(
                BillingEventType::GRANT_EXPIRED,
                BillingSource::ADMIN,
                $fresh,
                provider: BillingProvider::MANUAL,
                properties: [
                    'grant_id' => $open->getKey(),
                    'plan' => $open->plan,
                    'expires_at' => $open->expires_at->toIso8601ZuluString(),
                    'entitlement_written' => $written,
                ],
            );

            return [GrantEndReason::EXPIRED, $fresh];
        });

        [$outcome, $expired] = $settled;

        if ($expired instanceof Model) {
            $this->reprojectPaidRails($expired);
        }

        return $outcome;
    }

    /**
     * The grant's transaction: lock, re-check, supersede, create, write, record.
     *
     * @throws BillingAdministrationRefused `paid_rail_active` or `entitlement_refused`, which roll it back
     */
    protected function applyGrant(
        Authenticatable $actor,
        Model $billable,
        string $plan,
        string $reason,
        ?CarbonInterface $expiresAt,
    ): BillingGrant {
        // 1. The fresh row, locked: a checkout that landed since the first
        //    check has to win.
        $fresh = $this->lockedCopy($billable);
        $paidRail = $this->paidRail($fresh);

        if ($paidRail !== null) {
            throw new BillingAdministrationRefused('paid_rail_active', $paidRail);
        }

        // 2. At most one open grant per billable.
        BillingGrant::forBillable($fresh)
            ->open()
            ->get()
            ->each(fn (BillingGrant $open) => $this->endGrant($open, GrantEndReason::SUPERSEDED));

        $grant = BillingGrant::query()->create([
            'billable_type' => $fresh->getMorphClass(),
            'billable_id' => (string) $fresh->getKey(),
            'plan' => $plan,
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'granted_by' => $actor->getAuthIdentifier(),
        ]);

        // 3. A projection, never the rail speaking for itself: rule 2b then
        //    keeps it from moving a record a paying rail still holds.
        $written = $this->entitlements->write(new EntitlementWrite(
            billable: $fresh,
            plan: $plan,
            status: PlanStatus::ACTIVE,
            provider: BillingProvider::MANUAL,
            eventAt: $this->eventAtAfterRecord($fresh, BillingProvider::MANUAL),
            authoritative: false,
            source: BillingSource::ADMIN,
            productId: $grant->productId(),
            currentPeriodEnd: $expiresAt,
            renews: false,
        ));

        if (! $written) {
            throw new BillingAdministrationRefused('entitlement_refused', BillingProvider::MANUAL);
        }

        // 4. The operator's act, beside the entitlement row the write left.
        $this->recorder->record(
            BillingEventType::GRANT_ADDED,
            BillingSource::ADMIN,
            $fresh,
            provider: BillingProvider::MANUAL,
            properties: [
                'grant_id' => $grant->getKey(),
                'plan' => $plan,
                'reason' => $reason,
                'expires_at' => $expiresAt?->toIso8601ZuluString(),
            ],
            actor: $actor,
        );

        return $grant;
    }

    /**
     * The revoke's transaction: re-check the grant on the locked row, write the
     * revocation, close the grant and record it.
     *
     * @return Model the locked row, carrying what was written, for the re-projection
     *
     * @throws BillingAdministrationRefused `not_manual` or `entitlement_refused`, which roll it back
     */
    protected function applyRevoke(Authenticatable $actor, Model $billable, string $reason): Model
    {
        $fresh = $this->lockedCopy($billable);
        $grant = $this->grantOnRecord($fresh);

        if (! $grant instanceof BillingGrant) {
            throw new BillingAdministrationRefused(
                'not_manual',
                BillingProvider::fromWire($this->stringAttribute($fresh, 'plan_provider')),
            );
        }

        $written = $this->entitlements->write(new EntitlementWrite(
            billable: $fresh,
            plan: null,
            status: PlanStatus::CANCELED,
            provider: BillingProvider::MANUAL,
            eventAt: $this->eventAtAfterRecord($fresh, BillingProvider::MANUAL),
            authoritative: true,
            source: BillingSource::ADMIN,
            productId: $grant->productId(),
            renews: false,
        ));

        if (! $written) {
            throw new BillingAdministrationRefused('entitlement_refused', BillingProvider::MANUAL);
        }

        $this->endGrant($grant, GrantEndReason::REVOKED);

        $this->recorder->record(
            BillingEventType::GRANT_REVOKED,
            BillingSource::ADMIN,
            $fresh,
            provider: BillingProvider::MANUAL,
            properties: [
                'grant_id' => $grant->getKey(),
                'plan' => $grant->plan,
                'reason' => $reason,
            ],
            actor: $actor,
        );

        return $fresh;
    }

    /**
     * Revoke a store record whose subscriber holds sandbox purchases only.
     *
     * The store job leaves such a record alone, because a sandbox purchase is
     * no evidence that a production tier ended, so a sandbox id delisted after
     * it was granted would keep its tier forever. The read runs before the
     * transaction, so no network call holds the row lock.
     */
    protected function revokeSandboxOnlyStoreRecord(
        Authenticatable $actor,
        Model $billable,
        BillingProvider $provider,
        string $reason,
    ): void {
        // 1. The authoritative read; no answer is not an answer of "nothing".
        try {
            $subscriber = $this->revenueCat->subscriber((string) $billable->getKey());
        } catch (ConnectionException|RequestException|RuntimeException $failure) {
            $this->refuse(
                new BillingAdministrationRefused('rail_error', $provider, $failure),
                $actor,
                $billable,
                'revoke',
            );
        }

        // 2. A production subscription is the store's to end, and so is a
        //    sandbox one the store job counts for this billable: revoked here,
        //    the next re-projection would put it straight back.
        if ($this->holdsProductionSubscription($subscriber, $this->acceptsSandbox($billable))) {
            $this->refuse(new BillingAdministrationRefused('not_manual', $provider), $actor, $billable, 'revoke');
        }

        // 3. Revoke on the store's own rail, so its next word is same-rail.
        $revoked = $this->transaction($actor, $billable, 'revoke', function () use (
            $actor,
            $billable,
            $provider,
            $reason,
        ): Model {
            $fresh = $this->lockedCopy($billable);

            if (BillingProvider::fromWire($this->stringAttribute($fresh, 'plan_provider')) !== $provider) {
                throw new BillingAdministrationRefused('not_manual', $provider);
            }

            $written = $this->entitlements->write(new EntitlementWrite(
                billable: $fresh,
                plan: null,
                status: PlanStatus::EXPIRED,
                provider: $provider,
                eventAt: $this->eventAtAfterRecord($fresh, $provider),
                authoritative: true,
                source: BillingSource::ADMIN,
                productId: $this->stringAttribute($fresh, 'plan_product_id'),
                renews: false,
            ));

            if (! $written) {
                throw new BillingAdministrationRefused('entitlement_refused', $provider);
            }

            $this->recorder->record(
                BillingEventType::GRANT_REVOKED,
                BillingSource::ADMIN,
                $fresh,
                provider: $provider,
                properties: [
                    'reason' => $reason,
                    'store_sandbox_only' => true,
                ],
                actor: $actor,
            );

            return $fresh;
        });

        // 4. A card still billing behind the sandbox record goes back on record.
        $this->reprojectPaidRails($revoked);
    }

    /**
     * The Stripe half of {@see self::sync()}: read the subscription live, bring
     * the local Cashier row in line with it, and write the claim it makes.
     *
     * The claim is built from the LIVE object and is therefore authoritative,
     * like a webhook's and unlike the reconciler's projection of the local row:
     * Stripe speaking now may take the record over from a comp or a store. The
     * read runs before the transaction, so no network call holds the row lock.
     *
     * The claim is stamped when the read starts, not when it returns: a webhook
     * that lands while the read is in flight speaks for a newer state than the
     * object read, and rule 1 has to let it win.
     */
    protected function syncStripe(Authenticatable $actor, Model $billable, CashierSubscription $subscription): void
    {
        // 1. The stamp and the snapshot, as the record stands before the read.
        $current = $this->currentCopy($billable);
        $eventAt = $this->eventAtAfterRecord($current, BillingProvider::STRIPE);
        $before = $this->entitlementSnapshot($current);

        // 2. The rail's word.
        $object = $this->onStripe(
            $actor,
            $billable,
            'sync',
            fn (): StripeSubscription => $subscription->asStripeSubscription(),
        )->toArray();

        // 3. The local row, its items and the record, together or not at all.
        $this->transaction($actor, $billable, 'sync', function () use (
            $billable,
            $subscription,
            $object,
            $eventAt,
        ): void {
            $fresh = $this->lockedCopy($billable);

            $this->mirrorLiveSubscription($subscription, $object);

            $this->entitlements->write($this->liveSubscriptionClaim($fresh, $object, $eventAt));
        });

        // 4. Readers of the caller's instance see the healed row.
        $billable->unsetRelation('subscriptions');

        $this->recordSynced(
            $actor,
            $billable,
            BillingProvider::STRIPE,
            $before,
            $this->stringAttribute($subscription, 'stripe_id'),
        );
    }

    /**
     * The store half of {@see self::sync()}: the store job's authoritative
     * re-read, filed under the operator. A read that fails is refused, since
     * here nothing has happened yet that the failure could leave half done.
     *
     * A database fault is not the rail failing: it propagates, as it would
     * from anywhere else in this action.
     *
     * @param  BillingProvider|null  $store  the store on record, or null when the record names none
     */
    protected function syncStore(Authenticatable $actor, Model $billable, ?BillingProvider $store): void
    {
        $before = $this->entitlementSnapshot($this->currentCopy($billable));

        try {
            (new SyncRevenueCatEntitlement($this->storeRereadEvent($billable), BillingSource::ADMIN))
                ->handle($this->revenueCat, $this->entitlements);
        } catch (QueryException|ModelNotFoundException $fault) {
            throw $fault;
        } catch (ConnectionException|RequestException|RuntimeException $failure) {
            $this->refuse(
                new BillingAdministrationRefused('rail_error', $store, $failure),
                $actor,
                $billable,
                'sync',
            );
        }

        // A payer found behind a comp names their store once the read put it on record.
        $onRecord = BillingProvider::fromWire($this->stringAttribute($this->currentCopy($billable), 'plan_provider'));

        $this->recordSynced($actor, $billable, $onRecord->isStore() ? $onRecord : $store, $before);
    }

    /**
     * Bring the local Cashier row and its items in line with the live
     * subscription, field for field as Cashier's own
     * `customer.subscription.updated` handler does.
     *
     * One divergence: a cancellation at period end takes the period end from
     * the live object's first item rather than through
     * `Subscription::currentPeriodEnd()`, which would retrieve every item again
     * for a value already in hand.
     *
     * @param  array<string, mixed>  $object  the live Stripe subscription, as an array
     */
    protected function mirrorLiveSubscription(CashierSubscription $subscription, array $object): void
    {
        // 1. Price and quantity live on the row only for a single-price subscription.
        $items = $object['items']['data'] ?? [];
        $first = $items[0] ?? null;
        $isSinglePrice = count($items) === 1;

        $subscription->stripe_price = $isSinglePrice ? $first['price']['id'] : null;
        $subscription->quantity = $isSinglePrice && isset($first['quantity']) ? $first['quantity'] : null;

        // 2. The trial end, only when the object speaks about it.
        if (array_key_exists('trial_end', $object)) {
            $subscription->trial_ends_at = $object['trial_end']
                ? CarbonImmutable::createFromTimestamp((int) $object['trial_end'])
                : null;
        }

        // 3. The end: the trial's or the period's for a period-end cancel, the
        //    fixed date otherwise, none when nothing is cancelled.
        if ($object['cancel_at_period_end'] ?? false) {
            $subscription->ends_at = $subscription->onTrial()
                ? $subscription->trial_ends_at
                : $this->livePeriodEnd($object);
        } elseif (isset($object['cancel_at']) || isset($object['canceled_at'])) {
            $subscription->ends_at = CarbonImmutable::createFromTimestamp(
                (int) ($object['cancel_at'] ?? $object['canceled_at']),
            );
        } else {
            $subscription->ends_at = null;
        }

        // 4. The rail's status.
        if (isset($object['status'])) {
            $subscription->stripe_status = $object['status'];
        }

        $subscription->save();

        // 5. The items, by their Stripe id, and none Stripe no longer lists: a
        //    missed swap otherwise leaves the old price's item behind.
        if (! isset($object['items'])) {
            return;
        }

        $itemIds = [];

        foreach ($items as $item) {
            $itemIds[] = $item['id'];

            $subscription->items()->updateOrCreate([
                'stripe_id' => $item['id'],
            ], [
                'stripe_product' => $item['price']['product'],
                'stripe_price' => $item['price']['id'],
                'quantity' => $item['quantity'] ?? null,
            ]);
        }

        $subscription->items()->whereNotIn('stripe_id', $itemIds)->delete();
    }

    /**
     * The claim the live subscription makes, field for field as
     * `StripeWebhookController::subscriptionClaim()` maps a webhook's object,
     * filed under the operator.
     *
     * @param  array<string, mixed>  $object  the live Stripe subscription, as an array
     * @param  CarbonInterface  $eventAt  stamped before the live read, see {@see self::syncStripe()}
     *
     * @throws BillingAdministrationRefused `unmapped_price`, when a granting subscription's price names no
     *                                      tier: a config gap is never a downgrade, so nothing is written
     */
    protected function liveSubscriptionClaim(Model $billable, array $object, CarbonInterface $eventAt): EntitlementWrite
    {
        $status = is_string($object['status'] ?? null) ? $object['status'] : 'incomplete';
        $priceId = $object['items']['data'][0]['price']['id'] ?? null;
        $priceId = is_string($priceId) ? $priceId : null;

        // A status that does not grant owes nothing whatever the price says.
        $plan = null;

        if (StripeSubscriptionState::grants($status)) {
            $plan = StripeSubscriptionState::planForPrice($priceId);

            if ($plan === null) {
                BillingLog::warning('Stripe price id is not mapped to a plan; entitlement left untouched.', [
                    'price_id' => $priceId,
                    'billable_id' => $billable->getKey(),
                ]);

                throw new BillingAdministrationRefused('unmapped_price', BillingProvider::STRIPE);
            }
        }

        return new EntitlementWrite(
            billable: $billable,
            plan: $plan,
            status: StripeSubscriptionState::planStatusFor($status),
            provider: BillingProvider::STRIPE,
            eventAt: $eventAt,
            authoritative: true,
            source: BillingSource::ADMIN,
            providerStatus: $status,
            productId: $priceId,
            currentPeriodEnd: $this->livePeriodEnd($object),
            renews: array_key_exists('cancel_at_period_end', $object)
                ? ! (bool) $object['cancel_at_period_end']
                : null,
        );
    }

    /**
     * The paid period's end on the live object: on the first item under the
     * current API version, at the subscription level under older ones.
     *
     * @param  array<string, mixed>  $object
     */
    protected function livePeriodEnd(array $object): ?CarbonInterface
    {
        $timestamp = $object['items']['data'][0]['current_period_end']
            ?? $object['current_period_end']
            ?? null;

        return $timestamp === null ? null : CarbonImmutable::createFromTimestamp((int) $timestamp);
    }

    /**
     * Record one rail's sync, and whether the entitlement now means something
     * other than it did before the sync.
     *
     * @param  BillingProvider|null  $provider  Stripe, or the store that was read (null when it named none)
     * @param  array<string, mixed>  $before  {@see self::entitlementSnapshot()} from before the sync
     */
    protected function recordSynced(
        Authenticatable $actor,
        Model $billable,
        ?BillingProvider $provider,
        array $before,
        ?string $externalId = null,
    ): void {
        $after = $this->entitlementSnapshot($this->currentCopy($billable));
        $rail = $provider === BillingProvider::STRIPE ? 'stripe' : 'store';

        $this->recorder->record(
            BillingEventType::ENTITLEMENT_SYNCED,
            BillingSource::ADMIN,
            $billable,
            provider: $provider,
            externalId: $externalId,
            properties: [
                'rail' => $rail,
                'changed' => $this->entitlementChanges($before, $after) !== [],
            ],
            actor: $actor,
        );
    }

    /**
     * The billable's local `default` Cashier subscription, which every Stripe
     * operation acts on, or a `no_subscription` refusal.
     *
     * The gate is the subscription and never the record: a checkout over a
     * comp leaves the record naming somebody else while Cashier bills the card.
     *
     * @param  string  $operation  the contract method, kept on the refusal row
     *
     * @throws BillingAdministrationRefused `no_subscription`
     */
    protected function actionableSubscription(
        Authenticatable $actor,
        Model $billable,
        string $operation,
    ): CashierSubscription {
        $subscription = StripeBillingState::defaultSubscription($this->currentCopy($billable));

        if (! $subscription instanceof CashierSubscription) {
            $this->refuseOnStripe('no_subscription', $actor, $billable, $operation);
        }

        return $subscription;
    }

    /**
     * Run one Cashier or Stripe call, and turn the ways the rail fails into a
     * recorded `rail_error` carrying the rail's own message.
     *
     * `LogicException` covers Cashier's guards and the SDK's
     * `InvalidArgumentException`, both of which extend it. Never wrapped around
     * this action's own writes, so a defect of ours is not mistaken for the rail's.
     *
     * @template TResult
     *
     * @param  string  $operation  the contract method, kept on the refusal row
     * @param  Closure(): TResult  $call
     * @return TResult
     *
     * @throws BillingAdministrationRefused `rail_error`
     */
    protected function onStripe(Authenticatable $actor, Model $billable, string $operation, Closure $call): mixed
    {
        try {
            return $call();
        } catch (ApiErrorException|IncompletePayment|LogicException $failure) {
            $this->refuse(
                new BillingAdministrationRefused('rail_error', BillingProvider::STRIPE, $failure),
                $actor,
                $billable,
                $operation,
            );
        }
    }

    /**
     * Record a Stripe operation that went through, against the subscription it
     * changed.
     *
     * @param  array<string, mixed>  $properties
     */
    protected function recordOnStripe(
        BillingEventType $type,
        Authenticatable $actor,
        Model $billable,
        CashierSubscription $subscription,
        array $properties,
    ): void {
        $this->recorder->record(
            $type,
            BillingSource::ADMIN,
            $billable,
            provider: BillingProvider::STRIPE,
            externalId: $this->stringAttribute($subscription, 'stripe_id'),
            properties: $properties,
            actor: $actor,
        );
    }

    /**
     * Refuse a Stripe operation, recorded against the Stripe rail.
     *
     * @param  string  $operation  the contract method, kept on the refusal row
     *
     * @throws BillingAdministrationRefused always
     */
    protected function refuseOnStripe(
        string $reason,
        Authenticatable $actor,
        Model $billable,
        string $operation,
    ): never {
        $this->refuse(
            new BillingAdministrationRefused($reason, BillingProvider::STRIPE),
            $actor,
            $billable,
            $operation,
        );
    }

    /**
     * Let the paid rails put their own claim back after a grant ended.
     *
     * The Stripe half is the reconciler's local projection of the `default`
     * Cashier row, which applies now that the manual record no longer grants.
     * The store half is the store job's authoritative re-read, run only where
     * the rail is configured; a read that fails leaves the record as it is and
     * says so, because the grant has already ended and no answer is not a
     * reason to undo that. A database fault is not a failed read and propagates.
     */
    protected function reprojectPaidRails(Model $billable): void
    {
        $this->reconciler->reconcileStripeSubject($billable, BillingSource::ADMIN);

        if (! StoreRailConfiguration::railIsConfigured()) {
            return;
        }

        try {
            (new SyncRevenueCatEntitlement($this->storeRereadEvent($billable), BillingSource::ADMIN))
                ->handle($this->revenueCat, $this->entitlements);
        } catch (QueryException|ModelNotFoundException $fault) {
            throw $fault;
        } catch (ConnectionException|RequestException|RuntimeException $failure) {
            BillingLog::warning('A store rail read failed after a manual grant ended; entitlement left as is.', [
                'reason' => 'authoritative_read_failed',
                'billable_id' => $billable->getKey(),
                'rail' => 'store',
                'exception' => $failure::class,
                'message' => $failure->getMessage(),
            ]);
        }
    }

    /**
     * The store job's synthetic event, in the shape the reconciler builds for
     * the same re-read: who to read, when, the rail's word and an id for the log.
     *
     * @return array<string, mixed>
     */
    protected function storeRereadEvent(Model $billable): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => 'admin-' . $billable->getKey() . '-' . $now->getTimestampMs(),
            'type' => self::STORE_REREAD,
            'app_user_id' => (string) $billable->getKey(),
            'event_timestamp_ms' => $now->getTimestampMs(),
        ];
    }

    /**
     * The paid rail granting the billable now, or null when none is.
     *
     * Two sources, because the record alone can lie in the direction that
     * costs a payer: a checkout over a comp is dropped by the write rules and
     * leaves the record naming nobody while Cashier bills the card.
     */
    protected function paidRail(Model $billable): ?BillingProvider
    {
        $provider = BillingProvider::fromWire($this->stringAttribute($billable, 'plan_provider'));

        if (in_array($provider, self::PAID_RAILS, true)
            && PlanStatus::fromWire($this->stringAttribute($billable, 'plan_status'))->grants()
        ) {
            return $provider;
        }

        return $this->localStripeSubscriptionGrants($billable) ? BillingProvider::STRIPE : null;
    }

    /**
     * Whether the billable's local `default` Cashier subscription grants and
     * has not ended. A cancelled subscription still inside its period grants.
     */
    protected function localStripeSubscriptionGrants(Model $billable): bool
    {
        $subscription = StripeBillingState::defaultSubscription($billable);

        if ($subscription === null) {
            return false;
        }

        if (! StripeSubscriptionState::grants((string) $this->stringAttribute($subscription, 'stripe_status'))) {
            return false;
        }

        $endsAt = $this->dateAttribute($subscription, 'ends_at');

        return $endsAt === null || $endsAt->isFuture();
    }

    /**
     * Whether a plan may be granted: a tier of the published ranking, or, where
     * no ranking is published, any non-empty id. An unpublished catalogue is a
     * fresh install's normal state, and the write rules still refuse a grant
     * that would take a held tier away there.
     */
    protected function isGrantablePlan(string $plan): bool
    {
        $tierOrder = BillingCatalogue::tierOrder();

        if ($tierOrder === []) {
            return trim($plan) !== '';
        }

        return in_array($plan, $tierOrder, true);
    }

    /**
     * The open grant the billable's record is on, or null.
     */
    protected function grantOnRecord(Model $billable): ?BillingGrant
    {
        return BillingGrant::forBillable($billable)
            ->open()
            ->get()
            ->first(fn (BillingGrant $grant): bool => $this->isOnGrant($billable, $grant));
    }

    /**
     * Whether the record is MANUAL and names this grant.
     */
    protected function isOnGrant(Model $billable, BillingGrant $grant): bool
    {
        return BillingProvider::fromWire($this->stringAttribute($billable, 'plan_provider')) === BillingProvider::MANUAL
            && $this->stringAttribute($billable, 'plan_product_id') === $grant->productId();
    }

    /**
     * The stamp of a write on `$provider`: now, or one second past the record
     * when the record is already that rail's and `now()` is not later, so rule
     * 1 and rule 1b see a strictly newer event. A cross-rail write is never
     * compared by time, so it keeps `now()`.
     */
    protected function eventAtAfterRecord(Model $billable, BillingProvider $provider): CarbonInterface
    {
        $now = CarbonImmutable::now();
        $stored = $this->storedEventAt($billable);

        if ($stored === null
            || BillingProvider::fromWire($this->stringAttribute($billable, 'plan_provider')) !== $provider
        ) {
            return $now;
        }

        $next = CarbonImmutable::instance($stored)->addSecond();

        return $next->greaterThan($now) ? $next : $now;
    }

    /**
     * The billable's row as stored now, not as the caller's instance last saw
     * it: a write lands on the locked copy, so an instance a grant was made
     * through still reads the record from before that grant.
     */
    protected function currentCopy(Model $billable): Model
    {
        return $billable->newQuery()->whereKey($billable->getKey())->firstOrFail();
    }

    /**
     * The billable's row, re-read under `lockForUpdate()`.
     */
    protected function lockedCopy(Model $billable): Model
    {
        return $billable->newQuery()->whereKey($billable->getKey())->lockForUpdate()->firstOrFail();
    }

    protected function endGrant(BillingGrant $grant, GrantEndReason $reason): void
    {
        $grant->forceFill([
            'ended_at' => CarbonImmutable::now(),
            'end_reason' => $reason,
        ])->save();
    }

    /**
     * Whether any subscription the subscriber holds counts as a production
     * purchase.
     *
     * @param  array<string, mixed>  $subscriber  the RevenueCat `subscriber` object
     * @param  bool  $sandboxCounts  whether the store job treats this billable's sandbox purchases as production
     */
    protected function holdsProductionSubscription(array $subscriber, bool $sandboxCounts): bool
    {
        $subscriptions = is_array($subscriber['subscriptions'] ?? null) ? $subscriber['subscriptions'] : [];

        foreach ($subscriptions as $subscription) {
            if ($sandboxCounts || ! is_array($subscription) || ($subscription['is_sandbox'] ?? false) !== true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the store job counts sandbox purchases for this billable, read
     * from the same two keys it reads: `accept_sandbox` for everybody, or the
     * billable's KEY in `sandbox_app_user_ids`.
     */
    protected function acceptsSandbox(Model $billable): bool
    {
        if ((bool) config('magic-starter.billing.revenuecat.accept_sandbox', false)) {
            return true;
        }

        $allowlist = (array) config('magic-starter.billing.revenuecat.sandbox_app_user_ids', []);

        return in_array((string) $billable->getKey(), array_filter($allowlist, 'is_string'), true);
    }

    /**
     * Run `$work` in one transaction, and record a refusal it throws only after
     * that transaction rolled back.
     *
     * @template TResult
     *
     * @param  string  $operation  the contract method, kept on the refusal row
     * @param  Closure(): TResult  $work
     * @return TResult
     *
     * @throws BillingAdministrationRefused
     */
    protected function transaction(Authenticatable $actor, Model $billable, string $operation, Closure $work): mixed
    {
        try {
            return DB::transaction($work);
        } catch (BillingAdministrationRefused $refusal) {
            $this->refuse($refusal, $actor, $billable, $operation);
        }
    }

    /**
     * Record the `request_refused` row, then throw the refusal. A `rail_error`
     * keeps the rail's own message on the row, which the translated sentence
     * the panel shows does not carry.
     *
     * Never called inside a transaction this action opened, so the row outlives
     * whatever this action rolled back.
     *
     * @param  string  $operation  the contract method, kept on the refusal row
     *
     * @throws BillingAdministrationRefused always
     */
    protected function refuse(
        BillingAdministrationRefused $refusal,
        Authenticatable $actor,
        Model $billable,
        string $operation,
    ): never {
        $this->recorder->record(
            BillingEventType::REQUEST_REFUSED,
            BillingSource::ADMIN,
            $billable,
            provider: $refusal->provider(),
            reason: $refusal->reason(),
            properties: [
                'operation' => $operation,
                ...($refusal->getPrevious() === null ? [] : [
                    'rail_message' => $refusal->getPrevious()->getMessage(),
                ]),
            ],
            actor: $actor,
        );

        throw $refusal;
    }
}
