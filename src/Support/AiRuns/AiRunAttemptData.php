<?php

namespace FlutterSdk\MagicStarter\Support\AiRuns;

use DateTimeInterface;
use FlutterSdk\MagicStarter\Enums\AiCostSource;
use FlutterSdk\MagicStarter\Enums\AiRunStatus;
use InvalidArgumentException;

/**
 * One finished provider call inside a run: a retry or a fallback to another
 * provider is its own attempt, with its own status, usage and cost.
 *
 * An attempt either succeeded or failed; it is recorded once it is over, so it
 * has no running state. `errorMessage` and each tool call's `arguments` and
 * `result` are bodies: masked and capped when written.
 */
final readonly class AiRunAttemptData
{
    /**
     * @param  int  $attempt  Counts from 1 and is unique within the run.
     * @param  string|null  $costUsd  A decimal string in US dollars, eight places at most, `0.00042`; never a float.
     * @param  AiCostSource|null  $costSource  How the cost was known; MIXED is a run's fold, never an attempt's.
     * @param  list<array{name: string, arguments: mixed, result: mixed}>  $toolCalls
     *
     * @throws InvalidArgumentException when the status is neither SUCCEEDED nor FAILED
     */
    public function __construct(
        public int $attempt,
        public string $provider,
        public ?string $model,
        public AiRunStatus $status,
        public ?int $httpStatus = null,
        public ?string $finishReason = null,
        public ?string $errorClass = null,
        public ?string $errorMessage = null,
        public ?AiUsage $usage = null,
        public ?string $costUsd = null,
        public ?AiCostSource $costSource = null,
        public ?int $durationMs = null,
        public array $toolCalls = [],
        public ?DateTimeInterface $startedAt = null,
    ) {
        if ($status !== AiRunStatus::SUCCEEDED && $status !== AiRunStatus::FAILED) {
            throw new InvalidArgumentException('An attempt either succeeded or failed.');
        }
    }
}
