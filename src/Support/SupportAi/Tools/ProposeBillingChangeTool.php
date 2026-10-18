<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi\Tools;

use FlutterSdk\MagicStarter\Actions\RecordSupportProposal;
use FlutterSdk\MagicStarter\Contracts\RecordsSupportProposals;
use FlutterSdk\MagicStarter\Contracts\SupportTool;
use FlutterSdk\MagicStarter\Enums\SupportProposalAction;

/**
 * Lets the support AI ask for a change to the customer's subscription: a trial
 * extension, a cancellation at the period's end, or lifting such a cancellation.
 *
 * It only records the request through {@see RecordsSupportProposals}; a staff
 * member approves or rejects it in the panel, and only that approval changes
 * anything. The account and the owner check come from the conversation, never
 * from an argument, so the model can name what to ask for but not for whom.
 */
final class ProposeBillingChangeTool implements SupportTool
{
    public function __construct(
        private readonly RecordsSupportProposals $proposals,
    ) {}

    public function name(): string
    {
        return 'propose_billing_change';
    }

    public function description(): string
    {
        return 'Records the customer\'s request to change their subscription (extend the trial, cancel at the '
            . 'end of the period, or resume a scheduled cancellation) for a staff member to approve. It never '
            . 'applies the change: tell the customer a person will review it. Only the account owner may ask.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => array_column(SupportProposalAction::cases(), 'value'),
                    'description' => 'The change the customer asked for.',
                ],
                'until' => [
                    'type' => 'string',
                    'maxLength' => 10,
                    'description' => 'YYYY-MM-DD, only for extend_trial',
                ],
                'reason' => [
                    'type' => 'string',
                    'maxLength' => RecordSupportProposal::RATIONALE_MAX_LENGTH,
                    'description' => 'why the customer asked, in your words',
                ],
            ],
            'required' => ['action'],
        ];
    }

    /**
     * The trigger is the build-time conversation's `last_customer_message_id`,
     * never a fresh read, so a customer message written while the turn runs
     * does not become the message this turn's proposal answers.
     */
    public function handle(SupportToolContext $context, array $arguments): string
    {
        $conversation = $context->conversation();

        if ($conversation === null) {
            return 'There is no conversation to record a request on; tell the customer a teammate will help.';
        }

        $action = is_string($arguments['action'] ?? null)
            ? SupportProposalAction::tryFrom($arguments['action'])
            : null;

        if ($action === null) {
            return 'That is not a change I can request. The choices are extend_trial, '
                . 'cancel_at_period_end and resume.';
        }

        return $this->proposals->record(
            $conversation,
            $action,
            array_key_exists('until', $arguments) ? ['until' => $arguments['until']] : [],
            is_string($arguments['reason'] ?? null) ? $arguments['reason'] : null,
            $conversation->last_customer_message_id,
        );
    }
}
