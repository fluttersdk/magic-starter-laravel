<?php

namespace FlutterSdk\MagicStarter\Filament\Widgets;

use Carbon\CarbonInterval;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;
use Laravel\Pulse\Contracts\Storage;

/**
 * Slow requests and exceptions Pulse recorded in the last hour.
 *
 * Read through Pulse's storage contract, the same aggregate its own cards use,
 * so the numbers match the Pulse dashboard. Mounted only by the plugin's
 * `pulse()`, which has already checked that Pulse is installed.
 */
class PulseSummaryWidget extends StatsOverviewWidget
{
    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        /** @var Collection<string, int> $counts */
        $counts = app(Storage::class)->aggregateTotal(
            [
                'slow_request',
                'exception',
            ],
            'count',
            CarbonInterval::hour(),
        );

        return [
            Stat::make(__('magic-starter::admin_ops.pulse.slow_requests'), (int) $counts->get('slow_request', 0)),
            Stat::make(__('magic-starter::admin_ops.pulse.exceptions'), (int) $counts->get('exception', 0)),
        ];
    }
}
