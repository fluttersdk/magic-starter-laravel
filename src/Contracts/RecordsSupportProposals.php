<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Enums\SupportProposalAction;
use FlutterSdk\MagicStarter\Models\SupportConversation;

/**
 * Contract for recording a change to a subscription that the customer asked
 * the support AI for, as a draft a staff member approves or rejects later.
 *
 * The customer who owns the billable asks; the AI only records the request;
 * a staff member decides it in the panel, and that approval is the only thing
 * that ever changes the subscription. An implementation therefore never
 * touches the billing rail: it reads the rail to refuse a request that could
 * not run, and writes one `draft` row.
 */
interface RecordsSupportProposals
{
    /**
     * Record the request, or refuse it, and say which in a sentence for the model.
     *
     * A refusal is a normal outcome (a member who is not the owner, a date out
     * of range, a subscription already cancelled), so it is returned rather
     * than thrown; only a genuine fault, such as a dead database, propagates.
     * Calling again for the same conversation, trigger and action answers the
     * success sentence without a second row, because a retried turn reruns its
     * tool calls.
     *
     * @param  array<string, mixed>  $params  The action's input as the model sent it: untrusted. `until`
     *                                        (`Y-m-d`) for a trial extension; ignored for the others.
     * @param  string|null  $rationale  Why the customer asked, in the model's words; trimmed and cut to 500 characters.
     * @param  int|string|null  $triggerMessageId  The customer message the turn answers, read from the turn's own
     *                                             conversation instance so a message written mid-turn is not it.
     * @return string The sentence the model relays to the customer.
     */
    public function record(
        SupportConversation $conversation,
        SupportProposalAction $action,
        array $params,
        ?string $rationale,
        int|string|null $triggerMessageId,
    ): string;
}
