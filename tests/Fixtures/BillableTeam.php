<?php

namespace FlutterSdk\MagicStarter\Tests\Fixtures;

use Laravel\Cashier\Billable;

/**
 * The billable team, carrying Cashier's trait. Its foreign key is pinned to the
 * one the subscriptions migration derived from ConcreteTeam, since this class's
 * own basename would derive another.
 */
class BillableTeam extends ConcreteTeam
{
    use Billable;

    public function getForeignKey(): string
    {
        return 'concrete_team_id';
    }
}
