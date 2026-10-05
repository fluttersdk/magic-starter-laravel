<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\RemovesTeamMembers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Default action for removing a member from a team.
 *
 * The owner is never removed: a team without its owner is a team nobody can
 * manage or bill. The owner leaves by transferring ownership first.
 */
class RemoveTeamMember implements RemovesTeamMembers
{
    /**
     * Remove the given user from the given team.
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

        $team->users()->detach($teamMember->getKey());
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
     * @param  bool  $leaving  Whether the owner is the one asking to go.
     *
     * @throws ValidationException With code `owner_cannot_leave` or `owner_not_removable`.
     */
    public static function ensureRemovable(Model $team, Model $teamMember, bool $leaving): void
    {
        if ((string) $team->getAttribute('user_id') !== (string) $teamMember->getKey()) {
            return;
        }

        $code = $leaving ? 'owner_cannot_leave' : 'owner_not_removable';
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
