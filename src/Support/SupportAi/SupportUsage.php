<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi;

use InvalidArgumentException;

/**
 * Model tokens one AI support call consumed, summed over every leg it made.
 *
 * A responder that calls a model more than once (a classifier, then an answer,
 * then a retry on another provider) reports the total of all of them, since
 * every leg is billed and every leg counts against the quota pool.
 */
final readonly class SupportUsage
{
    /**
     * @throws InvalidArgumentException when either count is negative
     */
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
    ) {
        if ($promptTokens < 0 || $completionTokens < 0) {
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
        );
    }

    /**
     * Prompt and completion tokens together, the figure a quota pool is charged.
     */
    public function total(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
