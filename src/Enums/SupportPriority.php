<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * How urgently staff should pick a conversation up, as `support_conversations.priority` stores it.
 *
 * The values are stored and read back, so a value never changes once shipped.
 */
enum SupportPriority: string
{
    case LOW = 'low';

    /** The default for a new conversation. */
    case NORMAL = 'normal';

    case HIGH = 'high';

    case URGENT = 'urgent';
}
