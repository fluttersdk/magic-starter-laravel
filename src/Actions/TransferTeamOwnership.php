<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\TransfersTeamOwnership;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeamMemberRoles;
use FlutterSdk\MagicStarter\Enums\Role;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Default action for handing a team to another of its members.
 *
 * The pivot roles are written here directly rather than through
 * {@see UpdatesTeamMemberRoles}, which refuses both writes a transfer needs:
 * the outgoing owner's role is locked and the `owner` role is not assignable.
 */
class TransferTeamOwnership implements TransfersTeamOwnership
{
    /**
     * Make `$newOwner` the owner of `$team`.
     *
     * @param  Authenticatable  $actor  The user performing the transfer.
     *
     * @throws ValidationException When `$newOwner` is not a member of the team.
     */
    public function transfer(Authenticatable $actor, Model $team, Model $newOwner): void
    {
        if (! $team->users()->wherePivot('user_id', $newOwner->getKey())->exists()) {
            $this->refuse('new_owner_not_a_member');
        }

        DB::transaction(function () use ($team, $newOwner): void {
            $outgoing = $team->getAttribute('user_id');

            // user_id is not mass assignable on every team model.
            $team->forceFill([
                'user_id' => $newOwner->getKey(),
                'personal_team' => false,
            ])->save();

            // The outgoing owner first, so a transfer to the current owner
            // leaves them the owner rather than an admin of their own team.
            $team->users()->syncWithoutDetaching([
                $outgoing => [
                    'role' => Role::ADMIN->value,
                ],
            ]);

            $team->users()->updateExistingPivot($newOwner->getKey(), [
                'role' => Role::OWNER->value,
            ]);
        });
    }

    /**
     * @param  string  $code  a key of `magic-starter::teams.members`
     *
     * @throws ValidationException
     */
    private function refuse(string $code): never
    {
        $message = (string) __('magic-starter::teams.members.' . $code);

        $exception = ValidationException::withMessages([
            'owner' => $message,
        ]);

        $exception->response = new JsonResponse([
            'message' => $message,
            'code' => $code,
            'errors' => $exception->errors(),
        ], 422);

        throw $exception;
    }
}
