<?php

namespace FlutterSdk\MagicStarter\Actions;

use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Contracts\RecordsSupportProposals;
use FlutterSdk\MagicStarter\Enums\SupportProposalAction;
use FlutterSdk\MagicStarter\Enums\SupportProposalStatus;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportProposal;
use FlutterSdk\MagicStarter\Support\StripeBillingState;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportProposalBillable;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportProposalTrialWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * Records the subscription change the account owner asked the support AI for,
 * as a `draft` row a staff member approves or rejects later.
 *
 * Nothing here touches the billing rail. The rail is read only to refuse a
 * request that could not run anyway, with the same predicates the operator's
 * actions apply ({@see AdministerBilling}), so staff are never shown a request
 * that approval would refuse on sight. The draft stays hidden until the turn
 * that wrote it is delivered ({@see SupportProposal::promoteDrafts()}), which
 * is why a draft of another trigger never blocks a new request: its turn may
 * never have landed.
 */
class RecordSupportProposal implements RecordsSupportProposals
{
    /** The longest rationale kept, matching the tool schema's `reason`. */
    public const RATIONALE_MAX_LENGTH = 500;

    private const RECORDED = 'Recorded. A teammate will review this request; '
        . 'tell the customer a person will confirm it here.';

    public function record(
        SupportConversation $conversation,
        SupportProposalAction $action,
        array $params,
        ?string $rationale,
        int|string|null $triggerMessageId,
    ): string {
        // 1. The flag needs the support desk and billing, which supplies the owner gate.
        if (! Features::hasSupportProposalFeatures()) {
            return 'Proposals are not available here; tell the customer a teammate will help.';
        }

        // 2. A visitor has no account a change could belong to.
        $opener = SupportProposalBillable::opener($conversation);

        if ($opener === null) {
            return 'Changes to a subscription need an account. Tell the customer to sign in '
                . 'and write in from the account the subscription belongs to.';
        }

        // 3. The opener may have left the team, or the team may be gone.
        $billable = SupportProposalBillable::for($conversation);

        if ($billable === null) {
            return 'There is no billing account on this conversation to change; '
                . 'tell the customer a teammate will help.';
        }

        // 4. Only the owner asks for a billing change, the same gate the billing endpoints apply.
        if (! Gate::forUser($opener)->allows('manageBilling', $billable)) {
            return 'Only the account owner can request this change. '
                . "Tell the customer to write in from the owner's account.";
        }

        // 5. Read the action's own input in its strict shape; extra keys are dropped.
        $until = null;

        if ($action === SupportProposalAction::EXTEND_TRIAL) {
            $until = SupportProposalTrialWindow::day($params['until'] ?? null);

            if ($until === null) {
                return 'Give the new trial end as a real calendar day written YYYY-MM-DD.';
            }
        }

        // 6. Refuse what the rail could not take, before staff ever see it.
        $subscription = StripeBillingState::defaultSubscription($billable);

        if (! $subscription instanceof CashierSubscription) {
            return 'There is no card subscription on this account to change; '
                . 'tell the customer a teammate will help.';
        }

        $refusal = $this->railRefusal($action, $subscription, $until);

        if ($refusal !== null) {
            return $refusal;
        }

        // 7. Write one draft per trigger, and none while one waits for a decision.
        return DB::transaction(function () use (
            $conversation,
            $action,
            $until,
            $rationale,
            $triggerMessageId,
            $billable,
            $opener,
        ): string {
            $locked = SupportConversation::query()
                ->whereKey($conversation->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return 'This conversation no longer exists, so nothing was recorded.';
            }

            // 7a. A retried turn reruns its tool calls; its own row answers it whatever it became.
            $retried = $locked->proposals()
                ->where('trigger_message_id', $triggerMessageId)
                ->where('action', $action->value)
                ->exists();

            if ($retried) {
                return self::RECORDED;
            }

            $waiting = $locked->proposals()
                ->where('action', $action->value)
                ->where('status', SupportProposalStatus::PENDING->value)
                ->where('expires_at', '>', now())
                ->exists();

            if ($waiting) {
                return 'A request for this is already waiting for a teammate.';
            }

            $locked->proposals()->create([
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
                'requested_by' => $opener->getKey(),
                'action' => $action,
                'params' => $until === null ? [] : ['until' => $until->format('Y-m-d')],
                'rationale' => $this->rationale($rationale),
                'trigger_message_id' => $triggerMessageId,
                'status' => SupportProposalStatus::DRAFT,
                'expires_at' => now()->addHours((int) config('magic-starter.support.proposals.expires_hours', 72)),
            ]);

            return self::RECORDED;
        });
    }

    /**
     * Why the subscription cannot take the action, or null when it can.
     *
     * Mirrors the operator's extendTrial, cancel and resume refusals, plus
     * the window a trial extension may move the end within.
     */
    private function railRefusal(
        SupportProposalAction $action,
        CashierSubscription $subscription,
        ?CarbonInterface $until,
    ): ?string {
        return match ($action) {
            SupportProposalAction::EXTEND_TRIAL => $this->extendTrialRefusal($subscription, $until),
            SupportProposalAction::CANCEL_AT_PERIOD_END => match (true) {
                $subscription->onGracePeriod(), $subscription->ended() => 'The subscription is already cancelled.',
                ! $subscription->active() => 'The subscription is not active, so there is nothing to cancel.',
                default => null,
            },
            SupportProposalAction::RESUME => $subscription->onGracePeriod()
                ? null
                : 'The subscription is not scheduled to be cancelled, so there is nothing to resume.',
        };
    }

    /**
     * Why the trial cannot move to `$until`, or null when it can.
     */
    private function extendTrialRefusal(CashierSubscription $subscription, ?CarbonInterface $until): ?string
    {
        if (! $subscription->onTrial()) {
            return 'The subscription is not on a trial, so there is nothing to extend.';
        }

        if ($subscription->onGracePeriod()) {
            return 'The subscription is already cancelled, so its trial cannot be extended.';
        }

        // onTrial() holds, so the trial end is set.
        $trialEndsAt = Carbon::instance($subscription->trial_ends_at);

        if ($until === null || ! SupportProposalTrialWindow::fits($until, $trialEndsAt)) {
            return sprintf(
                'The new trial end must be after the current one, %s, and at most %d days later.',
                $trialEndsAt->format('Y-m-d'),
                SupportProposalTrialWindow::maxDays(),
            );
        }

        return null;
    }

    /**
     * The rationale trimmed and cut to the schema's length, or null when blank.
     */
    private function rationale(?string $rationale): ?string
    {
        $trimmed = trim((string) $rationale);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, self::RATIONALE_MAX_LENGTH);
    }
}
