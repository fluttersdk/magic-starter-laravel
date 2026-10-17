<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Support\AiPrompts\ResolvedPrompt;
use InvalidArgumentException;

/**
 * Answers the effective text of a registered prompt for one model call.
 *
 * The text is a body (a staff override while it is current and complete, the
 * code default otherwise) followed by the code-owned boundary. A database
 * failure answers the code default rather than an exception, so a host never
 * branches on the result.
 */
interface ResolvesPrompts
{
    /**
     * @throws InvalidArgumentException When no prompt is registered under the key.
     */
    public function resolve(string $key): ResolvedPrompt;
}
