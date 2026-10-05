<?php

namespace FlutterSdk\MagicStarter\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Cashier;

/**
 * The headline counts: users, teams, scheduled deletions and active
 * subscriptions, the last two only where their feature is on.
 */
class StarterStatsWidget extends StatsOverviewWidget
{
    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $users = MagicStarter::userModel();

        $stats = [
            Stat::make(__('magic-starter::admin_misc.dashboard.stats.users'), $users::query()->count()),
        ];

        if (Features::hasTeamFeatures()) {
            $teams = MagicStarter::teamModel();

            $stats[] = Stat::make(__('magic-starter::admin_misc.dashboard.stats.teams'), $teams::query()->count());
        }

        $stats[] = Stat::make(
            __('magic-starter::admin_misc.dashboard.stats.scheduled_deletions'),
            $users::query()->whereNotNull('deletion_scheduled_at')->count(),
        );

        if (Features::hasBillingFeatures()) {
            /** @var class-string<Model> $subscriptions */
            $subscriptions = Cashier::$subscriptionModel;

            $stats[] = Stat::make(
                __('magic-starter::admin_misc.dashboard.stats.active_subscriptions'),
                $subscriptions::query()->scopes(['active'])->count(),
            );
        }

        return $stats;
    }
}
