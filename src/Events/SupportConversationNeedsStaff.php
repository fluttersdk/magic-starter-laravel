<?php

namespace FlutterSdk\MagicStarter\Events;

use FlutterSdk\MagicStarter\Models\SupportConversation;

/**
 * A conversation is waiting on staff: a customer opened it or wrote again, or
 * the AI desk handed it over.
 *
 * Fired once per wait: a signed-in customer writing again to a conversation
 * already waiting on staff fires nothing, and a handoff fires only for the
 * caller that won it. On `handoff` the conversation's `handoff_reason` says why.
 *
 * The seam a staff alert hooks into; the package itself sends nothing from it.
 */
class SupportConversationNeedsStaff
{
    /**
     * @param  string  $reason  `opened`, `customer_message` or `handoff`
     */
    public function __construct(
        public readonly SupportConversation $conversation,
        public readonly string $reason,
    ) {}
}
