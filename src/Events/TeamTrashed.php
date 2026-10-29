<?php

namespace FlutterSdk\MagicStarter\Events;

use FlutterSdk\MagicStarter\Actions\TrashTeam;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A team has been hidden for its restore window.
 *
 * Dispatched by the default {@see TrashTeam}, once, after the trash has
 * committed. Members stay attached while a team is trashed, so a listener reads
 * them from the trashed row (`withTrashed()`); the deadline is its
 * `restore_until`. A refused trash (the billing guard, a team model without
 * soft deletes) fires nothing. The delete for good that follows the window is
 * {@see TeamDeleted}.
 *
 * Keys only, as strings whatever the key type.
 */
class TeamTrashed implements ShouldDispatchAfterCommit
{
    /**
     * @param  string  $teamId  Key of the team that was hidden.
     */
    public function __construct(
        public readonly string $teamId,
    ) {}
}
