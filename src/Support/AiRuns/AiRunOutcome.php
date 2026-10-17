<?php

namespace FlutterSdk\MagicStarter\Support\AiRuns;

use FlutterSdk\MagicStarter\Enums\AiRunStatus;

/**
 * How a run ended, as the host saw it after its last attempt.
 *
 * Provider, model, usage and cost are not part of it: finishing a run folds
 * them from the recorded attempts. `output`, `structured` and `errorMessage`
 * are bodies: masked and capped when written.
 */
final readonly class AiRunOutcome
{
    /**
     * @param  array<array-key, mixed>|null  $structured  The decoded structured answer, when the call asked for one.
     * @param  int|null  $durationMs  Wall time of the whole run, every attempt and wait included.
     */
    public function __construct(
        public AiRunStatus $status,
        public ?string $output = null,
        public ?array $structured = null,
        public ?string $errorClass = null,
        public ?string $errorMessage = null,
        public ?int $durationMs = null,
    ) {}
}
