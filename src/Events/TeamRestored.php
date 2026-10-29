<?php

namespace FlutterSdk\MagicStarter\Events;

use FlutterSdk\MagicStarter\Actions\TrashTeam;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A trashed team has been brought back inside its restore window.
 *
 * Dispatched by the default {@see TrashTeam}, once, after the restore has
 * committed. Restoring a team that is not trashed, or after its window closed,
 * fires nothing.
 *
 * Keys only, as strings whatever the key type.
 */
class TeamRestored implements ShouldDispatchAfterCommit
{
    /**
     * @param  string  $teamId  Key of the team that is live again.
     */
    public function __construct(
        public readonly string $teamId,
    ) {}
}
