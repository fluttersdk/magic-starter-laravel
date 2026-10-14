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

    /** The screenshot the app captured when the conversation was opened. Never sent to the model. */
    case AUTO_SCREENSHOT = 'auto_screenshot';

    /** A file staff attached from the panel. */
    case STAFF = 'staff';
}
