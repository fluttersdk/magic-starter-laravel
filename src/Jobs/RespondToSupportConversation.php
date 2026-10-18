<?php

namespace FlutterSdk\MagicStarter\Jobs;

use DateTimeInterface;
use FlutterSdk\MagicStarter\Contracts\RespondsToSupport;
use FlutterSdk\MagicStarter\Enums\SupportAttachmentOrigin;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportHandoffReason;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationUpdated;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Models\SupportMessageAttachment;
use FlutterSdk\MagicStarter\Notifications\SupportReplyNotification;
use FlutterSdk\MagicStarter\Support\SupportAi\HandoffClassifier;
use FlutterSdk\MagicStarter\Support\SupportAi\ReplyGuard;
use FlutterSdk\MagicStarter\Support\SupportAi\Reservation;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAiQuota;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAnswer;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportHandoff;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportTurn;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportUsage;
use FlutterSdk\MagicStarter\Support\SupportAttachmentResponse;
use FlutterSdk\MagicStarter\Support\TeamKey;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Answers one customer message of a pending conversation through {@see RespondsToSupport}.
 *
 * ONE ANSWER PER TURN, whoever else writes. A turn is identified by its
 * trigger, the customer message the job was queued for, and it is answered
 * only while the conversation is `pending`, the trigger is still its
 * `last_customer_message_id` and not yet its `answered_message_id`. That
 * predicate is checked before the model is called and again inside the
 * transaction that writes the answer, under `lockForUpdate`, so a newer
 * customer message, a staff reply or a handoff that lands while the model
 * thinks makes the answer a discard rather than a second voice. A retry of an
 * answered turn finds it answered and stops; the quota reads the same
 * reservation back, so it is charged once.
 *
 * Jobs of one conversation run one at a time under `support-ai:{id}`, held for
 * `support.ai.lock_seconds` at most. A job that cannot take it within 15
 * seconds goes back on the queue for 10. The lock outlives `$timeout`, so a
 * worker killed at the ceiling cannot leave two jobs inside; the predicate is
 * the backstop if a host shortens it.
 *
 * An answer that asks for a person is posted and handed off in the same
 * transaction, under the same lock and predicate, and staff are told after the
 * commit; the tokens are settled last, so a failure to settle cannot leave an
 * answered turn pending. An answer handed off as `unavailable` posts nothing of
 * its own: the handoff line already tells the customer.
 *
 * Nothing here reaches the customer as an error. A classifier hit, a refused
 * quota or an opener who lost eligibility hands the conversation to staff with
 * its reason; a responder that throws or an answer {@see ReplyGuard} rejects is
 * a failed turn, retried once by the queue, and handed off as `failures` at
 * `support.ai.failures_before_handoff`; a job that dies for good is handed off
 * as `unavailable` by {@see self::failed()}, and one that never runs by the
 * sweeper.
 */
class RespondToSupportConversation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Seconds a job waits for a sibling of the same conversation to finish before going back on the queue.
     */
    public const LOCK_WAIT_SECONDS = 15;

    /**
     * Seconds a job that found the conversation busy waits on the queue before it tries again.
     */
    public const BUSY_RELEASE_SECONDS = 10;

    /**
     * Minutes after dispatch past which the queue stops retrying and {@see self::failed()} hands off.
     */
    public const RETRY_WINDOW_MINUTES = 10;

    /**
     * Two attempts at a turn. `retryUntil()` takes precedence in the worker, so a release for a busy
     * conversation never spends one; `$maxExceptions` holds the count for a job that throws.
     */
    public int $tries = 2;

    /**
     * Unhandled exceptions before the job fails for good and hands off.
     */
    public int $maxExceptions = 2;

    /**
     * Seconds before the worker kills the run; set from `support.ai.job_timeout`.
     */
    public int $timeout = 90;

    /**
     * @param  int|string  $conversationId  Re-read on every run, so a deleted conversation is skipped.
     * @param  int|string  $triggerId  The customer message this turn answers; the quota's turn id.
     */
    public function __construct(
        public readonly int|string $conversationId,
        public readonly int|string $triggerId,
    ) {
        $this->timeout = (int) config('magic-starter.support.ai.job_timeout', $this->timeout);
        $this->onConnection(config('magic-starter.support.ai.connection', 'redis'));
        $this->onQueue(self::queueName());
        $this->afterCommit();
    }

    /**
     * The queue the AI desk's jobs run on, `support.ai.queue`.
     */
    public static function queueName(): string
    {
        return (string) config('magic-starter.support.ai.queue', 'support');
    }

    /**
     * Answer the turn under the conversation lock, or go back on the queue while a sibling holds it.
     */
    public function handle(
        Container $container,
        SupportHandoff $desk,
        SupportAiQuota $quota,
        HandoffClassifier $classifier,
        ReplyGuard $guard,
    ): void {
        $lock = Cache::lock(
            'support-ai:' . $this->conversationId,
            (int) config('magic-starter.support.ai.lock_seconds', 120),
        );
        $waitStartedAt = microtime(true);

        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            $this->release(self::BUSY_RELEASE_SECONDS);

            return;
        }

        // The model's budget shrinks by the time spent waiting for the lock, so
        // wait plus call never outruns the job timeout and the worker's SIGKILL.
        $waited = (int) floor(microtime(true) - $waitStartedAt);

        try {
            $this->answer($container, $desk, $quota, $classifier, $guard, $waited);
        } finally {
            $lock->release();
        }
    }

    /**
     * Past this moment the queue stops retrying, and the turn is handed off by {@see self::failed()}.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::RETRY_WINDOW_MINUTES);
    }

    /**
     * The job died for good: hand the turn to staff as `unavailable`, without the lock.
     *
     * Only while this turn is still the newest unanswered one, so a superseded
     * job never takes a conversation from the job that owns it now.
     */
    public function failed(?Throwable $exception): void
    {
        $conversation = SupportConversation::query()->find($this->conversationId);

        if ($conversation instanceof SupportConversation) {
            app(SupportHandoff::class)->handoff(
                $conversation,
                SupportHandoffReason::UNAVAILABLE,
                turn: $this->triggerId,
            );
        }
    }

    private function answer(
        Container $container,
        SupportHandoff $desk,
        SupportAiQuota $quota,
        HandoffClassifier $classifier,
        ReplyGuard $guard,
        int $waitedSeconds,
    ): void {
        // 1. Stale before the model: a newer message, a staff reply, a handoff or an answer took the turn.
        $conversation = SupportConversation::query()->find($this->conversationId);
        $trigger = SupportMessage::query()
            ->whereKey($this->triggerId)
            ->where('conversation_id', $this->conversationId)
            ->first();

        if (! $conversation instanceof SupportConversation || $trigger === null || ! $this->owns($conversation)) {
            return;
        }

        // 2. Eligibility is re-read on every turn; an opener who lost it, or an unbound responder, goes to staff.
        if (! $desk->routesToAi($conversation->user, $conversation->source)) {
            $desk->handoff($conversation, SupportHandoffReason::UNAVAILABLE, turn: $this->triggerId);

            return;
        }

        // 3. The deterministic classifier reads every message the desk still owes an answer to, before any
        //    model is called: a breach reported one message before a "hello?" is still a breach.
        //    A trigger that is only files has no question for the model, so it goes to staff here too.
        [
            'messages' => $history,
            'truncated' => $historyTruncated,
            'summary' => $summary,
        ] = $this->history($conversation, $trigger);
        $uploads = $this->uploads($trigger);

        // The boundary exactly as read, so the compare-and-set at persist time matches this row's value.
        $summaryThroughId = $conversation->ai_summary_through_id;
        $verdict = $classifier->classify(
            $this->unansweredText($history, $trigger),
            attachmentOnly: ($uploads['images'] !== [] || $uploads['pdfs'] > 0)
                && trim((string) $trigger->body) === '',
        );

        if ($verdict !== null) {
            $desk->handoff($conversation, $verdict['reason'], $verdict['priority'], $this->triggerId);

            return;
        }

        // 4. Charge the turn before the call; a pool the host cannot name or a refused quota goes to staff.
        $responder = $container->make(RespondsToSupport::class);

        try {
            $pool = $responder->pool($conversation);
        } catch (Throwable $exception) {
            report($exception);
            $desk->handoff($conversation, SupportHandoffReason::UNAVAILABLE, turn: $this->triggerId);

            return;
        }

        $reservation = $quota->reserve(
            $conversation,
            (string) $this->triggerId,
            $pool,
            images: count($uploads['images']),
        );

        if ($reservation === null) {
            $desk->handoff($conversation, SupportHandoffReason::QUOTA, turn: $this->triggerId);

            return;
        }

        // 5. Ask; a responder that throws is a failed turn.
        try {
            $answer = $responder->respond(new SupportTurn(
                conversation: $conversation,
                trigger: $trigger,
                history: $history,
                locale: $desk->locale($conversation),
                deadlineSeconds: max(1, (int) config('magic-starter.support.ai.deadline_seconds', 75) - $waitedSeconds),
                images: $uploads['images'],
                pdfCount: $uploads['pdfs'],
                summary: $summary,
                summaryThroughId: $summaryThroughId,
                historyTruncated: $historyTruncated,
            ));
        } catch (Throwable $exception) {
            report($exception);
            $this->failTurn($desk, $quota, $reservation, new SupportUsage(0, 0));

            return;
        }

        // 6. A responder that could not answer says so in its reply, which the handoff line already says to
        //    the customer: hand off only, and post nothing of its own.
        if ($answer->handoffReason === SupportHandoffReason::UNAVAILABLE) {
            try {
                $desk->handoff(
                    $conversation,
                    SupportHandoffReason::UNAVAILABLE,
                    $answer->handoffPriority,
                    $this->triggerId,
                );
            } finally {
                $quota->reconcile($reservation, $answer->usage);
            }

            return;
        }

        // 7. The guard refuses what may not reach a customer; a refusal is a failed turn.
        if ($guard->check($answer) !== null) {
            $this->failTurn($desk, $quota, $reservation, $answer->usage);

            return;
        }

        // 8. Persist the answer, and a handoff it asks for, in one transaction under the row lock; tell staff,
        //    or tell the opener and refresh the thread, once it commits, and settle the tokens last, whatever
        //    happened before.
        try {
            $message = $this->persist($desk, $answer, $summaryThroughId);

            if ($message === null) {
                return;
            }

            if ($answer->handsOff()) {
                $desk->announceHandoff($conversation);
            } else {
                // The conversation is read again, so the opener is whoever owns it now: a guest claim can commit
                // while the model thinks and hand it to the account, and the spent guest is not told anything.
                $conversation->refresh();

                // The opener is told in app and by push, as for a staff reply; an outage of either must never
                // fail an answer that is already stored.
                rescue(
                    static fn () => Notification::send($conversation->user, new SupportReplyNotification($message)),
                    report: true,
                );
                rescue(static fn () => event(new SupportConversationUpdated($conversation)), report: true);
            }
        } finally {
            $quota->reconcile($reservation, $answer->usage);
        }
    }

    /**
     * Whether this job's trigger is still the turn the desk owes: pending, newest, unanswered.
     */
    private function owns(SupportConversation $conversation): bool
    {
        return $conversation->status === SupportConversationStatus::PENDING
            && (string) $conversation->last_customer_message_id === (string) $this->triggerId
            && (string) $conversation->answered_message_id !== (string) $this->triggerId;
    }

    /**
     * The customer-visible thread after the summary's boundary and before the trigger, oldest first.
     *
     * Capped to `support.ai.history_messages`; `truncated` says the cap dropped older messages of that window.
     * A boundary whose message is gone bounds nothing, so the whole thread is read again and the stored
     * summary is left out: it covers messages the history now carries in full.
     *
     * @return array{messages: list<SupportMessage>, truncated: bool, summary: ?string}
     */
    private function history(SupportConversation $conversation, SupportMessage $trigger): array
    {
        $cap = max(0, (int) config('magic-starter.support.ai.history_messages', 12));
        $boundary = $conversation->ai_summary_through_id === null
            ? null
            : $conversation->messages()->whereKey($conversation->ai_summary_through_id)->first();

        // Both bounds are strict in the thread's own order, created_at then id, so a message written in the
        // same second as the boundary or the trigger lands on its true side. One extra row tells a full
        // window from a truncated one.
        $messages = $conversation->messages()
            ->customerVisible()
            ->when($boundary, function (Builder $after, SupportMessage $boundary): void {
                $after->where(function (Builder $after) use ($boundary): void {
                    $after->where('created_at', '>', $boundary->created_at)
                        ->orWhere(function (Builder $sameSecond) use ($boundary): void {
                            $sameSecond->where('created_at', $boundary->created_at)
                                ->where($boundary->getKeyName(), '>', $boundary->getKey());
                        });
                });
            })
            ->where(function (Builder $before) use ($trigger): void {
                $before->where('created_at', '<', $trigger->created_at)
                    ->orWhere(function (Builder $sameSecond) use ($trigger): void {
                        $sameSecond->where('created_at', $trigger->created_at)
                            ->where($trigger->getKeyName(), '<', $trigger->getKey());
                    });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($cap + 1)
            ->get();

        return [
            'messages' => $messages->take($cap)->reverse()->values()->all(),
            'truncated' => $messages->count() > $cap,
            'summary' => $boundary === null ? null : $conversation->ai_summary,
        ];
    }

    /**
     * The files the customer uploaded with the trigger, split into what the model may see and what it may only be told.
     *
     * Only the trigger's own `upload` files count: the auto screenshot, staff files and anything on an earlier
     * message stay out. An image is a disk and a path, never bytes; the responder reads it if its provider can.
     * Without `support-attachments` there are none to read, and a host that never published the attachments
     * migration has no table to read them from.
     *
     * @return array{images: list<array{disk: string, path: string, mime: string}>, pdfs: int}
     */
    private function uploads(SupportMessage $trigger): array
    {
        if (! Features::hasSupportAttachmentsFeatures()) {
            return [
                'images' => [],
                'pdfs' => 0,
            ];
        }

        $uploads = $trigger->attachments()
            ->where('origin', SupportAttachmentOrigin::UPLOAD->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return [
            'images' => $uploads
                ->filter(static fn (SupportMessageAttachment $file): bool => in_array(
                    $file->mime,
                    SupportAttachmentResponse::IMAGE_MIMES,
                    true,
                ))
                ->map(static fn (SupportMessageAttachment $file): array => [
                    'disk' => $file->disk,
                    'path' => $file->path,
                    'mime' => $file->mime,
                ])
                ->values()
                ->all(),
            'pdfs' => $uploads->where('mime', SupportAttachmentResponse::PDF)->count(),
        ];
    }

    /**
     * The trigger and every customer message right before it that nothing answered yet.
     *
     * @param  list<SupportMessage>  $history
     */
    private function unansweredText(array $history, SupportMessage $trigger): string
    {
        $unanswered = [$trigger->body];

        foreach (array_reverse($history) as $message) {
            if ($message->sender_type !== SupportSenderType::CUSTOMER) {
                break;
            }

            $unanswered[] = $message->body;
        }

        return implode("\n", array_reverse($unanswered));
    }

    /**
     * Write the answer as the AI desk's message, only while the turn is still owed.
     *
     * An answer that asks for a person hands off in the same transaction, under
     * the same lock and predicate, so a committed answer is never left pending
     * with nobody owing the customer a reply. Nothing is announced here; the
     * caller does that after the commit.
     *
     * A compaction rides on the same write, and only while the stored boundary
     * is still `$readBoundary`, the one the turn was built from: a summary that
     * another turn replaced meanwhile is newer than this one, so it stays.
     *
     * @param  int|string|null  $readBoundary  The `ai_summary_through_id` this turn read, null for none.
     * @return SupportMessage|null the stored answer, or null when the turn moved on while the model was
     *                             answering; nothing is written then
     */
    private function persist(
        SupportHandoff $desk,
        SupportAnswer $answer,
        int|string|null $readBoundary,
    ): ?SupportMessage {
        return DB::transaction(function () use ($desk, $answer, $readBoundary): ?SupportMessage {
            $conversation = SupportConversation::query()->lockForUpdate()->find($this->conversationId);

            if (! $conversation instanceof SupportConversation || ! $this->owns($conversation)) {
                return null;
            }

            $message = $conversation->messages()->create([
                'sender_type' => SupportSenderType::AI,
                'sender_id' => null,
                'kind' => SupportMessageKind::MESSAGE,
                'body' => $answer->reply,
                'meta' => [
                    'trigger_id' => (string) $this->triggerId,
                    'confidence' => $answer->confidence,
                    'handoff_reason' => $answer->handoffReason?->value,
                    'prompt_tokens' => $answer->usage->promptTokens,
                    'completion_tokens' => $answer->usage->completionTokens,
                ],
            ]);

            $conversation->forceFill([
                'answered_message_id' => $this->triggerId,
                'ai_failures' => 0,
                'last_activity_at' => now(),
                ...$this->compaction($conversation, $answer, $readBoundary),
            ])->save();

            // The row was read pending under this transaction's lock, so the conditional flip is this turn's.
            if ($answer->handsOff()) {
                $desk->moveToStaff($conversation, $answer->handoffReason, $answer->handoffPriority);
            }

            return $message;
        });
    }

    /**
     * The summary columns to store with the answer, or none when there is no compaction or the boundary moved.
     *
     * Compared as strings, as {@see self::owns()} compares keys, so an int column read back as a string still
     * matches; null matches only null.
     *
     * @return array{ai_summary?: string, ai_summary_through_id?: int|string}
     */
    private function compaction(
        SupportConversation $locked,
        SupportAnswer $answer,
        int|string|null $readBoundary,
    ): array {
        if ($answer->compaction === null) {
            return [];
        }

        $stored = $locked->ai_summary_through_id;

        if (($stored === null) !== ($readBoundary === null) || (string) $stored !== (string) $readBoundary) {
            return [];
        }

        if (! $this->summarisable($locked, $answer->compaction->throughMessageId)) {
            Log::warning('A support compaction named a message outside the turn\'s history; the summary is dropped.', [
                'conversation_id' => (string) $locked->getKey(),
                'through_message_id' => (string) $answer->compaction->throughMessageId,
            ]);

            return [];
        }

        return [
            'ai_summary' => $answer->compaction->text,
            'ai_summary_through_id' => $answer->compaction->throughMessageId,
        ];
    }

    /**
     * Whether `$throughId` is a message of this conversation sent before the trigger, so a later turn finds the
     * boundary and reads exactly the messages after it.
     *
     * The key shape is checked first: PostgreSQL refuses a non-uuid literal in a uuid column, and that error
     * inside this transaction would lose the answer with the summary.
     */
    private function summarisable(SupportConversation $locked, int|string $throughId): bool
    {
        if (! TeamKey::looksLikeOne((string) $throughId)) {
            return false;
        }

        $boundary = $locked->messages()->whereKey($throughId)->first();
        $trigger = $locked->messages()->whereKey($this->triggerId)->first();

        if ($boundary === null || $trigger === null) {
            return false;
        }

        // Strictly before the trigger in the thread's own order, created_at then id, as history() reads it. The
        // keys compare uncast: integer keys numerically, uuid keys by their text, as the database orders them.
        return $boundary->created_at->lt($trigger->created_at)
            || ($boundary->created_at->eq($trigger->created_at) && $boundary->getKey() < $trigger->getKey());
    }

    /**
     * Count a failed turn; at the limit hand it to staff, below it give the queue its retry.
     *
     * A retried turn keeps its reservation unsettled, so the retry's own call is
     * still covered by the charge it reads back; a turn that ends here settles
     * to what it used.
     */
    private function failTurn(
        SupportHandoff $desk,
        SupportAiQuota $quota,
        Reservation $reservation,
        SupportUsage $usage,
    ): void {
        $counted = SupportConversation::query()
            ->whereKey($this->conversationId)
            ->where('status', SupportConversationStatus::PENDING->value)
            ->where('last_customer_message_id', $this->triggerId)
            ->increment('ai_failures');

        // 1. The turn moved on while the model answered; there is nothing left to count against.
        if ($counted !== 1) {
            $quota->reconcile($reservation, $usage);

            return;
        }

        $conversation = SupportConversation::query()->findOrFail($this->conversationId);

        // 2. Below the limit the queue tries the turn once more.
        if ($conversation->ai_failures < (int) config('magic-starter.support.ai.failures_before_handoff', 2)) {
            $this->release();

            return;
        }

        // 3. At the limit a person takes over.
        $quota->reconcile($reservation, $usage);
        $desk->handoff($conversation, SupportHandoffReason::FAILURES, turn: $this->triggerId);
    }
}
