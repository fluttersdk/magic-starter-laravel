<?php

namespace FlutterSdk\MagicStarter\Events;

use FlutterSdk\MagicStarter\Actions\TransferTeamOwnership;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A team now belongs to another of its members.
 *
 * Dispatched by the default {@see TransferTeamOwnership}, once, after the
 * transfer has committed: from a host's own transfer, from the admin panel, and
 * from the account purge when it hands a departing owner's team on (`$actorId`
 * is then the departing owner). A refused transfer (the new owner is not a
 * member) fires nothing, and neither does a transfer to the team's current
 * owner, which changes nothing.
 *
 * Keys only, as strings whatever the key type.
 */
class TeamOwnershipTransferred implements ShouldDispatchAfterCommit
{
    /**
     * @param  string  $teamId  Key of the team that changed hands.
     * @param  string  $previousOwnerId  Key of the owner it left.
     * @param  string  $newOwnerId  Key of the member who owns it now.
     * @param  string  $actorId  Key of the user who performed the transfer.
     */
    public function __construct(
        public readonly string $teamId,
        public readonly string $previousOwnerId,
        public readonly string $newOwnerId,
        public readonly string $actorId,
    ) {}
}
