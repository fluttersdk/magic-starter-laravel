<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Who wrote a support message, as `support_messages.sender_type` stores it.
 *
 * The values are stored and read back, so a value never changes once shipped.
 */
enum SupportSenderType: string
{
    /** The opener; `sender_id` is null when they wrote through the public link. */
    case CUSTOMER = 'customer';

    case STAFF = 'staff';

    /** An answer the AI desk wrote through the host's `RespondsToSupport`. */
    case AI = 'ai';

    /** The package itself, for activity lines such as a status change; no `sender_id`. */
    case SYSTEM = 'system';
}
