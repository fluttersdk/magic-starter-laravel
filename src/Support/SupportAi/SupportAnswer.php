<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi;

use FlutterSdk\MagicStarter\Enums\SupportHandoffReason;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
use InvalidArgumentException;

/**
 * What a responder answers for one turn.
 *
 * `reply` is always posted to the customer, a handoff included: it is what the
 * customer reads before a person takes over. A handoff carries its reason and
 * the priority staff should pick it up at, both or neither.
 *
 * The constructor is the first gate, not the last: an answer that builds here
 * still passes the package's reply guard (length, links, credential requests,
 * completed-action claims) before anything is persisted.
 */
final readonly class SupportAnswer
{
    /**
     * @param  float  $confidence  The responder's own confidence in the reply, 0 to 1 inclusive.
     *
     * @throws InvalidArgumentException when the reply is blank, the confidence is outside 0..1
     *                                  or a handoff reason and priority do not come together
     */
    public function __construct(
        public string $reply,
        public float $confidence,
        public SupportUsage $usage,
        public ?SupportHandoffReason $handoffReason = null,
        public ?SupportPriority $handoffPriority = null,
    ) {
        if (trim($reply) === '') {
            throw new InvalidArgumentException('A support answer needs a reply.');
        }

        if (is_nan($confidence) || $confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('A support answer confidence must be between 0 and 1.');
        }

        if (($handoffReason === null) !== ($handoffPriority === null)) {
            throw new InvalidArgumentException('A handoff needs both a reason and a priority.');
        }
    }

    /**
     * Whether the responder asked to hand the conversation to staff.
     *
     * @phpstan-assert-if-true !null $this->handoffReason
     * @phpstan-assert-if-true !null $this->handoffPriority
     */
    public function handsOff(): bool
    {
        return $this->handoffReason !== null;
    }
}
