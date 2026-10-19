<?php

namespace FlutterSdk\MagicStarter\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportCsatRating;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Filament\Resources\AiRuns\AiRunResource;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use stdClass;

/**
 * How many support conversations the AI desk settled without a person, over the
 * last 7 days against the 7 before.
 *
 * A conversation is AI-routed when the AI desk wrote in it or handed it to staff.
 * It is resolved without a human when it was never handed off, no staff member
 * wrote in it, and it is either resolved or has seen no activity for
 * {@see self::IDLE_AFTER_HOURS} hours: the AI desk never resolves a conversation
 * itself, so an answer the customer stopped replying to is the closest honest
 * reading. A staff member who resolves without writing a message still counts
 * as the AI's, because nothing on the row tells the two apart.
 *
 * Conversations belong to the window they were opened in. The rating is the
 * thumbs on the conversation (`csat_rating`), not the 0-10 `support_feedback`
 * card, which is a separate scale and does not hang on an AI conversation.
 * Mounted only with the support feature.
 */
class SupportResolutionWidget extends StatsOverviewWidget
{
    /**
     * The length of the window and of the one it is compared with, in days.
     */
    protected const DAYS = 7;

    /**
     * How long an unanswered conversation sits before it counts as settled.
     */
    protected const IDLE_AFTER_HOURS = 24;

    /**
     * How many handoff reasons the handed-off figure names.
     */
    protected const TOP_REASONS = 3;

    protected int|array|null $columns = 4;

    /**
     * No polling: the figures span days, and each refresh runs every aggregate again.
     */
    protected ?string $pollingInterval = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $now = now();
        $windowStart = $now->copy()->subDays(static::DAYS);
        $previousStart = $windowStart->copy()->subDays(static::DAYS);

        $current = $this->totals($windowStart, null, $now);
        $previous = $this->totals($previousStart, $windowStart, $now);

        return [
            Stat::make($this->label('routed'), (int) $current->routed)
                ->description($this->previous((int) $previous->routed)),
            Stat::make($this->label('resolved'), (int) $current->resolved)
                ->description($this->previous((int) $previous->resolved)),
            Stat::make($this->label('handed_off'), (int) $current->handed_off)
                ->description($this->handedOffDescription($windowStart, (int) $previous->handed_off)),
            Stat::make($this->label('csat'), $this->csat($current))
                ->description($this->previous($this->csat($previous))),
        ];
    }

    /**
     * One aggregate over the AI-routed conversations opened in `[$from, $until)`, or since `$from` for a null end.
     */
    protected function totals(Carbon $from, ?Carbon $until, Carbon $now): stdClass
    {
        $staffWrote = 'exists (select 1 from support_messages'
            . ' where support_messages.conversation_id = support_conversations.id'
            . ' and support_messages.sender_type = ?)';

        /** @var stdClass $totals */
        $totals = SupportConversation::query()
            ->toBase()
            ->where('created_at', '>=', $from)
            ->when($until !== null, fn (Builder $query) => $query->where('created_at', '<', $until))
            ->where(function (Builder $query): void {
                $query->whereNotNull('handoff_reason')->orWhereExists(function (Builder $messages): void {
                    $messages->selectRaw('1')
                        ->from('support_messages')
                        ->whereColumn('support_messages.conversation_id', 'support_conversations.id')
                        ->where('support_messages.sender_type', SupportSenderType::AI->value);
                });
            })
            ->selectRaw(
                "count(*) as routed,
                coalesce(sum(case when handoff_reason is null and not {$staffWrote}
                    and (status = ? or last_activity_at < ?) then 1 else 0 end), 0) as resolved,
                coalesce(sum(case when handoff_reason is not null then 1 else 0 end), 0) as handed_off,
                coalesce(sum(case when csat_rating = ? then 1 else 0 end), 0) as csat_up,
                coalesce(sum(case when csat_rating = ? then 1 else 0 end), 0) as csat_down",
                [
                    SupportSenderType::STAFF->value,
                    SupportConversationStatus::RESOLVED->value,
                    $now->copy()->subHours(static::IDLE_AFTER_HOURS),
                    SupportCsatRating::UP->value,
                    SupportCsatRating::DOWN->value,
                ],
            )
            ->first();

        return $totals;
    }

    /**
     * The most common handoff reasons of the window, such as `Billing: 2, Outage reported: 1`, then the
     * previous window's count.
     */
    protected function handedOffDescription(Carbon $windowStart, int $previous): string
    {
        $reasons = SupportConversation::query()
            ->toBase()
            ->where('created_at', '>=', $windowStart)
            ->whereNotNull('handoff_reason')
            ->selectRaw('handoff_reason, count(*) as total')
            ->groupBy('handoff_reason')
            ->orderByDesc('total')
            ->orderBy('handoff_reason')
            ->limit(static::TOP_REASONS)
            ->pluck('total', 'handoff_reason')
            ->map(fn (int|string $total, string $reason): string => (string) __(
                "magic-starter::admin_support.enums.handoff_reason.{$reason}",
            ) . ": {$total}")
            ->implode(', ');

        return $reasons === '' ? $this->previous($previous) : "{$reasons} | {$this->previous($previous)}";
    }

    /**
     * The share of rated conversations that got a thumbs up and a thumbs down, or a dash when none was rated.
     */
    protected function csat(stdClass $totals): string
    {
        $up = (int) $totals->csat_up;
        $rated = $up + (int) $totals->csat_down;

        if ($rated === 0) {
            return AiRunResource::ABSENT;
        }

        $upShare = (int) round($up / $rated * 100);

        return (string) __('magic-starter::admin_support.resolution.csat_value', [
            'up' => $upShare,
            'down' => 100 - $upShare,
        ]);
    }

    protected function previous(int|string $value): string
    {
        return (string) __('magic-starter::admin_support.resolution.previous', [
            'days' => static::DAYS,
            'value' => $value,
        ]);
    }

    protected function label(string $key): string
    {
        return (string) __("magic-starter::admin_support.resolution.{$key}", ['days' => static::DAYS]);
    }
}
