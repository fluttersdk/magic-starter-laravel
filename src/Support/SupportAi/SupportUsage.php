<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi;

use InvalidArgumentException;

/**
 * Model tokens one AI support call consumed, summed over every leg it made.
 *
 * A responder that calls a model more than once (a classifier, then an answer,
 * then a retry on another provider) reports the total of all of them, since
 * every leg is billed and every leg counts against the quota pool. Cache reads
 * and writes are reported apart from the uncached input so the quota can weigh them.
 */
final readonly class SupportUsage
{
    /**
     * `promptTokens` is the input the provider processed WITHOUT the cache (uncached input), unlike the run
     * log's AiUsage, whose input includes both buckets. The two cache counts are reported apart so a quota
     * can weigh them.
     *
     * @throws InvalidArgumentException when any count is negative
     */
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
    ) {
        if ($promptTokens < 0 || $completionTokens < 0 || $cacheReadTokens < 0 || $cacheWriteTokens < 0) {
            throw new InvalidArgumentException('Token counts cannot be negative.');
        }
    }

    /**
     * Add another leg's usage to this one.
     */
    public function plus(self $other): self
    {
        return new self(
            promptTokens: $this->promptTokens + $other->promptTokens,
            completionTokens: $this->completionTokens + $other->completionTokens,
            cacheReadTokens: $this->cacheReadTokens + $other->cacheReadTokens,
            cacheWriteTokens: $this->cacheWriteTokens + $other->cacheWriteTokens,
        );
    }

    /**
     * Raw tokens across all four buckets, unweighted; the quota charges `SupportAiQuota::chargedTokens()`.
     */
    public function total(): int
    {
        return $this->promptTokens + $this->completionTokens + $this->cacheReadTokens + $this->cacheWriteTokens;
    }
}
