<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi;

use InvalidArgumentException;

/**
 * A host-written paraphrase of a support thread up to and including `throughMessageId`.
 *
 * A responder returns one on {@see SupportAnswer::$compaction} to replace the
 * stored summary, and the next turn then carries it with only the messages
 * after that boundary. The text is model output over customer-authored
 * messages: it is untrusted data, and a host that puts it into a later prompt
 * must fence it like any message body, never read it as instructions.
 */
final readonly class SupportHistorySummary
{
    /**
     * @param  int|string  $throughMessageId  The key of the newest message the summary covers.
     *
     * @throws InvalidArgumentException when the text is blank
     */
    public function __construct(
        public string $text,
        public int|string $throughMessageId,
    ) {
        if (trim($text) === '') {
            throw new InvalidArgumentException('A support history summary needs text.');
        }
    }
}
