<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi;

use FlutterSdk\MagicStarter\Contracts\AssistsSupportStaff;
use FlutterSdk\MagicStarter\Contracts\RespondsToSupport;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportHandoffReason;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Events\SupportConversationUpdated;
use FlutterSdk\MagicStarter\Jobs\PrepareSupportStaffAssist;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * Where a conversation goes between the AI desk and staff.
 *
 * {@see self::routesToAi()} decides whether the AI desk answers a turn at all;
 * {@see self::handoff()} moves a pending conversation to staff.
 *
 * A handoff takes no lock, so the job's `failed()`, the sweeper and a turn that
 * still holds the conversation lock can all call it. It is one conditional
 * UPDATE on `status = pending`, and the database decides the race: exactly one
 * caller changes the row, and only that caller posts the customer-visible
 * handoff line, alerts staff and queues the staff assist. Every other caller
 * changes nothing and is told so. The responder, whose answer can itself ask
 * for a person, runs the same write inside its own transaction
 * ({@see self::moveToStaff()}) and announces after the commit
 * ({@see self::announceHandoff()}).
 */
final class SupportHandoff
{
    /**
     * The doors whose signed-in openers the AI desk answers; the public form never reaches it.
     *
     * @var list<SupportConversationSource>
     */
    private const AI_SOURCES = [
        SupportConversationSource::IN_APP,
        SupportConversationSource::CONTACT_SALES,
    ];

    public function __construct(
        private readonly Container $container,
        private readonly SupportAiQuota $quota,
        private readonly SupportStaffAssistCache $assists,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Whether the AI desk answers this opener's turn: a responder is bound, the
     * opener is a signed-in, non-guest, verified account and the conversation
     * came in through the app or Contact sales.
     *
     * Asked on every turn, not once per conversation, so an opener who loses
     * eligibility mid-thread is answered by staff from then on.
     */
    public function routesToAi(?Model $opener, SupportConversationSource $source): bool
    {
        return $this->container->bound(RespondsToSupport::class)
            && $opener !== null
            && in_array($source, self::AI_SOURCES, true)
            && $this->quota->eligible($opener);
    }

    /**
     * Hand a pending conversation to staff, once.
     *
     * @param  SupportPriority|null  $priority  The priority staff pick it up at; null keeps the current one.
     * @param  int|string|null  $turn  When given, only while this customer message is the newest and still
     *                                 unanswered, so a superseded or answered turn never hands off.
     * @return bool Whether this call handed off; false when the conversation was no longer pending or the
     *              turn moved on, and then nothing was posted or sent.
     */
    public function handoff(
        SupportConversation $conversation,
        SupportHandoffReason $reason,
        ?SupportPriority $priority = null,
        int|string|null $turn = null,
    ): bool {
        // 1. The conditional update decides the winner; the line commits with it, so a loser never sees a
        //    conversation that is open without its line.
        $won = DB::transaction(fn (): bool => $this->moveToStaff($conversation, $reason, $priority, $turn));

        if (! $won) {
            return false;
        }

        // 2. Only the winner tells anyone.
        $this->announceHandoff($conversation);

        return true;
    }

    /**
     * The write half of a handoff: flip a pending conversation to `open` and post the customer-visible line.
     *
     * Runs inside the caller's transaction, so a caller that already holds the
     * row under `lockForUpdate` (the responder persisting an answer that asks
     * for a person) commits the answer and the handoff together. One
     * conditional UPDATE on `status = pending` still decides the winner, so a
     * lock-free {@see self::handoff()} racing it changes nothing. Tells nobody:
     * the winner calls {@see self::announceHandoff()} once the transaction
     * commits.
     *
     * @param  int|string|null  $turn  As in {@see self::handoff()}.
     * @return bool Whether this call changed the conversation and posted the line.
     */
    public function moveToStaff(
        SupportConversation $conversation,
        SupportHandoffReason $reason,
        ?SupportPriority $priority = null,
        int|string|null $turn = null,
    ): bool {
        $now = now();

        $changed = $this->pendingQuery($conversation, $turn)->update([
            'status' => SupportConversationStatus::OPEN->value,
            'waiting_since' => $now,
            'handoff_reason' => $reason->value,
            'last_activity_at' => $now,
            'updated_at' => $now,
            ...($priority !== null ? ['priority' => $priority->value] : []),
        ]);

        if ($changed !== 1) {
            return false;
        }

        $conversation->messages()->create([
            'sender_type' => SupportSenderType::SYSTEM,
            'sender_id' => null,
            'kind' => SupportMessageKind::ACTIVITY,
            'body' => Lang::get('magic-starter::support.ai.handoff', [], $this->locale($conversation)),
        ]);

        return true;
    }

    /**
     * The tell half of a handoff, for the caller that won {@see self::moveToStaff()}, after its commit.
     *
     * Staff hear of it once, the opener's thread refetches to show the line,
     * and the staff assist is prepared before anyone opens the Reply modal.
     * That automatic assist is a model call the customer's own writing
     * triggered, so it is charged to their quota like a turn, under the turn
     * id `assist:{last_customer_message_id}`; a refused charge, an unbound
     * responder or one that cannot name the pool skips it, and staff can still
     * ask for it with Summarize.
     */
    public function announceHandoff(SupportConversation $conversation): void
    {
        $conversation->refresh();
        $this->events->dispatch(new SupportConversationNeedsStaff($conversation, 'handoff'));
        rescue(fn () => $this->events->dispatch(new SupportConversationUpdated($conversation)), report: true);

        if (! $this->container->bound(AssistsSupportStaff::class) || ! $this->assists->claim($conversation)) {
            return;
        }

        if (! $this->chargeAssist($conversation)) {
            $this->assists->release($conversation);

            return;
        }

        PrepareSupportStaffAssist::dispatch($conversation->getKey());
    }

    /**
     * The language the desk writes to the opener in: their own locale, else the application's.
     */
    public function locale(SupportConversation $conversation): string
    {
        $locale = $conversation->user?->getAttribute('locale');

        return is_string($locale) && $locale !== '' ? $locale : (string) config('app.locale', 'en');
    }

    /**
     * Reserve the automatic assist against the opener's quota and the pool the responder names.
     */
    private function chargeAssist(SupportConversation $conversation): bool
    {
        if (! $this->container->bound(RespondsToSupport::class)) {
            return false;
        }

        try {
            $pool = $this->container->make(RespondsToSupport::class)->pool($conversation);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        $turn = 'assist:' . $conversation->last_customer_message_id;

        return $this->quota->reserve($conversation, $turn, $pool) !== null;
    }

    /**
     * @return Builder<SupportConversation>
     */
    private function pendingQuery(SupportConversation $conversation, int|string|null $turn): Builder
    {
        $query = SupportConversation::query()
            ->whereKey($conversation->getKey())
            ->where('status', SupportConversationStatus::PENDING->value);

        if ($turn === null) {
            return $query;
        }

        return $query->where('last_customer_message_id', $turn)
            ->where(function (Builder $unanswered) use ($turn): void {
                $unanswered->whereNull('answered_message_id')->orWhere('answered_message_id', '!=', $turn);
            });
    }
}
