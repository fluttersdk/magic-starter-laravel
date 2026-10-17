<?php

namespace FlutterSdk\MagicStarter\Support\AiRuns;

/**
 * What a host knows when it opens a run: one logical model call, before any
 * provider is tried.
 *
 * `model` is the model the host asked for; the run's model is replaced by the
 * one that actually answered when the run finishes. `instructions`, `input` and
 * the `context` values are bodies: masked and capped when written.
 */
final readonly class AiRunStart
{
    /**
     * @param  string  $feature  The host's own name for the calling feature, `incident.analysis`; 100 chars at most.
     * @param  int|string|null  $teamId  The team the call is made for; an integer or a UUID key.
     * @param  string|null  $subjectType  The morph type of the record the call is about.
     * @param  string  $source  Where the prompt came from; `code` unless an override supplied it.
     * @param  string|null  $promptHash  A 64-char sha256 of the prompt, so identical prompts group together.
     * @param  array<string, string>  $context  Named context blocks sent with the prompt.
     * @param  list<string>  $tools  The names of the tools offered to the model.
     */
    public function __construct(
        public string $feature,
        public int|string|null $teamId = null,
        public ?string $subjectType = null,
        public int|string|null $subjectId = null,
        public string $source = 'code',
        public ?string $promptHash = null,
        public ?string $model = null,
        public ?string $instructions = null,
        public ?string $input = null,
        public array $context = [],
        public array $tools = [],
    ) {}
}
