<?php

namespace FlutterSdk\MagicStarter\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use FlutterSdk\MagicStarter\Enums\AiRunStatus;
use FlutterSdk\MagicStarter\Filament\Resources\AiRuns\AiRunResource;
use FlutterSdk\MagicStarter\Models\AiRun;
use stdClass;

/**
 * What the AI run log says over the last 7 and 30 days: how many runs, the
 * share that failed, what they cost and how much of the input the provider
 * served from its cache.
 *
 * The cache hit rate is the cache read tokens over the input tokens of the runs
 * that reported both figures; a run that left either null says nothing about
 * the cache and is left out of both sums. With no such run the rate is a dash,
 * as is the failed share of an empty window. Mounted only with the ai-runs
 * feature.
 */
class AiRunStatsWidget extends StatsOverviewWidget
{
    /**
     * The windows in days, one row of stats each.
     */
    protected const WINDOWS = [7, 30];

    protected int|array|null $columns = 4;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $stats = [];

        foreach (static::WINDOWS as $days) {
            array_push($stats, ...$this->windowStats($days));
        }

        return $stats;
    }

    /**
     * @return list<Stat>
     */
    protected function windowStats(int $days): array
    {
        $totals = $this->totals($days);
        $runs = (int) $totals->runs;

        return [
            Stat::make($this->label('runs', $days), $runs),
            Stat::make($this->label('failed', $days), $this->share((float) $totals->failed, (float) $runs)),
            Stat::make($this->label('cost', $days), AiRunResource::usd((float) $totals->cost, 4)),
            Stat::make(
                $this->label('cache_hit_rate', $days),
                $this->share((float) $totals->cache_read, (float) $totals->cache_input),
            ),
        ];
    }

    /**
     * One aggregate over the runs started inside the window.
     */
    protected function totals(int $days): stdClass
    {
        $qualifies = 'input_tokens is not null and cache_read_tokens is not null';

        /** @var stdClass $totals */
        $totals = AiRun::query()
            ->toBase()
            ->where('started_at', '>=', now()->subDays($days))
            ->selectRaw(
                "count(*) as runs,
                coalesce(sum(case when status = ? then 1 else 0 end), 0) as failed,
                coalesce(sum(cost_usd), 0) as cost,
                coalesce(sum(case when {$qualifies} then input_tokens else 0 end), 0) as cache_input,
                coalesce(sum(case when {$qualifies} then cache_read_tokens else 0 end), 0) as cache_read",
                [AiRunStatus::FAILED->value],
            )
            ->first();

        return $totals;
    }

    /**
     * A percentage such as `33.3%` or `50%`, or a dash when there is nothing to divide by.
     */
    protected function share(float $part, float $whole): string
    {
        if ($whole <= 0) {
            return AiRunResource::ABSENT;
        }

        return rtrim(rtrim(number_format($part / $whole * 100, 1), '0'), '.') . '%';
    }

    protected function label(string $key, int $days): string
    {
        return (string) __("magic-starter::admin_ai.widget.{$key}", ['days' => $days]);
    }
}
