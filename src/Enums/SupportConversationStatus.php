<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Where a support conversation stands, as `support_conversations.status` stores it.
 *
 * The values are stored and read back, so a value never changes once shipped.
 */
enum SupportConversationStatus: string
{
    /** The AI desk owns the conversation; only the package writes it, never staff. */
    case PENDING = 'pending';

    /** Waiting on staff or on the customer; the default for a new conversation. */
    case OPEN = 'open';

    /** Set aside by staff until later; a customer message reopens it. */
    case SNOOZED = 'snoozed';

    case RESOLVED = 'resolved';
}
