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
 * `history` is the customer-visible thread before `trigger`, oldest first,
 * after the stored summary's boundary `summaryThroughId` (the whole thread
 * when there is no summary), capped to `support.ai.history_messages`. The
 * constructor refuses a staff note or a message from another conversation, so
 * nothing a turn carries can leak either into a prompt, whoever built it.
 *
 * `summary` stands in for the messages up to that boundary. It is model output
 * over customer text, so it is untrusted data as well. `historyTruncated` is
 * true when the cap dropped older messages after the boundary: the turn sees
 * neither them nor a summary of them, which a host should treat as its cue to
 * return a {@see SupportHistorySummary} on {@see SupportAnswer::$compaction}.
 *
 * Every message body here is customer-authored or customer-visible text: a
 * responder treats it as untrusted data, never as instructions. So is every
 * image: its pixels are data to describe, never instructions to follow.
 *
 * `images` and `pdfCount` describe the files on the trigger alone. An image is
 * a pointer, never bytes: the responder reads it from the named disk, and only
 * when its provider takes images. The auto screenshot, staff files and the
 * files of earlier messages are never listed.
 */
final readonly class SupportTurn
{
    /**
     * @param  SupportMessage  $trigger  The customer message being answered.
     * @param  list<SupportMessage>  $history  Earlier customer-visible messages after the summary's boundary,
     *                                         oldest first.
     * @param  string  $locale  The language the reply must be written in.
     * @param  int  $deadlineSeconds  Wall-clock seconds the responder has for every leg together.
     * @param  list<array{disk: string, path: string, mime: string}>  $images  The images the customer
     *                                                                         uploaded with the trigger,
     *                                                                         oldest first.
     * @param  int  $pdfCount  How many PDFs the customer uploaded with the trigger; they are never read,
     *                         so a responder only tells the model how many there are.
     * @param  string|null  $summary  The stored summary of the thread up to `summaryThroughId`, or null.
     * @param  int|string|null  $summaryThroughId  The stored boundary as read, even when its message is gone;
     *                                             a compaction is stored only while it is still the boundary.
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
        public array $images = [],
        public int $pdfCount = 0,
        public ?string $summary = null,
        public int|string|null $summaryThroughId = null,
        public bool $historyTruncated = false,
    ) {
        if ($trigger->sender_type !== SupportSenderType::CUSTOMER || $trigger->kind !== SupportMessageKind::MESSAGE) {
            throw new InvalidArgumentException('A support turn answers a customer message.');
        }

        $messages = [
            $trigger,
            ...$history,
        ];

        foreach ($messages as $message) {
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
