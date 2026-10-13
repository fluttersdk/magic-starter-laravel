<?php

namespace FlutterSdk\MagicStarter\Jobs;

use DateTimeInterface;
use FlutterSdk\MagicStarter\Contracts\AssistsSupportStaff;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportStaffAssistCache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks {@see AssistsSupportStaff} for a conversation's summary and reply draft
 * and stores both for the panel to read.
 *
 * Queued by the Summarize action, by an empty Draft reply and by the handoff
 * (which charges it to the opener's quota first), never run while a staff
 * member waits: the Reply modal only reads what this stored. It runs once
 * (`$tries = 1`, `$failOnTimeout`) because a second model call for the same
 * turn is cost and no new answer; staff ask again. A busy queue has
 * {@see self::RETRY_WINDOW_MINUTES} minutes to start it. A contract that
 * throws, or is no longer bound, leaves nothing stored and the panel as it is
 * without AI. The failure is logged and never reaches staff.
 *
 * `$timeout` is the hard ceiling (`support.ai.job_timeout`), kept above the
 * model's own deadline and below the worker's, as `config/magic-starter.php`
 * documents for the AI desk. It runs on the responder's connection and queue,
 * `redis` and `support` when the host's config has no `support.ai` block.
 */
class PrepareSupportStaffAssist implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * Minutes after dispatch a busy queue still starts the job; past them it fails without a model call.
     */
    public const RETRY_WINDOW_MINUTES = 10;

    /**
     * One attempt: staff ask again rather than the queue repeating a model call.
     */
    public int $tries = 1;

    /**
     * A run the worker killed at `$timeout` fails for good instead of starting again inside the retry window.
     */
    public bool $failOnTimeout = true;

    /**
     * Seconds before the worker kills the run; set from `support.ai.job_timeout`.
     */
    public int $timeout = 90;

    /**
     * @param  int|string  $conversationId  The conversation key; it is re-read, so a deleted one is skipped.
     */
    public function __construct(
        public readonly int|string $conversationId,
    ) {
        $this->timeout = (int) config('magic-starter.support.ai.job_timeout', $this->timeout);
        $this->onConnection(config('magic-starter.support.ai.connection', 'redis'));
        $this->onQueue(RespondToSupportConversation::queueName());
    }

    /**
     * Ask the contract once and store the answer under the conversation's turn.
     */
    public function handle(Container $container, SupportStaffAssistCache $cache, LoggerInterface $log): void
    {
        // 1. Skip a conversation that is gone, or a host that no longer binds the contract.
        $conversation = SupportConversation::query()->find($this->conversationId);

        if (! $conversation instanceof SupportConversation || ! $container->bound(AssistsSupportStaff::class)) {
            return;
        }

        // 2. Ask, store both strings, and let a provider failure end here.
        try {
            $cache->put($conversation, $container->make(AssistsSupportStaff::class)->assist($conversation));
        } catch (Throwable $exception) {
            $log->warning('The support staff assist failed; the panel shows no summary or draft.', [
                'conversation_id' => $this->conversationId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        } finally {
            // 3. Free the turn, so a failure can be asked again without waiting out the claim.
            $cache->release($conversation);
        }
    }

    /**
     * Past this moment the queue fails the job rather than starting it late.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::RETRY_WINDOW_MINUTES);
    }

    /**
     * Reached when the worker killed the run at `$timeout` or the job waited past its retry window; the panel
     * stays as it was.
     */
    public function failed(?Throwable $exception): void
    {
        logger()->warning(
            'The support staff assist never finished: it timed out or waited past its retry window.',
            [
                'conversation_id' => $this->conversationId,
                'exception' => $exception !== null ? $exception::class : null,
            ],
        );
    }
}
