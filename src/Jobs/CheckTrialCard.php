<?php

namespace FlutterSdk\MagicStarter\Jobs;

use FlutterSdk\MagicStarter\Console\ReconcileBillingEntitlements;
use FlutterSdk\MagicStarter\Enums\TrialRefusalReason;
use FlutterSdk\MagicStarter\Http\Controllers\StripeWebhookController;
use FlutterSdk\MagicStarter\Models\BillingTrial;
use FlutterSdk\MagicStarter\Notifications\TrialRefusedNotification;
use FlutterSdk\MagicStarter\Support\TrialCardGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Cashier;
use Stripe\Subscription as StripeSubscription;
use Throwable;

/**
 * Checks the card behind one recorded trial, once, and refuses the later of any
 * trials that share a person, a billed subject or a card.
 *
 * Queued by {@see StripeWebhookController} after the transaction that recorded
 * the `billing_trials` row commits, because the card read is a Stripe round
 * trip and the webhook transaction must make none. Re-dispatched by
 * {@see ReconcileBillingEntitlements} for any row still unchecked half an hour
 * later, refused or not, which covers an after-commit dispatch that was lost
 * and a cancel that failed on the job's last attempt. That sweep runs on
 * `billing:reconcile`'s own cadence (`magic-starter.billing.reconcile.cadence`,
 * daily by default), so an application selling trials should set it `hourly`.
 *
 * ## Earliest wins
 *
 * Within a set of conflicting trials the one Stripe created FIRST survives,
 * decided on `subscription_created_at` (Stripe's `created`) and then the row
 * key, never on which job happened to run first. Two checkouts opened seconds
 * apart queue two jobs a worker may run in either order, and a rule keyed on
 * the job would refuse the legitimate first trial half the time. A symmetric
 * rule ("refuse whatever matches") would refuse both.
 *
 * The decision runs under one cache lock for every trial
 * (`magic-starter:billing-trials`), so two jobs never decide overlapping sets
 * at once. The lock is only fleet-wide on a shared cache store; on `file` or
 * `array` every server locks for itself. The only Stripe calls made while it is
 * held are the live status read and the cancel of a row whose refusal is
 * ALREADY written, so a Stripe webhook racing the cancel always finds the
 * refusal.
 *
 * Known limitation: the set is one hop from this row. A chain (A shares a card
 * with B, B a person with C) is settled by the jobs of the rows in it, each of
 * which looks at its own neighbours, so C is compared with B and not with A.
 *
 * ## What a refusal does
 *
 * - The refusal (`refused_at`, `refusal_reason`) is written before the cancel.
 *   `checked_at` stays null until the cancel is confirmed, so a refused row
 *   with no `checked_at` is a cancel still owed, and a retry of any job whose
 *   set reaches it finishes it instead of skipping it. While it is owed the
 *   webhook keeps applying the subscription's updates, so its local row stays
 *   true.
 * - Only a subscription whose local Cashier row still says `trialing` is
 *   refused, and it is only cancelled while Stripe's LIVE status says
 *   `trialing` too. One that converted (paid, or in dunning) has its refusal
 *   withdrawn, and one Stripe already ended is stamped without a cancel: this
 *   check refuses trials, never customers.
 * - A refused subscription on the SAME subject as a surviving one loses its
 *   local Cashier row and items, as Cashier does itself on `incomplete_expired`.
 *   Left in place it would be the subject's newest `default` row, and
 *   `subscription('default')` would answer the cancelled one to every swap,
 *   cancel, trial date and reconciler read after it. The subject's entitlement
 *   is then re-projected from the survivor, because the refused duplicate's own
 *   created event wrote its tier and product and nothing later writes them back.
 * - The person is mailed ({@see TrialRefusedNotification}) only for
 *   `card_reused`, only while `magic-starter.billing.trial_refused_notification`
 *   is on, and only after the cancel was confirmed and stamped, so a mail
 *   failure can never cause a second cancel.
 *
 * ## Retries, and the `sync` queue
 *
 * A subscription whose card Checkout has not attached yet is asked again a
 * minute later through `release(60)`. Laravel counts a release as an attempt
 * and fails a job past `$tries` BEFORE `handle()` runs, so on the last attempt
 * the job keeps the trial and logs instead of releasing into a failure.
 *
 * On the `sync` queue `release()` requeues nothing and `attempts()` is always
 * 1, so this job never retries itself there and the attempt count never runs
 * out: the `billing:reconcile` sweep is what retries it, and a row recorded
 * more than six hours ago is given up on whatever the attempt, keeping the
 * trial, so the sweep does not re-dispatch it forever.
 */
class CheckTrialCard implements ShouldQueue
{
    use Queueable;

    /**
     * The one lock every trial decision is taken under.
     */
    public const LOCK = 'magic-starter:billing-trials';

    /**
     * Seconds the lock is held at most: long enough for a set's status reads
     * and cancels, each a Stripe round trip, to finish under it.
     */
    public const LOCK_SECONDS = 120;

    /**
     * Hours after the row was recorded past which a missing payment method is
     * given up on, whatever the attempt.
     */
    public const GIVE_UP_AFTER_HOURS = 6;

    /**
     * Stripe statuses that need no cancel: the subscription is already over.
     */
    protected const ENDED_STATUSES = [
        StripeSubscription::STATUS_CANCELED,
        StripeSubscription::STATUS_INCOMPLETE_EXPIRED,
    ];

    /**
     * Attempts before the queue gives up, the last of which keeps the trial
     * rather than releasing.
     */
    public int $tries = 6;

    /**
     * Seconds before a failed attempt is retried.
     */
    public int $backoff = 60;

    /**
     * @param  int|string  $trialId  The `billing_trials` key; the row is re-read,
     *                               so a job that outlived its row does nothing.
     */
    public function __construct(
        public readonly int|string $trialId,
    ) {}

    /**
     * Read the trial's card and settle every conflict it is part of.
     *
     * @param  ReconcileBillingEntitlements  $reconciler  Whose Stripe arm re-projects a subject
     *                                                    that lost a refused duplicate.
     *
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException When another decision holds the
     *                                                          lock past ten seconds; the retry takes it again.
     */
    public function handle(TrialCardGateway $gateway, ReconcileBillingEntitlements $reconciler): void
    {
        // 1. Re-read the row. A checked row was decided; a deleted one is moot.
        $trial = BillingTrial::query()->find($this->trialId);

        if (! $trial instanceof BillingTrial || $trial->checked_at !== null) {
            return;
        }

        // 2. A live row needs its card first, read outside the lock, unless a
        //    run that then timed out on the lock already stored it. A refused
        //    row with no `checked_at` is a cancel still owed and goes straight
        //    to the decision, which finishes it.
        $needsCard = $trial->refused_at === null && $trial->card_fingerprint === null;

        if ($needsCard && ! $this->readCard($trial, $gateway)) {
            return;
        }

        // 3. Decide under the lock; status reads and cancels happen in here.
        /** @var array{card_reused: list<BillingTrial>, reproject: list<BillingTrial>} $outcome */
        $outcome = Cache::lock(self::LOCK, self::LOCK_SECONDS)
            ->block(10, fn (): array => $this->decide($trial, $gateway));

        // 4. Hand each subject that lost a same-subject duplicate back to its
        //    survivor, outside the lock: a local read and one write each.
        $this->reproject($outcome['reproject'], $reconciler);

        // 5. Tell the people whose card had already trialed, outside the lock.
        $this->notifyRefused($outcome['card_reused']);
    }

    /**
     * Say loudly that a check gave up, because it may have given up owing a
     * cancel: a refused row whose `checked_at` never landed is a trial Stripe
     * may still be running, until the sweep re-dispatches it.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('A trial card check failed; a refused trial may still be live in Stripe.', [
            'reason' => 'trial_card_check_failed',
            'billing_trial_id' => $this->trialId,
            'exception' => $exception !== null ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);
    }

    /**
     * Ask the gateway for the card and store its fingerprint.
     *
     * Returns false when there is nothing to decide: no payment method yet (the
     * job was released, or gave up keeping the trial), or a method with no card
     * fingerprint (the trial is kept and stamped).
     */
    protected function readCard(BillingTrial $trial, TrialCardGateway $gateway): bool
    {
        $answer = $gateway->fingerprintFor($trial->stripe_subscription_id);

        if ($answer === null) {
            $this->awaitPaymentMethod($trial);

            return false;
        }

        if ($answer === TrialCardGateway::NO_CARD) {
            Log::info('A trial has no card fingerprint to compare; the trial is kept.', [
                'reason' => 'trial_card_not_a_card',
                'billing_trial_id' => $trial->getKey(),
                'subscription' => $trial->stripe_subscription_id,
            ]);

            $this->stamp($trial);

            return false;
        }

        $trial->forceFill([
            'card_fingerprint' => $answer,
        ])->save();

        return true;
    }

    /**
     * Ask again in a minute, or keep the trial once the attempts ran out or
     * the row is older than {@see self::GIVE_UP_AFTER_HOURS}.
     *
     * The age is what ends the wait on the `sync` queue, where `attempts()` is
     * always 1 and the attempt count alone would never run out.
     */
    protected function awaitPaymentMethod(BillingTrial $trial): void
    {
        $tooOld = $trial->created_at?->lte(Carbon::now()->subHours(self::GIVE_UP_AFTER_HOURS)) ?? false;

        if ($this->attempts() < $this->tries && ! $tooOld) {
            $this->release($this->backoff);

            return;
        }

        Log::warning('A trial never showed a payment method; the trial is kept unchecked by card.', [
            'reason' => 'trial_card_never_attached',
            'billing_trial_id' => $trial->getKey(),
            'subscription' => $trial->stripe_subscription_id,
            'attempts' => $this->attempts(),
        ]);

        $this->stamp($trial);
    }

    /**
     * Settle every conflict this trial is part of. Runs under the lock.
     *
     * Returns the rows refused as `card_reused` whose cancel was confirmed in
     * this run, which are the ones owed a mail, and the refused rows that
     * shared a subject with a survivor, whose subject is re-projected.
     *
     * @return array{card_reused: list<BillingTrial>, reproject: list<BillingTrial>}
     */
    protected function decide(BillingTrial $trial, TrialCardGateway $gateway): array
    {
        $outcome = [
            'card_reused' => [],
            'reproject' => [],
        ];

        // 1. Re-read inside the lock: another job may have decided this row
        //    while its card was being read.
        $fresh = $trial->fresh();

        if (! $fresh instanceof BillingTrial || $fresh->checked_at !== null) {
            return $outcome;
        }

        // 2. Walk the set earliest first. A row conflicting with nothing kept
        //    so far is kept; a later one conflicting with a kept row is refused
        //    while it is still a trial, and kept once it is paying.
        $kept = [];
        $owed = [];

        foreach ($this->conflictSet($fresh) as $row) {
            if ($row->refused_at !== null) {
                $owed[] = $row;

                continue;
            }

            $conflicts = array_values(array_filter(
                $kept,
                fn (BillingTrial $survivor): bool => $this->conflicts($row, $survivor),
            ));

            if ($conflicts === []) {
                $kept[] = $row;

                continue;
            }

            if (! $this->stillTrialing($row)) {
                $this->stamp($row);
                $kept[] = $row;

                continue;
            }

            $this->refuse($row, $this->refusalReason($row, $conflicts));
            $owed[] = $row;
        }

        // 3. Finish every refusal still owed, this run's and any an earlier
        //    run wrote and could not finish.
        foreach ($owed as $row) {
            $sharesSubject = $this->sharesSubjectWithAny($row, $kept);

            if (! $this->finishRefusal($row, $sharesSubject, $gateway)) {
                continue;
            }

            if ($sharesSubject) {
                $outcome['reproject'][] = $row;
            }

            if ($row->refusal_reason === TrialRefusalReason::CARD_REUSED) {
                $outcome['card_reused'][] = $row;
            }
        }

        // 4. This row is decided once its conflicts are. Re-read, because the
        //    walk above held its own copy: a refusal of this row was stamped
        //    when its cancel landed, and a kept one may already be stamped.
        $fresh->refresh();

        if ($fresh->refused_at === null && $fresh->checked_at === null) {
            $this->stamp($fresh);
        }

        return $outcome;
    }

    /**
     * This row, every live row sharing its person, subject or card, and every
     * refused row among those still owed a cancel, earliest first.
     *
     * One hop from this row and no further; see the class docblock for the
     * chain this leaves to the other rows' jobs.
     *
     * @return Collection<int, BillingTrial>
     */
    protected function conflictSet(BillingTrial $trial): Collection
    {
        return BillingTrial::query()
            ->where(function (Builder $query) use ($trial): void {
                $query
                    ->whereKey($trial->getKey())
                    ->orWhere(function (Builder $related) use ($trial): void {
                        $related
                            ->where(fn (Builder $open): Builder => $open
                                ->whereNull('refused_at')
                                ->orWhereNull('checked_at'))
                            ->where(function (Builder $match) use ($trial): void {
                                $match->where(fn (Builder $subject): Builder => $subject
                                    ->where('billable_type', $trial->billable_type)
                                    ->where('billable_id', $trial->billable_id));

                                if ($trial->user_id !== null) {
                                    $match->orWhere('user_id', $trial->user_id);
                                }

                                if ($trial->card_fingerprint !== null) {
                                    $match->orWhere('card_fingerprint', $trial->card_fingerprint);
                                }
                            });
                    });
            })
            ->orderBy('subscription_created_at')
            ->orderBy($trial->getKeyName())
            ->get();
    }

    /**
     * Whether two trials share a person, a subject or a card.
     */
    protected function conflicts(BillingTrial $row, BillingTrial $survivor): bool
    {
        return $this->samePerson($row, $survivor)
            || $this->sameSubject($row, $survivor)
            || ($row->card_fingerprint !== null && $row->card_fingerprint === $survivor->card_fingerprint);
    }

    /**
     * `duplicate` when the row shares a person or a subject with a survivor,
     * `card_reused` when the card is the only thing they share.
     *
     * @param  list<BillingTrial>  $conflicts  Kept rows this row conflicts with.
     */
    protected function refusalReason(BillingTrial $row, array $conflicts): TrialRefusalReason
    {
        foreach ($conflicts as $survivor) {
            if ($this->samePerson($row, $survivor) || $this->sameSubject($row, $survivor)) {
                return TrialRefusalReason::DUPLICATE;
            }
        }

        return TrialRefusalReason::CARD_REUSED;
    }

    protected function samePerson(BillingTrial $row, BillingTrial $other): bool
    {
        return $row->user_id !== null && (string) $row->user_id === (string) $other->user_id;
    }

    protected function sameSubject(BillingTrial $row, BillingTrial $other): bool
    {
        return $row->billable_type === $other->billable_type
            && (string) $row->billable_id === (string) $other->billable_id;
    }

    /**
     * Whether a row bills the same subject as any of the survivors.
     *
     * @param  list<BillingTrial>  $kept
     */
    protected function sharesSubjectWithAny(BillingTrial $row, array $kept): bool
    {
        foreach ($kept as $survivor) {
            if ($this->sameSubject($row, $survivor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the subscription behind a row is still a trial, read from the
     * local Cashier row the webhooks keep in step.
     *
     * Enough to decide whether to REFUSE, never whether to cancel: the cancel
     * waits for Stripe's live word ({@see self::finishRefusal()}). No row is
     * not a trial.
     */
    protected function stillTrialing(BillingTrial $row): bool
    {
        return $this->localSubscription($row)?->getAttribute('stripe_status')
            === StripeSubscription::STATUS_TRIALING;
    }

    /**
     * Write the refusal, BEFORE anything reaches Stripe.
     *
     * `checked_at` is cleared with it: a row kept by an earlier run had it
     * stamped, and until the cancel is confirmed this row is a cancel owed,
     * not a decided one.
     */
    protected function refuse(BillingTrial $row, TrialRefusalReason $reason): void
    {
        $row->forceFill([
            'refused_at' => Carbon::now(),
            'refusal_reason' => $reason,
            'checked_at' => null,
        ])->save();

        Log::warning('A trial was refused: an earlier trial shares its person, subject or card.', [
            'reason' => 'trial_refused',
            'refusal_reason' => $reason->value,
            'billing_trial_id' => $row->getKey(),
            'subscription' => $row->stripe_subscription_id,
        ]);
    }

    /**
     * Cancel a refused row's subscription while Stripe says it is a trial,
     * drop its local Cashier row when it shares a subject with a survivor, and
     * stamp the row decided.
     *
     * Stripe's LIVE status decides, never the local row's, because the local
     * row is only as fresh as the last webhook that landed and a cancel owed
     * for longer than the trial may be owed on a subscription that is paying:
     *
     * - `trialing`: cancelled, then finished.
     * - already ended (`canceled`, `incomplete_expired`): finished, no cancel.
     * - anything else (paid, in dunning, or a state this check never started):
     *   the refusal is WITHDRAWN, never cancelled, and logged as an error,
     *   since it means a cancel failed for longer than the trial lasted. A
     *   refusal stamped finished instead would make the webhook skip every
     *   later update of a subscription somebody is paying for.
     *
     * Returns whether the refusal stands.
     *
     * @param  bool  $sharesSubject  Whether a survivor of this decision bills the same subject.
     */
    protected function finishRefusal(BillingTrial $row, bool $sharesSubject, TrialCardGateway $gateway): bool
    {
        // 1. Stripe's word, read once under the lock.
        $status = $gateway->status($row->stripe_subscription_id);

        if ($status === StripeSubscription::STATUS_TRIALING) {
            $gateway->cancel($row->stripe_subscription_id);
        } elseif (! in_array($status, self::ENDED_STATUSES, true)) {
            $this->withdrawRefusal($row, $status);

            return false;
        }

        // 2. Drop the local row and stamp the refusal finished in one
        //    transaction, so a retry never sees one without the other. The
        //    local row is read only for this delete; a missing one was never
        //    synced or was already deleted by Cashier, and there is nothing to
        //    drop.
        $local = $this->localSubscription($row);

        DB::transaction(function () use ($row, $local, $sharesSubject): void {
            if ($local !== null && $sharesSubject) {
                $local->items()->delete();
                $local->delete();
            }

            $this->stamp($row);
        });

        return true;
    }

    /**
     * Take a refusal back from a subscription Stripe no longer reports as a
     * trial, and mark the row decided as kept.
     */
    protected function withdrawRefusal(BillingTrial $row, string $status): void
    {
        Log::error('A refused trial is no longer trialing in Stripe; the refusal was withdrawn, nothing cancelled.', [
            'reason' => 'refused_trial_converted',
            'billing_trial_id' => $row->getKey(),
            'subscription' => $row->stripe_subscription_id,
            'stripe_status' => $status,
        ]);

        $row->forceFill([
            'refused_at' => null,
            'refusal_reason' => null,
            'checked_at' => Carbon::now(),
        ])->save();
    }

    /**
     * Re-project each subject that lost a same-subject duplicate from its
     * surviving local Cashier row.
     *
     * Through the reconciler's own Stripe arm for one subject rather than a
     * claim assembled here: it reads the subject's `default` row (now the
     * survivor), maps the price through the catalogue, writes through
     * {@see \FlutterSdk\MagicStarter\Contracts\WritesEntitlement} as a
     * projection, and only on a disagreement, so the ordering rules that
     * guard every other write guard this one too. It is a local read; nothing
     * here calls Stripe.
     *
     * @param  list<BillingTrial>  $refused
     */
    protected function reproject(array $refused, ReconcileBillingEntitlements $reconciler): void
    {
        $done = [];

        foreach ($refused as $row) {
            $subject = $row->billable_type . '|' . $row->billable_id;

            if (isset($done[$subject])) {
                continue;
            }

            $done[$subject] = true;
            $billable = $row->billable;

            if ($billable instanceof Model) {
                $reconciler->reconcileStripeSubject($billable);
            }
        }
    }

    /**
     * The local Cashier subscription row behind a trial, or null.
     *
     * @return (Model&\Laravel\Cashier\Subscription)|null
     */
    protected function localSubscription(BillingTrial $row): ?Model
    {
        /** @var class-string<\Laravel\Cashier\Subscription> $model */
        $model = Cashier::$subscriptionModel;

        return $model::query()->where('stripe_id', $row->stripe_subscription_id)->first();
    }

    /**
     * Mark a row decided.
     */
    protected function stamp(BillingTrial $row): void
    {
        $row->forceFill([
            'checked_at' => Carbon::now(),
        ])->save();
    }

    /**
     * Mail each person whose trial was refused because the card had trialed.
     *
     * @param  list<BillingTrial>  $refused
     */
    protected function notifyRefused(array $refused): void
    {
        if (! config('magic-starter.billing.trial_refused_notification', true)) {
            return;
        }

        foreach ($refused as $row) {
            $user = $row->user;

            if ($user === null) {
                continue;
            }

            Notification::send($user, new TrialRefusedNotification);
        }
    }
}
