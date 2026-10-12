<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Which door a support conversation came in through, as `support_conversations.source` stores it.
 *
 * The values are stored and read back, so a value never changes once shipped.
 */
enum SupportConversationSource: string
{
    /** Opened by a signed-in user from inside the application. */
    case IN_APP = 'in_app';

    /** A sales enquiry; may come from a visitor with no account. */
    case CONTACT_SALES = 'contact_sales';

    /** The public contact form; the opener has no account and follows the thread by mailed link. */
    case PUBLIC_FORM = 'public_form';

    case FEEDBACK = 'feedback';
}
