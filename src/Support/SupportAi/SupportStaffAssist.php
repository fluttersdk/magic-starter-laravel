<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi;

use InvalidArgumentException;

/**
 * What a staff assistant answers for a conversation: a summary of the thread
 * and a reply draft. Staff read both and send nothing without editing or
 * confirming; neither ever reaches the customer on its own.
 */
final readonly class SupportStaffAssist
{
    /**
     * @throws InvalidArgumentException when the summary or the draft is blank
     */
    public function __construct(
        public string $summary,
        public string $draft,
    ) {
        if (trim($summary) === '' || trim($draft) === '') {
            throw new InvalidArgumentException('A staff assist needs both a summary and a draft.');
        }
    }
}
