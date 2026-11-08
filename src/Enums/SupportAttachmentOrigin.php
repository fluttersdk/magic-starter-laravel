<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Where a support attachment came from, as `support_message_attachments.origin` stores it.
 *
 * The values are stored and read back, so a value never changes once shipped.
 */
enum SupportAttachmentOrigin: string
{
    /** A file the customer picked. */
    case UPLOAD = 'upload';

    /** A file staff attached from the panel. */
    case STAFF = 'staff';
}
