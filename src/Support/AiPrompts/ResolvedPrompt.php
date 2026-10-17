<?php

namespace FlutterSdk\MagicStarter\Support\AiPrompts;

use FlutterSdk\MagicStarter\Enums\AiPromptSource;

/**
 * The text a host sends to the model for one call, with where its body came
 * from and the two hashes an AI run records.
 */
final readonly class ResolvedPrompt
{
    /** The sha256 of {@see self::$text}, computed here so it cannot disagree with it. */
    public string $hash;

    /**
     * @param  string  $text  The body followed by the code boundary.
     * @param  string  $defaultHash  The sha256 of the code default at resolve time, whichever body was used.
     */
    public function __construct(
        public string $text,
        public AiPromptSource $source,
        public string $defaultHash,
    ) {
        $this->hash = hash('sha256', $text);
    }
}
