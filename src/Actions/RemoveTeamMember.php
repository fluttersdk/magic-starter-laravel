<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\RemovesTeamMembers;
use FlutterSdk\MagicStarter\Events\TeamMemberRemoved;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * Default action for removing a member from a team.
 *
 * The owner is never removed: a team without its owner is a team nobody can
 * manage or bill. The owner leaves by transferring ownership first.
 *
 * A removed member whose `current_team_id` points at the team is left pointing
 * at nothing, so `getCurrentTeamOrPersonal()` lands them on their personal team
 * instead of the team they were removed from.
 *
 * Dispatches {@see TeamMemberRemoved} when a membership was actually removed.
 */
class RemoveTeamMember implements RemovesTeamMembers
{
    /**
     * Remove the given user from the given team and clear their pointer to it.
     *
     * @param  Authenticatable  $user  The user performing the removal; the same
     *                                 account as `$teamMember` means leaving.
     * @param  Model  $team  The team to remove from.
     * @param  Model  $teamMember  The member being removed.
     *
     * @throws ValidationException When `$teamMember` owns the team.
     */
    public function remove(Authenticatable $user, Model $team, Model $teamMember): void
    {
        self::ensureRemovable(
            $team,
            $teamMember,
            leaving: (string) $user->getAuthIdentifier() === (string) $teamMember->getKey(),
        );

        $detached = $team->users()->detach($teamMember->getKey());

        self::clearCurrentTeam($team, $teamMember);

        // A user who was not on the team lost nothing, so there is nothing to tell.
        if ($detached === 0) {
            return;
        }

        Event::dispatch(new TeamMemberRemoved(
            teamId: (string) $team->getKey(),
            userId: (string) $teamMember->getKey(),
            actorId: (string) $user->getAuthIdentifier(),
        ));
    }

    /**
     * Null the member's `current_team_id` when it is the team they just left.
     *
     * A query-builder update rather than `forceFill()->save()` on `$teamMember`:
     * the column is system-managed and kept out of `$fillable`, which the
     * builder never consults, and the given instance may hold a pointer that
     * has since moved. A member whose pointer is another team is not touched.
     */
    private static function clearCurrentTeam(Model $team, Model $teamMember): void
    {
        $userModel = MagicStarter::userModel();

        $userModel::query()
            ->whereKey($teamMember->getKey())
            ->where('current_team_id', $team->getKey())
            ->update(['current_team_id' => null]);
    }

    /**
     * Refuse removing the team's owner, with the sentence for leaving or for
     * being removed.
     *
     * Public and static so the member endpoints enforce the rule whatever an
     * application binds over {@see RemovesTeamMembers}, as they did before the
     * rule moved here, and so each endpoint keeps its own sentence: removing
     * yourself through the remove endpoint was always "not removable".
     *
     * An owner leaving their PERSONAL team gets its own code: the shared-team
     * sentence tells them to delete the team instead, and a personal team
     * cannot be deleted either.
     *
     * @param  bool  $leaving  Whether the owner is the one asking to go.
     *
     * @throws ValidationException With code `owner_cannot_leave`, `personal_team_cannot_leave`
     *                             or `owner_not_removable`.
     */
    public static function ensureRemovable(Model $team, Model $teamMember, bool $leaving): void
    {
        if ((string) $team->getAttribute('user_id') !== (string) $teamMember->getKey()) {
            return;
        }

        $code = match (true) {
            ! $leaving => 'owner_not_removable',
            (bool) $team->getAttribute('personal_team') => 'personal_team_cannot_leave',
            default => 'owner_cannot_leave',
        };
        $message = (string) __('magic-starter::teams.members.' . $code);

        $exception = ValidationException::withMessages([
            'member' => $message,
        ]);

        $exception->response = new JsonResponse([
            'message' => $message,
            'code' => $code,
            'errors' => $exception->errors(),
        ], 422);

        throw $exception;
    }
}
