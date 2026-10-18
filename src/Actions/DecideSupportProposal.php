<?php

namespace FlutterSdk\MagicStarter\Actions;

use Carbon\CarbonInterface;
use Closure;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Contracts\DecidesSupportProposals;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportProposalAction;
use FlutterSdk\MagicStarter\Enums\SupportProposalStatus;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationUpdated;
use FlutterSdk\MagicStarter\Filament\Support\BillingAuthorization;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportProposal;
use FlutterSdk\MagicStarter\Support\BillingAdministrationRefused;
use FlutterSdk\MagicStarter\Support\StripeBillingState;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportHandoff;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportProposalBillable;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportProposalTrialWindow;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiConnectionException;

/**
 * Decides a support proposal for a staff member of the admin panel.
 *
 * Approval re-derives everything the request depended on (the account, its
 * owner, the trial window) under a row lock and claims the row as `executing`
 * in one short transaction, then calls {@see AdministersBilling} with nothing
 * open: the rail call and the `billing_events` rows it writes commit on their
 * own, and a crash leaves the row `executing` for staff to confirm by hand
 * rather than a second approval charging twice. A Stripe call that never got an
 * answer is treated the same way: it may have applied, so the row stays
 * `executing` until staff check the billing events and mark it applied or
 * failed. A refusal found under the lock is returned from the transaction,
 * never thrown inside it, so the status it wrote (expired, failed) commits
 * before the ValidationException leaves.
 */
class DecideSupportProposal implements DecidesSupportProposals
{
    public function __construct(
        private readonly AdministersBilling $billing,
        private readonly SupportHandoff $handoff,
    ) {}

    public function approve(Authenticatable $staff, SupportProposal $proposal, Panel $panel): void
    {
        // 1. Only a billing-cleared member of this panel decides.
        $this->authorize($staff, $panel);

        // 2. The billing call must commit on its own; inside the panel's transaction a rollback would hide it.
        if (DB::transactionLevel() > 0) {
            throw $this->refusal('in_transaction');
        }

        // 3. Claim the row, or commit the reason it cannot run.
        $claim = DB::transaction(fn (): Closure|string => $this->claim($staff, $proposal));

        $proposal->refresh();

        if (is_string($claim)) {
            throw $this->refusal($claim);
        }

        // 4. Run the change with no transaction open. Any other failure, and a refusal whose outcome
        //    is unknown, leaves the row executing for staff to check.
        try {
            $claim();
        } catch (BillingAdministrationRefused $refusal) {
            if (! $this->outcomeUnknown($refusal)) {
                $this->settle($proposal, SupportProposalStatus::FAILED, $refusal->reason(), 'failed');
            }

            throw $refusal;
        }

        // 5. Record the outcome and tell the customer.
        $this->settle($proposal, SupportProposalStatus::APPROVED, null, 'approved');
    }

    public function reject(Authenticatable $staff, SupportProposal $proposal, Panel $panel, string $reason): void
    {
        // 1. Only a billing-cleared member of this panel decides.
        $this->authorize($staff, $panel);

        // 2. Decline a row still pending before its deadline; a lapsed one is expired instead, as approval does.
        $refusal = DB::transaction(function () use ($staff, $proposal, $reason): ?string {
            $locked = $this->lock($proposal);

            if ($locked?->status !== SupportProposalStatus::PENDING) {
                return 'already_decided';
            }

            if ($locked->expires_at->lte(now())) {
                $locked->forceFill([
                    'status' => SupportProposalStatus::EXPIRED,
                ])->save();

                return 'expired';
            }

            $locked->forceFill([
                'status' => SupportProposalStatus::REJECTED,
                'decided_by' => $staff->getAuthIdentifier(),
                'decided_at' => now(),
                'decision_reason' => $reason,
            ])->save();

            $this->tellCustomer($locked, 'declined');

            return null;
        });

        $proposal->refresh();

        // 3. Refuse with what the lock found, or push the declined line to the thread.
        if ($refusal !== null) {
            throw $this->refusal($refusal);
        }

        $this->refreshThread($proposal);
    }

    public function markFailed(Authenticatable $staff, SupportProposal $proposal, Panel $panel, string $reason): void
    {
        // 1. Only a billing-cleared member of this panel decides.
        $this->authorize($staff, $panel);

        // 2. Close the running approval as failed, recorded as this staff member's decision; nobody is told.
        $closed = $this->closeExecuting($staff, $proposal, SupportProposalStatus::FAILED, $reason, null);

        // 3. Refuse a row that is not executing.
        if (! $closed) {
            throw $this->refusal('not_executing');
        }
    }

    public function markApplied(Authenticatable $staff, SupportProposal $proposal, Panel $panel, string $reason): void
    {
        // 1. Only a billing-cleared member of this panel decides.
        $this->authorize($staff, $panel);

        // 2. Close the running approval as approved, as this staff member, and tell the customer it applied.
        $closed = $this->closeExecuting($staff, $proposal, SupportProposalStatus::APPROVED, $reason, 'approved');

        // 3. Refuse a row that is not executing, or push the approved line to the thread.
        if (! $closed) {
            throw $this->refusal('not_executing');
        }

        $this->refreshThread($proposal);
    }

    /**
     * Whether a refusal leaves it unknown if the change applied: a Stripe call
     * that could not connect or never answered may still have gone through.
     */
    private function outcomeUnknown(BillingAdministrationRefused $refusal): bool
    {
        return $refusal->reason() === 'rail_error' && $refusal->getPrevious() instanceof ApiConnectionException;
    }

    /**
     * Under the row lock, close an `executing` row by hand as `$status`, with
     * `$staff` as the final decider, and post `$line` to the customer when one
     * is given. Returns false, writing nothing, when the row is not executing.
     *
     * @param  string|null  $line  A key of `support.proposals`, or null to tell the customer nothing.
     */
    private function closeExecuting(
        Authenticatable $staff,
        SupportProposal $proposal,
        SupportProposalStatus $status,
        string $reason,
        ?string $line,
    ): bool {
        $closed = DB::transaction(function () use ($staff, $proposal, $status, $reason, $line): bool {
            $locked = $this->lock($proposal);

            if ($locked?->status !== SupportProposalStatus::EXECUTING) {
                return false;
            }

            $locked->forceFill([
                'status' => $status,
                'decided_by' => $staff->getAuthIdentifier(),
                'decided_at' => now(),
                'decision_reason' => $reason,
            ])->save();

            if ($line !== null) {
                $this->tellCustomer($locked, $line);
            }

            return true;
        });

        $proposal->refresh();

        return $closed;
    }

    /**
     * Under the row lock, either claim the proposal as `executing` and hand back
     * the billing call it cleared, or write why it cannot run and return that
     * refusal key.
     *
     * Runs inside a transaction and never throws for a refusal, so the expired
     * or failed status it writes commits.
     *
     * @return Closure(): void|string
     */
    private function claim(Authenticatable $staff, SupportProposal $proposal): Closure|string
    {
        // 1. One decision per row: a second approval finds it no longer pending.
        $locked = $this->lock($proposal);

        if ($locked?->status !== SupportProposalStatus::PENDING) {
            return 'already_decided';
        }

        if ($locked->expires_at->lte(now())) {
            $locked->forceFill([
                'status' => SupportProposalStatus::EXPIRED,
            ])->save();

            return 'expired';
        }

        // 2. The account as it is now, decided the way recording decided it.
        $conversation = SupportConversation::query()->find($locked->conversation_id);
        $billable = $conversation === null ? null : SupportProposalBillable::for($conversation);

        if ($conversation === null || $billable === null) {
            return $this->fail($locked, $staff, 'no_billable');
        }

        if (
            $billable->getMorphClass() !== $locked->billable_type
            || (string) $billable->getKey() !== (string) $locked->billable_id
        ) {
            return $this->fail($locked, $staff, 'billable_changed');
        }

        // 3. Only the owner may ask for a billing change, and the opener must still be it.
        $opener = SupportProposalBillable::opener($conversation);

        if ($opener === null || ! Gate::forUser($opener)->allows('manageBilling', $billable)) {
            return $this->fail($locked, $staff, 'owner_changed');
        }

        // 4. The requested trial day must still fit the trial as it stands.
        $call = $this->billingCall($staff, $locked, $billable);

        if ($call === null) {
            return $this->fail($locked, $staff, 'params_stale');
        }

        // 5. Claim it: from here only this approval may run the change.
        $locked->forceFill([
            'status' => SupportProposalStatus::EXECUTING,
            'decided_by' => $staff->getAuthIdentifier(),
            'decided_at' => now(),
        ])->save();

        return $call;
    }

    /**
     * The billing call the proposal's action stands for, or null when a trial
     * extension's day no longer fits the current trial.
     *
     * @return (Closure(): void)|null
     */
    private function billingCall(Authenticatable $staff, SupportProposal $proposal, Model $billable): ?Closure
    {
        if ($proposal->action === SupportProposalAction::CANCEL_AT_PERIOD_END) {
            return function () use ($staff, $billable): void {
                $this->billing->cancel($staff, $billable);
            };
        }

        if ($proposal->action === SupportProposalAction::RESUME) {
            return function () use ($staff, $billable): void {
                $this->billing->resume($staff, $billable);
            };
        }

        $until = $this->trialDay($billable, $proposal->params['until'] ?? null);

        if ($until === null) {
            return null;
        }

        return function () use ($staff, $billable, $until): void {
            $this->billing->extendTrial($staff, $billable, $until);
        };
    }

    /**
     * The day the trial may move to, or null when it is malformed or no longer
     * fits the window recording enforced ({@see SupportProposalTrialWindow}).
     */
    private function trialDay(Model $billable, mixed $value): ?CarbonInterface
    {
        $until = SupportProposalTrialWindow::day($value);
        $trialEndsAt = StripeBillingState::defaultSubscription($billable)?->getAttribute('trial_ends_at');

        if ($until === null || ! $trialEndsAt instanceof CarbonInterface) {
            return null;
        }

        return SupportProposalTrialWindow::fits($until, $trialEndsAt) ? $until : null;
    }

    /**
     * Mark the locked row failed by the deciding staff member and return the refusal key.
     */
    private function fail(SupportProposal $locked, Authenticatable $staff, string $reason): string
    {
        $locked->forceFill([
            'status' => SupportProposalStatus::FAILED,
            'decided_by' => $staff->getAuthIdentifier(),
            'decided_at' => now(),
            'decision_reason' => $reason,
        ])->save();

        return $reason;
    }

    /**
     * Write an approval's final status and the customer's line together, after the billing call returned.
     *
     * The row is re-read under its lock and written only while it is still
     * `executing`: another staff member may have marked it applied or failed
     * while the billing call ran, and that decision stands, with no second line.
     *
     * @param  string  $line  A key of `support.proposals`: `approved` or `failed`.
     */
    private function settle(
        SupportProposal $proposal,
        SupportProposalStatus $status,
        ?string $reason,
        string $line,
    ): void {
        $settled = DB::transaction(function () use ($proposal, $status, $reason, $line): bool {
            $locked = $this->lock($proposal);

            if ($locked?->status !== SupportProposalStatus::EXECUTING) {
                return false;
            }

            $locked->forceFill([
                'status' => $status,
                'decision_reason' => $reason,
            ])->save();

            $this->tellCustomer($locked, $line);

            return true;
        });

        $proposal->refresh();

        if ($settled) {
            $this->refreshThread($proposal);
        }
    }

    /**
     * Push the new line to an open thread once it is committed, the way a
     * handoff does; a broadcast outage never undoes a decision already stored.
     */
    private function refreshThread(SupportProposal $proposal): void
    {
        $conversation = $proposal->conversation;

        rescue(static fn () => event(new SupportConversationUpdated($conversation)), report: true);
    }

    /**
     * Post the decision into the thread as a system activity line, in the
     * conversation's language rather than the deciding staff member's.
     *
     * @param  string  $line  A key of `support.proposals`: `approved`, `declined` or `failed`.
     */
    private function tellCustomer(SupportProposal $proposal, string $line): void
    {
        $conversation = $proposal->conversation;
        $locale = $this->handoff->locale($conversation);
        $action = $proposal->action->label($locale);

        $conversation->messages()->create([
            'sender_type' => SupportSenderType::SYSTEM,
            'sender_id' => null,
            'kind' => SupportMessageKind::ACTIVITY,
            'body' => Lang::get('magic-starter::support.proposals.' . $line, ['action' => $action], $locale),
        ]);

        $conversation->forceFill([
            'last_activity_at' => now(),
        ])->save();
    }

    /**
     * The proposal's row re-read under `FOR UPDATE`, or null once it is gone.
     */
    private function lock(SupportProposal $proposal): ?SupportProposal
    {
        return SupportProposal::query()
            ->whereKey($proposal->getKey())
            ->lockForUpdate()
            ->first();
    }

    /**
     * Refuse unless `$staff` may enter `$panel` and run billing actions there, by that panel's own rule.
     *
     * @throws ValidationException
     */
    private function authorize(Authenticatable $staff, Panel $panel): void
    {
        if (
            $staff instanceof FilamentUser
            && $staff->canAccessPanel($panel)
            && BillingAuthorization::allows($staff, $panel)
        ) {
            return;
        }

        throw $this->refusal('unauthorized');
    }

    /**
     * The refusal the panel shows, in the deciding staff member's language.
     */
    private function refusal(string $key): ValidationException
    {
        return ValidationException::withMessages([
            'proposal' => __('magic-starter::admin_support.proposals.refusals.' . $key),
        ]);
    }
}
