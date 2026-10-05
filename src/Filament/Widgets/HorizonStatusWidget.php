<?php

namespace FlutterSdk\MagicStarter\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * Whether Horizon is processing, and how many jobs failed recently.
 *
 * The status follows Horizon's own dashboard: no master supervisor is
 * `inactive`, every master paused is `paused`, anything else is `running`.
 * Mounted only by the plugin's `horizon()`, which has already checked that
 * Horizon is installed.
 */
class HorizonStatusWidget extends StatsOverviewWidget
{
    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $status = $this->status();

        return [
            Stat::make(__('magic-starter::admin_ops.horizon.status'), __("magic-starter::admin_ops.horizon.{$status}"))
                ->color(match ($status) {
                    'running' => 'success',
                    'paused' => 'warning',
                    default => 'danger',
                }),
            Stat::make(
                __('magic-starter::admin_ops.horizon.recently_failed'),
                app(JobRepository::class)->countRecentlyFailed(),
            ),
        ];
    }

    /**
     * @return 'running'|'paused'|'inactive'
     */
    protected function status(): string
    {
        $masters = app(MasterSupervisorRepository::class)->all();

        if ($masters === []) {
            return 'inactive';
        }

        return collect($masters)->every(static fn (object $master): bool => $master->status === 'paused')
            ? 'paused'
            : 'running';
    }
}
