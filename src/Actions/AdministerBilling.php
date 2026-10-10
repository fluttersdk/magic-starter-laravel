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
use FlutterSdk\MagicStarter\Support\StripeSubscriptionState;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * The package's operator side of billing: a manual grant, its revoke and its
 * expiry, with the Stripe operations still to come.
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
     * @param  WritesEntitlement  $entitlements  the only code path that writes the entitlement columns
     * @param  BillingEventRecorder  $recorder  leaves every `billing_events` row this action owes
     * @param  RevenueCatClient  $revenueCat  the store rail's authoritative read
     */
    public function __construct(
        protected WritesEntitlement $entitlements,
        protected BillingEventRecorder $recorder,
        protected RevenueCatClient $revenueCat,
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
     */
    public function extendTrial(Authenticatable $actor, Model $billable, CarbonInterface $until): void
    {
        throw new LogicException('Not implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function endTrial(Authenticatable $actor, Model $billable): void
    {
        throw new LogicException('Not implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function cancel(Authenticatable $actor, Model $billable): void
    {
        throw new LogicException('Not implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function resume(Authenticatable $actor, Model $billable): void
    {
        throw new LogicException('Not implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function refundLastInvoice(Authenticatable $actor, Model $billable, string $reason): string
    {
        throw new LogicException('Not implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function sync(Authenticatable $actor, Model $billable): void
    {
        throw new LogicException('Not implemented');
    }

    /**
     * {@inheritDoc}
     */
    public function paidRailGrants(Model $billable): bool
    {
        return $this->paidRail($billable) !== null;
    }

    /**
     * {@inheritDoc}
     */
    public function refundablePayment(Model $billable): ?array
    {
        throw new LogicException('Not implemented');
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

        // 2. A production subscription is the store's to end.
        if ($this->holdsProductionSubscription($subscriber)) {
            $this->refuse(new BillingAdministrationRefused('not_manual', $provider), $actor, $billable, 'revoke');
        }

        // 3. Revoke on the store's own rail, so its next word is same-rail.
        $this->transaction($actor, $billable, 'revoke', function () use ($actor, $billable, $provider, $reason): void {
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
        });
    }

    /**
     * Let the paid rails put their own claim back after a grant ended.
     *
     * The Stripe half is the reconciler's local projection of the `default`
     * Cashier row, which applies now that the manual record no longer grants.
     * The store half is the store job's authoritative re-read, run only where
     * the rail is configured; a read that fails leaves the record as it is and
     * says so, because the grant has already ended and no answer is not a
     * reason to undo that.
     */
    protected function reprojectPaidRails(Model $billable): void
    {
        app(ReconcileBillingEntitlements::class)->reconcileStripeSubject($billable, BillingSource::ADMIN);

        if (! StoreRailConfiguration::railIsConfigured()) {
            return;
        }

        try {
            (new SyncRevenueCatEntitlement($this->storeRereadEvent($billable), BillingSource::ADMIN))
                ->handle($this->revenueCat, $this->entitlements);
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
        // Cashier's `Billable` is the consuming application's choice.
        if (! method_exists($billable, 'subscription')) {
            return false;
        }

        $subscription = $billable->subscription(StripeSubscriptionState::SUBSCRIPTION_TYPE);

        if (! $subscription instanceof Model) {
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
     * Whether any subscription the subscriber holds is a production purchase.
     *
     * @param  array<string, mixed>  $subscriber  the RevenueCat `subscriber` object
     */
    protected function holdsProductionSubscription(array $subscriber): bool
    {
        $subscriptions = is_array($subscriber['subscriptions'] ?? null) ? $subscriber['subscriptions'] : [];

        foreach ($subscriptions as $subscription) {
            if (! is_array($subscription) || ($subscription['is_sandbox'] ?? false) !== true) {
                return true;
            }
        }

        return false;
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
     * Record the `request_refused` row, then throw the refusal.
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
            ],
            actor: $actor,
        );

        throw $refusal;
    }
}
