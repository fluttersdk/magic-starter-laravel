<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Contracts\TrashesTeams;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\SoftDeletingTeams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Default restorable team delete: trash a team for a window, restore it inside
 * that window.
 *
 * Members stay attached, the stock a team owns is not touched, and the photo
 * stays on its disk, so a restore brings back the team as it was. Everything
 * irreversible is {@see DeletesTeams}' job, which the
 * `magic-starter:purge-trashed-teams` command runs once the window has closed.
 *
 * Two things change beyond the team itself. The active-team pointer of everyone
 * on it is cleared: `users.current_team_id` is a foreign key with
 * `nullOnDelete`, but a soft delete is an update and never triggers it, so the
 * pointer would go on naming a team nobody can see. A restore does not put it
 * back, because the pointer is the person's choice and they have been somewhere
 * else since. And the pending invitations are cancelled: an invitation resolves
 * its team through the model's soft delete scope, so accepting one to a hidden
 * team would find no team, and a link to a household its owner deleted should
 * not work in any case. A restore does not bring them back; the owner invites
 * again.
 */
class TrashTeam implements TrashesTeams
{
    /**
     * Hide the team for the restore window, and clear everyone's pointer to it.
     *
     * @param  Model  $team  A team of a model that uses {@see SoftDeletes}.
     * @param  int|null  $restoreDays  Length of the window in days; null reads `magic-starter.teams.restore_days`.
     *
     * @throws LogicException When the team model does not use SoftDeletes.
     */
    public function trash(Model $team, ?int $restoreDays = null): void
    {
        $this->requireSoftDeletes($team);

        $days = max(0, $restoreDays ?? (int) config('magic-starter.teams.restore_days'));

        DB::transaction(function () use ($team, $days): void {
            // 1. Before the team is hidden, in the same transaction, so no one is
            //    left pointing at a team that has gone.
            $this->clearCurrentTeam($team);

            // 2. A link to a hidden team must not be acceptable.
            $team->invitations()->delete();

            // 3. The deadline is written first because a soft delete writes only
            //    `deleted_at`; a team is never hidden without a window.
            $team->forceFill(['restore_until' => now()->addDays($days)])->save();

            // 4. Hide it.
            $team->delete();
        });
    }

    /**
     * Bring a trashed team back while its window is open.
     *
     * The window is closed at its deadline, not after it: the purge deletes a
     * team whose `restore_until` is not in the future, so the two never
     * overlap on the same instant.
     *
     * @param  Model  $team  A trashed team of a model that uses {@see SoftDeletes}.
     *
     * @throws ValidationException When the window has closed.
     * @throws LogicException When the team model does not use SoftDeletes.
     */
    public function restore(Model $team): void
    {
        $this->requireSoftDeletes($team);

        // 1. A team that is already live has nothing to restore, which also
        //    answers a client that sends the request twice.
        if (! $team->trashed()) {
            return;
        }

        // 2. A closed window is final: the purge may delete the team at any moment.
        $deadline = $team->getAttribute('restore_until');

        if ($deadline !== null && ! Carbon::parse($deadline)->isFuture()) {
            throw ValidationException::withMessages([
                'team' => __('magic-starter::teams.restore_window_closed'),
            ])->errorBag('restoreTeam');
        }

        // 3. The deadline goes with `deleted_at`, in one write.
        $team->forceFill(['restore_until' => null])->restore();
    }

    /**
     * Null `current_team_id` of every user whose active team is the given one.
     *
     * A query-builder update on the configured user model: the column is
     * system-managed and kept out of `$fillable`, which the builder never
     * consults, and it reaches users who are not loaded anywhere.
     */
    private function clearCurrentTeam(Model $team): void
    {
        $userModel = MagicStarter::userModel();

        $userModel::query()
            ->where('current_team_id', $team->getKey())
            ->update(['current_team_id' => null]);
    }

    /**
     * @throws LogicException When the team model would delete the team for good instead of trashing it.
     */
    private function requireSoftDeletes(Model $team): void
    {
        if (SoftDeletingTeams::usedBy($team)) {
            return;
        }

        throw new LogicException(sprintf(
            'The team model [%s] must use %s to be trashed and restored.',
            $team::class,
            SoftDeletes::class,
        ));
    }
}
