<?php

namespace FlutterSdk\MagicStarter\Console;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Contracts\WritesEntitlement;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Jobs\SyncRevenueCatEntitlement;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\EntitlementWrite;
use FlutterSdk\MagicStarter\Support\ReadsBillableAttributes;
use FlutterSdk\MagicStarter\Support\RevenueCatClient;
use FlutterSdk\MagicStarter\Support\StripeSubscriptionState;
use FlutterSdk\MagicStarter\Support\TeamKey;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The only thing that heals a dropped webhook.
 *
 * Both rails give up. RevenueCat retries a failed delivery five times inside
 * about three hours (5, 10, 20, 40 and 80 minutes) and then abandons the event
 * permanently; Stripe stops after roughly three days. Nothing after that point
 * ever mentions the event again, so the drift it left is permanent, and neither
 * rail's abandonment raises anything here. That makes the two failure modes
 * silent and opposite:
 *
 *  - a dropped `EXPIRATION` (or `customer.subscription.deleted`) is a paid tier
 *    held for free, forever, with nobody to complain;
 *  - a dropped `INITIAL_PURCHASE` is a paying customer stuck on the free tier
 *    with NO self-serve recovery, because the store will not sell them a
 *    subscription they already own.
 *
 * This command re-reads what each rail says and routes every correction through
 * {@see WritesEntitlement}, which stays the single code path that writes the
 * entitlement columns. Every correction is logged at warning level: a
 * reconciler that silently fixes things is a reconciler that hides a broken
 * webhook, and the log line is the only evidence a delivery was ever missed.
 *
 * ## The two directions of wrongness are not symmetric
 *
 * Revoking too eagerly takes a tier away from somebody who is paying, on a
 * schedule, from every affected subject at once. Correcting nothing leaves
 * pre-existing drift in place. So every ambiguity here resolves toward keeping
 * the entitlement, and in particular a failed authoritative READ is never a
 * revocation: {@see RevenueCatClient} raises rather than answering an empty
 * subscriber precisely so that a 503, a timeout or a missing API key can skip
 * the billable instead of emptying it. Absence of a reason to grant is not a
 * reason to revoke.
 *
 * ## The two rails are read differently, and the asymmetry is principled
 *
 * The STORE rail has an authority to ask: `GET /v1/subscribers/{app_user_id}` is
 * the truth, freshly read, so its answer is applied unconditionally and the
 * mapping is not re-implemented here at all. {@see SyncRevenueCatEntitlement} is
 * invoked directly as the store rail's read-and-claim operation, because a
 * second liveness rule is the one thing this command must not invent: a
 * subscription entitles while EITHER `expires_date` OR
 * `grace_period_expires_date` reaches into the future, and two definitions of
 * that would eventually disagree about whether a customer is paid up.
 *
 * The STRIPE rail has no local authority. `subscriptions.stripe_status` and
 * `stripe_price` are themselves a projection of the same webhooks that may have
 * been dropped, so a local read cannot be fresher truth than the delivery that
 * wrote it. It therefore claims only when it DISAGREES with the entitlement on
 * record, which is real drift the webhook projection can leave behind (an
 * unmapped price at the time, a claim the ordering rules dropped, a manual
 * database edit). What a local read cannot do is heal a Stripe delivery that
 * never arrived, because Cashier's sync and the entitlement projection run in
 * one transaction and a dropped event leaves both stale. Closing that would
 * take a live Stripe read per billable, and `Subscription::currentPeriodEnd()`
 * is one Stripe round-trip PER subscription item, so a loop over the fleet would
 * put thousands of synchronous calls behind a scheduled sweep. The gap is named
 * rather than papered over.
 *
 * ## Which subjects are walked
 *
 * The three rails that have something to re-read: `stripe`, `app_store` and
 * `play_store`. `manual` is deliberately excluded even though it is not `none`,
 * because an operator-granted plan has no authority to compare against and
 * every possible comparison would revoke it. An unrecognised provider value,
 * which is what an older deploy sees after a newer one ships a fourth rail, is
 * excluded by the same `whereIn`: a rail this build cannot read is a rail this
 * build must not correct.
 *
 * Plus a second arm, for the rows that predate every rail: `plan_provider` NULL
 * WITH a local billing signal. See {@see self::onAReadableRail()} for why NULL
 * needs its own arm at all, why the signal is required with it, and why the
 * store rail cannot have one. Such a subject reads as {@see
 * BillingProvider::NONE}, which is not a store, so it is reconciled through the
 * Stripe branch; that branch is what the local signal attests to.
 *
 * ## The subject is whatever the application bills
 *
 * Nothing here names a team or a user. The walk runs over
 * {@see MagicStarter::billableModel()}, and Cashier's `Billable` trait is the
 * consuming application's decision rather than this package's, so every read
 * that trait provides is guarded with `method_exists()` before it is made.
 */
class ReconcileBillingEntitlements extends Command
{
    /**
     * The provenance columns are decoded through the shared readers rather than
     * read off the model. The package ships those columns and not the model that
     * casts them, and this class is where an undecoded read fails in three
     * different ways at once: an uncast `plan` read as an enum yields null with a
     * warning, an uncast `plan_current_period_end` read with `?->` is a fatal
     * Error, and an uncast `plan_renews` compares `1 !== true` and disagrees with
     * the column forever. The third one is the expensive one, because
     * {@see self::agreesWithRecord()} is what keeps this sweep from re-stamping
     * every billable's provenance on every run.
     */
    use ReadsBillableAttributes;

    /**
     * The command's name, declared once and read by the schedule registration in
     * the service provider.
     *
     * A constant rather than the same string in three places: the mutex name the
     * provider passes to `->name()` has to match what an operator sees, and a
     * schedule whose name drifts from its command is a duplicate-registration
     * check that cannot be written.
     */
    public const NAME = 'billing:reconcile';

    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = self::NAME . '
        {--billable= : Reconcile a single billable subject by id, for diagnosing one customer}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-read each billing rail and correct any entitlement that drifted';

    /**
     * Billable subjects held in memory at once. `chunkById` keyset-paginates, so
     * the walk is stable across the writes it makes inside the callback.
     */
    protected const CHUNK_SIZE = 100;

    /**
     * Cashier's subscription type. One per subject in the shape this package
     * ships; the parameter exists in Cashier for multi-plan billing that nothing
     * here sells.
     */
    protected const STRIPE_SUBSCRIPTION_TYPE = StripeSubscriptionState::SUBSCRIPTION_TYPE;

    /**
     * The word this command leaves in `plan_provider_status` on the store rail.
     *
     * That column holds the RAIL's own last word, which on a store is the
     * webhook event type; a reconciled row has no event behind it, so it says so
     * instead. It gates nothing, and an operator reading it learns the useful
     * thing: this row was last written by the sweep, not by a delivery.
     */
    protected const RECONCILED = 'RECONCILIATION';

    /**
     * How many subjects were walked, corrected, and skipped without a correction.
     */
    protected int $walked = 0;

    protected int $corrected = 0;

    protected int $unreadable = 0;

    /**
     * @param  RevenueCatClient  $revenueCat  the store rail's authoritative read
     * @param  WritesEntitlement  $writeEntitlement  the only code path allowed to
     *                                               write the entitlement columns
     */
    public function __construct(
        protected RevenueCatClient $revenueCat,
        protected WritesEntitlement $writeEntitlement,
    ) {
        parent::__construct();
    }

    /**
     * Walk the readable rails and correct what drifted.
     *
     * Exits non-zero when at least one subject's authoritative read FAILED, which
     * is the one outcome an operator needs to see from a hand-run `--billable=`:
     * the entitlement was not checked, so the absence of a correction says
     * nothing. A config gap (an unmapped price, a subject with no local
     * subscription row) is not a failure of this command and exits zero, having
     * logged.
     */
    public function handle(): int
    {
        // The tallies are reset per RUN and not merely initialised per instance,
        // because Artisan resolves a command ONCE and reuses that instance for
        // every later invocation in the same process. A one-shot `php artisan`
        // process never sees the difference; a second `Artisan::call()` in one
        // process reports the first run's subjects again, and so does a sweep
        // driven twice under a long-lived worker. The counts decide this
        // command's EXIT CODE, so a stale `unreadable` would also fail a run in
        // which nothing failed.
        $this->walked = 0;
        $this->corrected = 0;
        $this->unreadable = 0;

        $only = $this->option('billable');

        // A malformed id must not reach the query. On a UUID deployment the key
        // column is a PostgreSQL `uuid`, so a bad value in `where id = ?` raises
        // there while SQLite answers an empty set, which would make the default
        // test engine blind to a 500 production gets.
        if (is_string($only) && ! TeamKey::looksLikeOne($only)) {
            $this->error("[{$only}] cannot be a billable key, so nothing was queried.");

            return self::FAILURE;
        }

        $this->onAReadableRail(is_string($only) ? $only : null)
            ->chunkById(self::CHUNK_SIZE, function (Collection $billables): void {
                /** @var Collection<int, Model> $billables */
                foreach ($billables as $billable) {
                    $this->reconcile($billable);
                }
            });

        $this->info(sprintf(
            'Reconciled %d billable(s): %d corrected, %d unreadable.',
            $this->walked,
            $this->corrected,
            $this->unreadable,
        ));

        return $this->unreadable === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The subjects whose entitlement some rail can still be asked about.
     *
     * Candidates, not drift: which of them actually drifted is only knowable
     * after the rail has answered.
     *
     * `subscriptions` is eager-loaded because the Stripe half reads it per
     * subject and Cashier's `subscription()` is a lazy relation access, which
     * over a fleet-wide walk is one query each. Both that eager load and the
     * local-signal arm below are guarded on the relation EXISTING, because
     * applying Cashier's `Billable` trait is the consuming application's
     * decision: on a model without it, `with('subscriptions')` and
     * `orWhereHas('subscriptions')` are each a fatal error rather than an empty
     * result.
     *
     * @return Builder<Model>
     */
    protected function onAReadableRail(?string $only): Builder
    {
        /** @var class-string<Model> $model */
        $model = MagicStarter::billableModel();

        $carriesLocalSubscriptions = method_exists($model, 'subscriptions');

        $query = $model::query();

        if ($carriesLocalSubscriptions) {
            $query->with('subscriptions');
        }

        return $query
            // Both arms are wrapped in ONE group, and that grouping is
            // correctness rather than tidiness: `chunkById` appends its keyset
            // `id > ?` at the top level, and SQL binds AND tighter than OR, so
            // an ungrouped `orWhere` would attach the page boundary to the
            // second arm alone and the walk would restart from the top of the
            // first arm on every chunk.
            ->where(function (Builder $selected) use ($carriesLocalSubscriptions): void {
                $selected
                    ->whereIn('plan_provider', [
                        BillingProvider::STRIPE->value,
                        BillingProvider::APP_STORE->value,
                        BillingProvider::PLAY_STORE->value,
                    ])
                    // The rows that predate every rail. `whereIn` is satisfied
                    // by no NULL on either engine, and the provenance migration
                    // adds all ten columns nullable with a deliberate absence
                    // of backfill, so without this arm the first run against
                    // real data walks approximately nobody, and a dropped
                    // `INITIAL_PURCHASE` (which leaves the provenance NULL by
                    // construction) is excluded by the very filter meant to
                    // catch it.
                    //
                    // A LOCAL SIGNAL is required with it, because NULL
                    // provenance on its own is also every subject that has never
                    // bought anything, and walking the free tier on a schedule
                    // would log a `no_local_subscription` skip per subject
                    // forever.
                    //
                    // The signal is Stripe-only, and there is deliberately no
                    // store equivalent: a store customer leaves NOTHING behind
                    // locally. `app_user_id` exists only on the event that
                    // carries it (nothing persists it, and this command
                    // reconstructs it from the billable key), and RevenueCat
                    // publishes no list-subscribers endpoint, so the only way to
                    // find a NULL-provenance store customer would be to ask
                    // `GET /v1/subscribers` about every subject on the table,
                    // once per run. A NULL-provenance store purchase is
                    // therefore genuinely unreachable from here, and it is named
                    // rather than papered over with an arm that would select the
                    // wrong rows.
                    ->orWhere(fn (Builder $unprovenanced): Builder => $unprovenanced
                        ->whereNull('plan_provider')
                        ->where(fn (Builder $signal): Builder => $carriesLocalSubscriptions
                            ? $signal->whereNotNull('stripe_id')->orWhereHas('subscriptions')
                            : $signal->whereNotNull('stripe_id')));
            })
            ->when($only !== null, fn (Builder $query): Builder => $query->whereKey($only));
    }

    /**
     * Re-read one subject's rail, then report what actually moved.
     *
     * The correction is measured as a BEFORE/AFTER diff of the row rather than
     * as the claim that was made, and the difference matters: the write action's
     * ordering rules can legitimately drop a claim, and a claim that was dropped
     * is not a correction. Reporting the diff means the log carries exactly the
     * changes that landed, while a dropped claim is reported by the action's own
     * warning with the rule that dropped it.
     */
    protected function reconcile(Model $billable): void
    {
        $this->walked++;

        $provider = BillingProvider::fromWire($this->stringAttribute($billable, 'plan_provider'));
        $before = $this->snapshot($billable);

        $read = $provider->isStore()
            ? $this->reconcileStoreRail($billable)
            : $this->reconcileStripeRail($billable);

        if (! $read) {
            return;
        }

        $after = $this->snapshot($billable->refresh());
        $changed = array_keys(array_filter(
            $after,
            fn (mixed $value, string $field): bool => $value !== $before[$field],
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changed === []) {
            return;
        }

        $this->corrected++;
        $this->reportCorrection($billable, $provider, $before, $after, $changed);
    }

    /**
     * The store rail: read the authoritative subscriber and claim what it owes.
     *
     * Delegated to {@see SyncRevenueCatEntitlement} rather than reimplemented.
     * That job's body IS this operation (re-read one subscriber, map the tier,
     * claim it through the contract); the only thing a webhook adds is the reason
     * to run it. Calling it here keeps ONE liveness rule, one sandbox gate, one
     * unmapped-product rule and one revocation rule in the codebase, which is
     * the property that decides whether a paying customer keeps their tier.
     *
     * Returns false when the read failed, which is the skip: the entitlement is
     * left exactly as it was.
     */
    protected function reconcileStoreRail(Model $billable): bool
    {
        try {
            (new SyncRevenueCatEntitlement($this->reconciliationEvent($billable)))
                ->handle($this->revenueCat, $this->writeEntitlement);
        } catch (ConnectionException|RequestException|RuntimeException $failure) {
            // The three failures {@see RevenueCatClient} documents: nothing
            // reached RevenueCat, RevenueCat answered non-2xx, or the rail is
            // unconfigured. Each one means "no answer", and no answer is not an
            // expired subscription. Caught per SUBJECT so one unreachable
            // subscriber does not cost every subject behind it in the walk its
            // correction. Anything else is a defect rather than a rail failure
            // and is deliberately NOT caught: it stops the sweep loudly instead
            // of being filed as an unreadable subscriber.
            $this->unreadable++;

            Log::warning('A billing rail could not be read; entitlement left untouched.', [
                'reason' => 'authoritative_read_failed',
                'billable_id' => $billable->getKey(),
                'rail' => 'store',
                'exception' => $failure::class,
                'message' => $failure->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * The store rail's synthetic event.
     *
     * Four fields, which is all {@see SyncRevenueCatEntitlement} reads: which
     * subscriber to re-read, when, the rail's word for what happened, and an id
     * for the log.
     *
     * `event_timestamp_ms` is `now()`, and that is honest HERE while it would be
     * a defect in a webhook feeder. The monotonic ordering rule compares the
     * moment a truth was ESTABLISHED, and for an authoritative re-read that
     * moment is the read itself; a feeder stamping receipt time instead of its
     * event's time would claim freshness it does not have. The consequence is
     * intended: a webhook whose event predates this run is dropped by the write
     * path, because this run already read the rail's own state later than that
     * event was emitted. It costs at most one cadence of delay if RevenueCat's
     * API ever lags its own webhook.
     *
     * @return array<string, mixed>
     */
    protected function reconciliationEvent(Model $billable): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => 'reconcile-' . $billable->getKey() . '-' . $now->getTimestampMs(),
            'type' => self::RECONCILED,
            'app_user_id' => (string) $billable->getKey(),
            'event_timestamp_ms' => $now->getTimestampMs(),
        ];
    }

    /**
     * The Stripe rail, read locally and claimed only on disagreement.
     *
     * Returns true whenever the rail was READ, including when it had nothing to
     * say: none of the four skips below is a failure of this command, and none
     * of them may revoke.
     */
    protected function reconcileStripeRail(Model $billable): bool
    {
        // Cashier's `Billable` trait is applied by the CONSUMING application, so
        // this call is checked for rather than assumed, exactly as the billing
        // endpoints and the Stripe webhook feeder do it. In practice this arm is
        // unreachable on a trait-less deployment (nothing can write a `stripe`
        // provenance there, and the NULL-provenance arm withholds itself for the
        // same reason), which is why it skips rather than counting as unreadable:
        // a subject nobody can bill by card has no card rail to have drifted.
        if (! method_exists($billable, 'subscription')) {
            $this->skip($billable, 'no_cashier_billable', 'stripe', [
                'billable_model' => $billable::class,
            ]);

            return true;
        }

        $subscription = $billable->subscription(self::STRIPE_SUBSCRIPTION_TYPE);

        // An absent row is an ABSENCE, not a statement from Stripe that the
        // subscription ended, and the local table is itself a projection of
        // deliveries that may have been dropped. Revoking on it would empty the
        // tier of a migrated customer and of anybody whose Cashier row Stripe's
        // `incomplete_expired` branch deleted.
        if (! $subscription instanceof Model) {
            $this->skip($billable, 'no_local_subscription', 'stripe', [
                // The RAW column, matching `snapshot()` below and the write
                // path's own drop log. A tier read through anything that answers
                // a free-tier word over a NULL row would print that word here
                // instead, and this is the skip an operator sees most: it fires
                // every run for a subject carrying a `stripe_id` and no Cashier
                // row, so a wrong value here is the one that gets believed.
                'stored_plan' => $this->stringAttribute($billable, 'plan'),
            ]);

            return true;
        }

        // A row with no `updated_at` cannot be ordered against the stored
        // provenance at all, and `timestamps()` leaves that column nullable, so
        // an undated row is one Cashier's sync never finished writing. It is
        // skipped rather than read, and the skip is logged so the half-written
        // row is visible instead of being silently reconciled from.
        //
        // The claim below is stamped `now()` rather than with this timestamp
        // (see the comment there), so the guard no longer feeds a value object
        // that would refuse a null. It is kept because the behaviour is the
        // point: a row this incomplete is not evidence about anybody's tier.
        if ($this->dateAttribute($subscription, 'updated_at') === null) {
            $this->skip($billable, 'undated_subscription_row', 'stripe', [
                'subscription_id' => $this->stringAttribute($subscription, 'stripe_id'),
            ]);

            return true;
        }

        // Stamped NOW, not with the Cashier row's `updated_at`, and matching what
        // `reconciliationEvent()` already does on the store rail.
        //
        // Both columns are whole-second (`timestampTz` and `timestamps()` both
        // take the schema builder's default precision of 0), so `updated_at`
        // ties with `plan_source_event_at` exactly when the drift being healed
        // was CAUSED by two events sharing a second. A same-second downgrade
        // leaves the record on the higher tier and the Cashier row on the lower
        // price; the claim then arrives with an equal timestamp, the tie-break
        // drops it as a revocation, and the drift this command exists to heal
        // survives every run forever.
        //
        // The trade is real and is the same one the store rail already takes: a
        // genuinely late Stripe delivery whose `created` predates a reconcile
        // run is dropped as stale. Its Cashier sync still lands (the parent
        // handler runs before the projection), so the next run re-derives it.
        $claim = $this->stripeClaim($billable, $subscription, CarbonImmutable::now());

        // Only a disagreement is claimed. A local read is no fresher than the
        // delivery that wrote it, so re-applying an agreeing one would stamp
        // this run's provenance over a genuine event's and then have the
        // ordering rule drop the NEXT run, once per cadence, forever. That
        // guard, not the choice of timestamp, is what keeps the restamp loop
        // shut.
        if ($claim instanceof EntitlementWrite && ! $this->agreesWithRecord($billable, $claim)) {
            $this->writeEntitlement->write($claim);
        }

        return true;
    }

    /**
     * What the local Cashier row says the subject is owed, or null when it cannot
     * say.
     *
     * `$eventAt` is a parameter rather than read from the row here because the
     * caller has already established that the row HAS one, and re-deriving a
     * nullable value inside a method whose value object forbids null would make
     * this depend on a guard it cannot see.
     *
     * The subscription is a plain `Model` rather than Cashier's class, because a
     * consumer may point `Cashier::useSubscriptionModel()` at one of their own,
     * and its columns are read through the shared decoders for the same reason
     * the billable's are: the model belongs to the consuming application.
     */
    protected function stripeClaim(
        Model $billable,
        Model $subscription,
        CarbonInterface $eventAt,
    ): ?EntitlementWrite {
        $status = (string) $this->stringAttribute($subscription, 'stripe_status');
        $priceId = $this->stringAttribute($subscription, 'stripe_price');

        // A locally recorded non-granting status is a positive statement that
        // the subscription finished, so it really does revoke. That is the
        // asymmetry with the absent row above: this is an answer, not a gap.
        if (! StripeSubscriptionState::grants($status)) {
            return new EntitlementWrite(
                billable: $billable,
                // No tier, matching what the webhook feeder writes on the same
                // reading: a finished subscription owes nothing, and naming a
                // free tier would be that absence wearing a tier's name.
                plan: null,
                status: StripeSubscriptionState::planStatusFor($status),
                provider: BillingProvider::STRIPE,
                eventAt: $eventAt,
                // A PROJECTION, and the whole Stripe branch of this command is
                // one: `stripe_status` and `stripe_price` are themselves what a
                // webhook wrote here earlier, so this can correct drift in the
                // record and cannot testify that Stripe is the rail billing
                // anybody. The store branch does not go through here at all; it
                // runs a job that re-reads the rail.
                authoritative: false,
                providerStatus: $status,
                productId: $priceId,
                // A finished subscription has no period left to run and nothing
                // left to renew, matching what the webhook feeder writes on a
                // deletion.
                renews: false,
            );
        }

        // An unmapped price is a CONFIG gap, exactly as it is in the webhook
        // feeder: the absence of a reason to grant is not a reason to revoke.
        $plan = StripeSubscriptionState::planForPrice($priceId);

        if ($plan === null) {
            $this->skip($billable, 'unmapped_price', 'stripe', [
                'price_id' => $priceId,
            ]);

            return null;
        }

        return new EntitlementWrite(
            billable: $billable,
            plan: $plan,
            status: StripeSubscriptionState::planStatusFor($status),
            provider: BillingProvider::STRIPE,
            eventAt: $eventAt,
            // A projection, for the same reason as the branch above.
            authoritative: false,
            providerStatus: $status,
            productId: $priceId,
            // The local row carries no period column, so the stored value is
            // carried forward: the write path sets every column on each apply, so
            // passing null would BLANK a period a subscription event had already
            // established, and reading Cashier's accessor for the real one would
            // be a live Stripe call per subscription item.
            //
            // The webhook feeder guards this carry-forward with a "is Stripe the
            // rail on record" check, because an invoice can arrive for a subject
            // a store rail has since taken over. Here that check would be dead
            // code: the two selections that reach this branch are a stored
            // `plan_provider` of stripe, and a NULL one, which is a row no rail
            // has ever written and therefore a row with no period for a store to
            // have established. Neither can carry a store's period forward.
            currentPeriodEnd: $this->dateAttribute($billable, 'plan_current_period_end'),
            // This one the local row does know: `ends_at` is Cashier's
            // cancellation-effective date, so a row that carries one will not
            // roll over.
            renews: $this->dateAttribute($subscription, 'ends_at') === null,
        );
    }

    /**
     * Whether a claim says exactly what the row already says.
     *
     * Compared through the same five fields {@see self::snapshot()} projects, so
     * that "agrees" and "was corrected" cannot mean two different things.
     */
    protected function agreesWithRecord(Model $billable, EntitlementWrite $claim): bool
    {
        return $this->snapshot($billable) === [
            'plan' => $claim->plan,
            'plan_status' => $claim->status->value,
            'plan_provider' => $claim->provider->value,
            'plan_current_period_end' => $claim->currentPeriodEnd?->toIso8601ZuluString(),
            'plan_renews' => $claim->renews,
        ];
    }

    /**
     * The entitlement's MEANING, as five comparable fields.
     *
     * Deliberately not the whole row. `plan_source_event_at` and
     * `plan_provider_status` are provenance and move on every store-rail read,
     * so including them would report a correction on every run; `plan_manage_url`
     * and `plan_product_id` are debug and navigation. What is left is what a
     * customer would notice: the tier, where it stands, who is billing it, when
     * the period ends and whether it rolls over. A dropped `RENEWAL` moves only
     * the last two, which is why they are in here rather than assumed harmless.
     *
     * Timestamps are compared as UTC ISO-8601 strings, which is also what the
     * log prints, so the field that reported a correction is the field that was
     * compared.
     *
     * EVERY field goes through the shared decoders, and three of the five are
     * the reason this method is the most dangerous one in the file. The package
     * ships these columns and not the model that casts them, so on an uncast
     * model `plan` read as an enum answers null with a warning,
     * `plan_current_period_end` read with `?->toIso8601ZuluString()` is a fatal
     * Error, and `plan_renews` read raw compares `1 !== true` and disagrees with
     * the column forever. The third is the quiet one: it makes
     * {@see self::agreesWithRecord()} answer false on a subject that agrees
     * perfectly, so every scheduled run re-applies the same claim and stamps this
     * run's provenance over a genuine event's. That is precisely the restamp loop
     * that method exists to shut.
     *
     * `plan` is the RAW column and never a reader that answers a free-tier word
     * over a NULL one, and that is convergence rather than taste. A revocation
     * claim carries no tier, so comparing through such a reader would report a
     * disagreement the write had already resolved, forever.
     *
     * @return array<string, mixed>
     */
    protected function snapshot(Model $billable): array
    {
        return [
            'plan' => $this->stringAttribute($billable, 'plan'),
            'plan_status' => PlanStatus::fromWire($this->stringAttribute($billable, 'plan_status'))->value,
            'plan_provider' => BillingProvider::fromWire($this->stringAttribute($billable, 'plan_provider'))->value,
            'plan_current_period_end' => $this->dateAttribute($billable, 'plan_current_period_end')
                ?->toIso8601ZuluString(),
            'plan_renews' => $this->booleanAttribute($billable, 'plan_renews'),
        ];
    }

    /**
     * Report a correction, which is always a delivery that was missed.
     *
     * Warning level, and the wording says the cause rather than the symptom: an
     * entitlement that needed correcting means a webhook the application was
     * relying on never landed, and that is worth an operator's attention even
     * though the customer is now on the right tier. The `changed` list is what
     * separates a tier correction from a period correction at a glance.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<int, string>  $changed
     */
    protected function reportCorrection(
        Model $billable,
        BillingProvider $provider,
        array $before,
        array $after,
        array $changed,
    ): void {
        Log::warning('Billing entitlement corrected by the reconciler; a rail delivery was missed.', [
            'reason' => 'entitlement_corrected',
            'billable_id' => $billable->getKey(),
            'rail' => $provider->isStore() ? 'store' : 'stripe',
            'provider' => $provider->value,
            'changed' => $changed,
            'before' => $before,
            'after' => $after,
        ]);
    }

    /**
     * Report a rail that was read and had nothing it could decide.
     *
     * Warning rather than info for the same reason the write path logs its drops
     * that way: each case means a paying customer's entitlement could not be
     * verified, and the Stripe cases are all states an operator can fix (fill in
     * the price map, look at why a subject on the Stripe rail has no subscription
     * row, or apply Cashier's trait to the billable model).
     *
     * @param  array<string, mixed>  $context
     */
    protected function skip(Model $billable, string $reason, string $rail, array $context = []): void
    {
        Log::warning('A billing rail had nothing to decide; entitlement left untouched.', [
            'reason' => $reason,
            'billable_id' => $billable->getKey(),
            'rail' => $rail,
            ...$context,
        ]);
    }
}
