<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi;

use FlutterSdk\MagicStarter\Enums\SupportAiPool;

/**
 * What {@see SupportAiQuota::reserve()} charged for one turn, and the handle to settle it.
 *
 * It names the day the tokens were reserved on and the user they were charged
 * to, so a reconcile that lands after midnight still adjusts that day's pool
 * and that user's token ceiling.
 */
final readonly class Reservation
{
    /**
     * @param  string  $userId  The opener whose daily token ceiling was charged.
     * @param  int  $tokens  Tokens reserved from the pool and the user's ceiling: `support.ai.pool_reservation_tokens`
     *                       plus `support.ai.image_reservation_tokens` for each image the turn carries.
     * @param  string  $day  The `Y-m-d` the counters were charged on.
     */
    public function __construct(
        public string $conversationId,
        public string $turnId,
        public SupportAiPool $pool,
        public int $tokens,
        public string $day,
        public string $userId,
    ) {}
}
