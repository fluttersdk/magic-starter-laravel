<?php

namespace FlutterSdk\MagicStarter\Events;

use FlutterSdk\MagicStarter\Models\SupportConversation;

/**
 * A conversation is waiting on staff: a customer opened it or wrote again.
 *
 * The seam a staff alert hooks into; the package itself sends nothing from it.
 */
class SupportConversationNeedsStaff
{
    /**
     * @param  string  $reason  `opened` or `customer_message`
     */
    public function __construct(
        public readonly SupportConversation $conversation,
        public readonly string $reason,
    ) {}
}
