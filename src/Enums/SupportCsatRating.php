<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * How a customer rated a resolved conversation, as `support_conversations.csat_rating` stores it.
 *
 * The values are stored and read back, so a value never changes once shipped.
 */
enum SupportCsatRating: string
{
    /** Thumbs up. */
    case UP = 'up';

    /** Thumbs down; staff are alerted once per resolution. */
    case DOWN = 'down';
}
