<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Who a knowledge-base read is for, which decides the visibilities it may see.
 *
 * The single place that maps a reader to what it may read; a new reader adds a
 * case here instead of teaching each caller its own filter.
 */
enum SupportAudience: string
{
    /** A signed-in customer's turn with the AI agent; in-app suggestions link to the page, so they read as PAGE. */
    case CUSTOMER = 'customer';

    /** A staff member in the admin panel. */
    case STAFF = 'staff';

    /** The unauthenticated public FAQ page. */
    case PAGE = 'page';

    /**
     * The visibilities this audience may read.
     *
     * @return list<SupportArticleVisibility>
     */
    public function visibilities(): array
    {
        return match ($this) {
            self::PAGE => [
                SupportArticleVisibility::PUBLIC,
            ],
            self::CUSTOMER => [
                SupportArticleVisibility::PUBLIC,
                SupportArticleVisibility::AI_ONLY,
            ],
            self::STAFF => SupportArticleVisibility::cases(),
        };
    }
}
