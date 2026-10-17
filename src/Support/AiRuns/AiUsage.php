<?php

namespace FlutterSdk\MagicStarter\Support\AiRuns;

use InvalidArgumentException;

/**
 * Tokens one provider call reported.
 *
 * `inputTokens` INCLUDES the cache reads and cache writes, which are subsets of
 * it (the laravel/ai and OpenTelemetry GenAI convention), so a total is never
 * `input + cacheRead`. A count the provider did not report stays null rather
 * than becoming zero.
 */
final readonly class AiUsage
{
    /**
     * @throws InvalidArgumentException when a count is negative
     */
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public ?int $cacheReadTokens = null,
        public ?int $cacheWriteTokens = null,
        public ?int $reasoningTokens = null,
    ) {
        $counts = [
            $inputTokens,
            $outputTokens,
            $cacheReadTokens ?? 0,
            $cacheWriteTokens ?? 0,
            $reasoningTokens ?? 0,
        ];

        if (min($counts) < 0) {
            throw new InvalidArgumentException('Token counts cannot be negative.');
        }
    }
}
