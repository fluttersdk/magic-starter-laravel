<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Contracts\TrashesTeams;
use FlutterSdk\MagicStarter\Events\TeamDeleted;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\SoftDeletingTeams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * Default team deletion action with photo cleanup.
 *
 * This is the purge: it removes the team for good, whether or not the team
 * model soft deletes. The restorable delete is {@see TrashesTeams}, and its
 * `magic-starter:purge-trashed-teams` command ends up here once a window
 * has closed.
 *
 * Dispatches {@see TeamDeleted} once the team is gone. The members are read
 * before the detach, because the event is the only place a listener can still
 * learn who belonged to the team.
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
            Storage::disk(MagicStarter::teamPhotoDisk())->delete((string) $team->profile_photo_path);
        }

        // 2. Remember who belonged to the team: the detach below erases the only record.
        $teamId = (string) $team->getKey();
        $ownerId = (string) $team->getAttribute('user_id');
        $memberIds = $this->memberIds($team, $ownerId);

        // 3. Detach all members and delete invitations.
        $team->users()->detach();
        $team->invitations()->delete();

        // 4. Delete the team for good: on a model that soft deletes, `delete()` would only trash it.
        if (SoftDeletingTeams::usedBy($team)) {
            $team->forceDelete();
        } else {
            $team->delete();
        }

        // 5. Tell the host, after the team is gone. A refusal upstream of this action never reaches here.
        Event::dispatch(new TeamDeleted(
            teamId: $teamId,
            ownerId: $ownerId,
            memberIds: $memberIds,
        ));
    }

    /**
     * Keys of everyone who belongs to the team, the owner first.
     *
     * Ownership lives on `teams.user_id` and writes no membership row of its
     * own, so the owner is added to the rows rather than assumed to be among
     * them.
     *
     * @return list<string>
     */
    private function memberIds(Model $team, string $ownerId): array
    {
        $memberIds = $team->users()
            ->pluck('team_user.user_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return array_values(array_unique([
            $ownerId,
            ...$memberIds,
        ]));
    }
}
