<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Contracts\TrashesTeams;
use FlutterSdk\MagicStarter\Support\SoftDeletingTeams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Default team deletion action with photo cleanup.
 *
 * This is the purge: it removes the team for good, whether or not the team
 * model soft deletes. The restorable delete is {@see TrashesTeams}, and its
 * `magic-starter:purge-trashed-teams` command ends up here once a window
 * has closed.
 */
class DeleteTeam implements DeletesTeams
{
    /**
     * Delete the given team and clean up associated resources.
     *
     * @param  Model  $team  The team to delete, live or trashed.
     */
    public function delete(Model $team): void
    {
        // 1. Delete team photo from storage if present.
        if (! empty($team->profile_photo_path)) {
            Storage::disk(config('magic-starter.profile_photo_disk', 'public'))
                ->delete((string) $team->profile_photo_path);
        }

        // 2. Detach all members and delete invitations.
        $team->users()->detach();
        $team->invitations()->delete();

        // 3. Delete the team for good: on a model that soft deletes, `delete()` would only trash it.
        if (SoftDeletingTeams::usedBy($team)) {
            $team->forceDelete();

            return;
        }

        $team->delete();
    }
}
