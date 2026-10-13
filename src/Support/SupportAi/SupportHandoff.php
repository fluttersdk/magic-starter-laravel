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
 * changes nothing and is told so.
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
        $won = DB::transaction(function () use ($conversation, $reason, $priority, $turn): bool {
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
        });

        if (! $won) {
            return false;
        }

        // 2. Staff hear of it once, the opener's thread refetches to show the line, and the assist is
        //    prepared before anyone opens the Reply modal.
        $conversation->refresh();
        $this->events->dispatch(new SupportConversationNeedsStaff($conversation, 'handoff'));
        rescue(fn () => $this->events->dispatch(new SupportConversationUpdated($conversation)), report: true);

        if ($this->container->bound(AssistsSupportStaff::class) && $this->assists->claim($conversation)) {
            PrepareSupportStaffAssist::dispatch($conversation->getKey());
        }

        return true;
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
