<?php

namespace FlutterSdk\MagicStarter\Jobs;

use FlutterSdk\MagicStarter\Console\ReconcileBillingEntitlements;
use FlutterSdk\MagicStarter\Http\Controllers\StripeWebhookController;
use FlutterSdk\MagicStarter\Models\BillingTrial;
use FlutterSdk\MagicStarter\Notifications\TrialRefusedNotification;
use FlutterSdk\MagicStarter\Support\StripeSubscriptionState;
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
use Throwable;

/**
 * Checks the card behind one recorded trial, once, and refuses the later of any
 * trials that share a person, a billed subject or a card.
 *
 * Queued by {@see StripeWebhookController} after the transaction that recorded
 * the `billing_trials` row commits, because the card read is a Stripe round
 * trip and the webhook transaction must make none. Re-dispatched by
 * {@see ReconcileBillingEntitlements} for a row still unchecked half an hour
 * later, which covers an after-commit dispatch that was lost.
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
 * `array` every server locks for itself. The only Stripe call made while it is
 * held is the cancel of a row whose refusal is ALREADY written, so a Stripe
 * webhook racing the cancel always finds the refusal.
 *
 * ## What a refusal does
 *
 * - The refusal (`refused_at`, `refusal_reason`) is written before the cancel.
 *   `checked_at` stays null until the cancel is confirmed, so a refused row
 *   with no `checked_at` is a cancel still owed, and a retry of any job whose
 *   set reaches it finishes it instead of skipping it.
 * - Only a subscription whose local Cashier row still says `trialing` is
 *   refused. A later duplicate that already converted to paid (or ended) is
 *   only stamped checked and kept: this check refuses trials, never customers.
 * - A refused subscription on the SAME subject as a surviving one loses its
 *   local Cashier row and items, as Cashier does itself on `incomplete_expired`.
 *   Left in place it would be the subject's newest `default` row, and
 *   `subscription('default')` would answer the cancelled one to every swap,
 *   cancel, trial date and reconciler read after it.
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
 * 1, so this job never retries itself there: the scheduled `billing:reconcile`
 * sweep, which re-dispatches every live row still unchecked after 30 minutes,
 * is what retries it.
 */
class CheckTrialCard implements ShouldQueue
{
    use Queueable;

    /**
     * The one lock every trial decision is taken under.
     */
    public const LOCK = 'magic-starter:billing-trials';

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
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException When another decision holds the
     *                                                          lock past ten seconds; the retry takes it again.
     */
    public function handle(TrialCardGateway $gateway): void
    {
        // 1. Re-read the row. A checked row was decided; a deleted one is moot.
        $trial = BillingTrial::query()->find($this->trialId);

        if (! $trial instanceof BillingTrial || $trial->checked_at !== null) {
            return;
        }

        // 2. A live row needs its card first, read outside the lock. A refused
        //    row with no `checked_at` is a cancel still owed and goes straight
        //    to the decision, which finishes it.
        if ($trial->refused_at === null && ! $this->readCard($trial, $gateway)) {
            return;
        }

        // 3. Decide under the lock; cancels of refused rows happen in here.
        /** @var list<BillingTrial> $cardReused */
        $cardReused = Cache::lock(self::LOCK, 30)->block(10, fn (): array => $this->decide($trial, $gateway));

        // 4. Tell the people whose card had already trialed, outside the lock.
        $this->notifyRefused($cardReused);
    }

    /**
     * Say loudly that a check gave up, because it may have given up owing a
     * cancel: a refused row whose `checked_at` never landed is a trial Stripe
     * may still be running.
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
     * job was released, or kept the trial on its last attempt), or a method
     * with no card fingerprint (the trial is kept and stamped).
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
     * Ask again in a minute, or keep the trial on the last attempt.
     */
    protected function awaitPaymentMethod(BillingTrial $trial): void
    {
        if ($this->attempts() < $this->tries) {
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
     * this run, which are the ones owed a mail.
     *
     * @return list<BillingTrial>
     */
    protected function decide(BillingTrial $trial, TrialCardGateway $gateway): array
    {
        // 1. Re-read inside the lock: another job may have decided this row
        //    while its card was being read.
        $fresh = $trial->fresh();

        if (! $fresh instanceof BillingTrial || $fresh->checked_at !== null) {
            return [];
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

        // 3. Cancel every refusal still owed, this run's and any an earlier
        //    run wrote and could not finish.
        $cardReused = [];

        foreach ($owed as $row) {
            if ($this->finishRefusal($row, $kept, $gateway) && $row->refusal_reason === 'card_reused') {
                $cardReused[] = $row;
            }
        }

        // 4. This row is decided once its conflicts are. Re-read, because the
        //    walk above held its own copy: a refusal of this row was stamped
        //    when its cancel landed, and a kept one may already be stamped.
        $fresh->refresh();

        if ($fresh->refused_at === null && $fresh->checked_at === null) {
            $this->stamp($fresh);
        }

        return $cardReused;
    }

    /**
     * This row, every live row sharing its person, subject or card, and every
     * refused row among those still owed a cancel, earliest first.
     *
     * One hop from this row and no further. A chain (A shares a card with B,
     * B a person with C) is settled by the jobs of the rows in it, each of
     * which looks at its own neighbours.
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
    protected function refusalReason(BillingTrial $row, array $conflicts): string
    {
        foreach ($conflicts as $survivor) {
            if ($this->samePerson($row, $survivor) || $this->sameSubject($row, $survivor)) {
                return 'duplicate';
            }
        }

        return 'card_reused';
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
     * Whether the subscription behind a row is still a trial, read from the
     * local Cashier row the webhooks keep in step.
     *
     * No row is not a trial. Nothing that is not a trial is ever refused or
     * cancelled here.
     */
    protected function stillTrialing(BillingTrial $row): bool
    {
        return $this->localSubscription($row)?->getAttribute('stripe_status') === 'trialing';
    }

    /**
     * Write the refusal, BEFORE anything reaches Stripe.
     *
     * `checked_at` is cleared with it: a row kept by an earlier run had it
     * stamped, and until the cancel is confirmed this row is a cancel owed,
     * not a decided one.
     */
    protected function refuse(BillingTrial $row, string $reason): void
    {
        $row->forceFill([
            'refused_at' => Carbon::now(),
            'refusal_reason' => $reason,
            'checked_at' => null,
        ])->save();

        Log::warning('A trial was refused: an earlier trial shares its person, subject or card.', [
            'reason' => 'trial_refused',
            'refusal_reason' => $reason,
            'billing_trial_id' => $row->getKey(),
            'subscription' => $row->stripe_subscription_id,
        ]);
    }

    /**
     * Cancel a refused row's subscription, drop its local Cashier row when it
     * shares a subject with a survivor, and stamp the row decided.
     *
     * A local row that converted to a granting status (paid, or in dunning)
     * while the cancel was owed is NOT cancelled, because this check must never cancel a customer. Its refusal
     * is withdrawn rather than left on record: a refused row makes the webhook
     * skip every `customer.subscription.updated` for the subscription, which
     * on a paying one would freeze its local row and its entitlement. The case
     * is logged as an error, since it means a cancel failed for longer than the
     * trial lasted.
     *
     * Returns whether the refusal stands (the subscription is cancelled).
     *
     * @param  list<BillingTrial>  $kept  The survivors of this decision.
     */
    protected function finishRefusal(BillingTrial $row, array $kept, TrialCardGateway $gateway): bool
    {
        $local = $this->localSubscription($row);

        $status = $local?->getAttribute('stripe_status');

        // 1. Cancel only what is still a trial. A missing local row was
        //    deleted after a confirmed cancel by a run that stopped short of
        //    the stamp, and an ended one needs no cancel, so neither calls
        //    Stripe; a paying one withdraws the refusal instead.
        if ($status === 'trialing') {
            $gateway->cancel($row->stripe_subscription_id);
        } elseif (is_string($status) && StripeSubscriptionState::grants($status)) {
            Log::error('A refused trial converted before its cancel landed; the refusal was withdrawn.', [
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

            return false;
        }

        $sharesSubject = array_filter(
            $kept,
            fn (BillingTrial $survivor): bool => $this->sameSubject($row, $survivor),
        ) !== [];

        // 2. Drop the local row and stamp the refusal finished together, so a
        //    retry never sees one without the other.
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
