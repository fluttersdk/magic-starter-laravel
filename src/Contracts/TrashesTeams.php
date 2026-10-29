<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Contract for the restorable half of a team delete.
 *
 * Trashing hides a team for a window and keeps everything it owns; restoring
 * brings it back while the window is open. {@see DeletesTeams} is the other
 * half: it deletes a team for good, and the `magic-starter:purge-trashed-teams`
 * command calls it once the window has closed.
 */
interface TrashesTeams
{
    /**
     * Hide the team for the restore window.
     *
     * The team is soft deleted and `restore_until` marks the end of the
     * window. Members stay attached, every user whose active team is this one
     * is left without one, and the pending invitations are cancelled.
     *
     * @param  Model  $team  A team of a model that uses SoftDeletes.
     * @param  int|null  $restoreDays  Length of the window in days; null reads `magic-starter.teams.restore_days`.
     *
     * @throws LogicException When the team model does not use SoftDeletes.
     */
    public function trash(Model $team, ?int $restoreDays = null): void;

    /**
     * Bring a trashed team back.
     *
     * A team that is not trashed is left as it is. A trashed team with no
     * `restore_until` was not trashed through this contract and has no window to
     * close, so it is restored.
     *
     * @param  Model  $team  A trashed team of a model that uses SoftDeletes.
     *
     * @throws ValidationException When the window has closed: `restore_until` is not in the future.
     * @throws LogicException When the team model does not use SoftDeletes.
     */
    public function restore(Model $team): void;
}
