<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * What a support message is, as `support_messages.kind` stores it.
 *
 * Only `NOTE` is staff-only, and the `customerVisible()` scope on
 * `SupportMessage` is the single place that rule is applied. The values are
 * stored and read back, so a value never changes once shipped.
 */
enum SupportMessageKind: string
{
    /** A reply between the customer and staff. */
    case MESSAGE = 'message';

    /** An internal staff note. Never shown to the customer. */
    case NOTE = 'note';

    /** A recorded event in the thread, such as a status or assignee change. */
    case ACTIVITY = 'activity';
}
