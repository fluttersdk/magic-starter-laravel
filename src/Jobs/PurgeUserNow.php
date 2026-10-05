<?php

namespace FlutterSdk\MagicStarter\Jobs;

use FlutterSdk\MagicStarter\Actions\ScheduleUserDeletion;
use FlutterSdk\MagicStarter\Console\PurgeDeletedUsersCommand;
use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeamMemberRoles;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Purges one account without waiting out the grace period: the second half of
 * an immediate deletion, queued by {@see ScheduleUserDeletion} once the
 * account is locked and stamped.
 *
 * It runs the purge command's own per-account decision with a cutoff of now,
 * so everything the purge re-checks is re-checked here: a schedule cleared by
 * a sign-in in the meantime is left alone, a team that gained a member
 * un-schedules the account, a billing subscription holds it, and the rest is
 * deleted through {@see DeletesUsers}.
 *
 * Queued after the scheduling transaction commits, because a team cascade can
 * outlast an HTTP request. The application's queue connection must not be
 * `sync`, or the purge runs inside the request after all.
 *
 * The job carries the key rather than the model, so a duplicate that runs after
 * the account is gone finds nothing and does nothing.
 */
class PurgeUserNow implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * @param  int|string  $userId  the key of the scheduled account
     */
    public function __construct(
        public readonly int|string $userId,
    ) {}

    /**
     * Purge the account, or do nothing when it no longer exists.
     */
    public function handle(
        PurgeDeletedUsersCommand $purge,
        DeletesUsers $deleter,
        UpdatesTeamMemberRoles $roles,
    ): void {
        $userModel = MagicStarter::userModel();

        /** @var Model $prototype */
        $prototype = new $userModel;

        $user = $prototype->newQuery()->find($this->userId);

        if (! $user instanceof Model) {
            return;
        }

        $purge->purge($user, now(), $deleter, $roles);
    }
}
