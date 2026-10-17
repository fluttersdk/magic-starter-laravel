<?php

namespace FlutterSdk\MagicStarter\Support\AiPrompts;

use Closure;

/**
 * One prompt a host registered at boot: the editable code default body, the
 * code-owned boundary block, and the phrases any body must keep.
 *
 * Both texts are closures, evaluated on every call, and must be deterministic: no
 * locale, request, clock or config that varies from call to call. One override row
 * pins one default hash, so a body that differs per call would leave the override
 * stale for some calls and current for others. {@see self::defaultHash()} always
 * describes the default the code would answer right now.
 */
final readonly class PromptDefinition
{
    /**
     * @param  Closure(): string  $body  The code default, the only part staff may replace.
     * @param  Closure(): string  $boundary  Appended after every body; never stored, never editable.
     * @param  list<string>  $requiredPhrases  Literals a body must contain, compared by {@see RequiredPhrases}.
     */
    public function __construct(
        public string $key,
        public string $label,
        private Closure $body,
        private Closure $boundary,
        public array $requiredPhrases = [],
    ) {}

    public function body(): string
    {
        return ($this->body)();
    }

    public function boundary(): string
    {
        return ($this->boundary)();
    }

    /**
     * The sha256 of the current code default, which an override records to be
     * judged stale once the default changes underneath it.
     */
    public function defaultHash(): string
    {
        return self::hashBody($this->body());
    }

    /**
     * The hash of a body already read from the closure, for a caller that needs the text as well.
     */
    public static function hashBody(string $body): string
    {
        return hash('sha256', $body);
    }
}
