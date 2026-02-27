<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Default team deletion action with photo cleanup.
 */
class DeleteTeam implements DeletesTeams
{
    /**
     * Delete the given team and clean up associated resources.
     *
     * @param  Model  $team  The team to delete.
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

        // 3. Delete the team.
        $team->delete();
    }
}
