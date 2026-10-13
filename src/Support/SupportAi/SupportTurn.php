<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi;

use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use InvalidArgumentException;

/**
 * One customer message the AI desk is asked to answer, with the context it may see.
 *
 * `history` is the thread before `trigger`, oldest first, already capped to
 * `support.ai.history_messages` and read through `customerVisible()`. The
 * constructor refuses a staff note or a message from another conversation, so
 * nothing a turn carries can leak either into a prompt, whoever built it.
 *
 * Every message body here is customer-authored or customer-visible text: a
 * responder treats it as untrusted data, never as instructions.
 */
final readonly class SupportTurn
{
    /**
     * @param  SupportMessage  $trigger  The customer message being answered.
     * @param  list<SupportMessage>  $history  Earlier customer-visible messages, oldest first.
     * @param  string  $locale  The language the reply must be written in.
     * @param  int  $deadlineSeconds  Wall-clock seconds the responder has for every leg together.
     *
     * @throws InvalidArgumentException when a message is not this conversation's, a history
     *                                  entry is not customer-visible, the trigger is not a
     *                                  customer message, or the deadline is not positive
     */
    public function __construct(
        public SupportConversation $conversation,
        public SupportMessage $trigger,
        public array $history,
        public string $locale,
        public int $deadlineSeconds,
    ) {
        if ($trigger->sender_type !== SupportSenderType::CUSTOMER || $trigger->kind !== SupportMessageKind::MESSAGE) {
            throw new InvalidArgumentException('A support turn answers a customer message.');
        }

        foreach ([$trigger, ...$history] as $message) {
            if ((string) $message->conversation_id !== (string) $conversation->getKey()) {
                throw new InvalidArgumentException('A support turn carries only its own conversation.');
            }

            if (! $message->isCustomerVisible()) {
                throw new InvalidArgumentException('A support turn carries only customer-visible messages.');
            }
        }

        if ($deadlineSeconds < 1) {
            throw new InvalidArgumentException('A support turn needs a positive deadline.');
        }
    }
}
