<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Who may read a knowledge-base article, as `support_articles.visibility` stores it.
 *
 * The values are stored and read back, so a value never changes once shipped.
 */
enum SupportArticleVisibility: string
{
    /** Shown on the public FAQ page, and readable by everyone else too. */
    case PUBLIC = 'public';

    /** Read by the AI agent, never shown on the public page or offered as an in-app suggestion. */
    case AI_ONLY = 'ai_only';

    /** Internal notes for staff: no customer-facing reader ever sees it. */
    case STAFF_ONLY = 'staff_only';
}
