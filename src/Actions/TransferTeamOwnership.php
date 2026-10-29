<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\TransfersTeamOwnership;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeamMemberRoles;
use FlutterSdk\MagicStarter\Enums\Role;
use FlutterSdk\MagicStarter\Events\TeamOwnershipTransferred;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * Default action for handing a team to another of its members.
 *
 * The pivot roles are written here directly rather than through
 * {@see UpdatesTeamMemberRoles}, which refuses both writes a transfer needs:
 * the outgoing owner's role is locked and the `owner` role is not assignable.
 *
 * The outgoing owner stays on the team as an admin; a host whose teams have
 * fewer roles overrides {@see self::outgoingRole()} instead of re-implementing
 * the transfer. Dispatches {@see TeamOwnershipTransferred} once the transfer has
 * committed, which a re-implementation would have to remember to do.
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

        // 1. The rows change together or not at all; the transaction reports who the owner was.
        $previousOwnerId = DB::transaction(function () use ($team, $newOwner): string {
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
                    'role' => $this->outgoingRole()->value,
                ],
            ]);

            $team->users()->updateExistingPivot($newOwner->getKey(), [
                'role' => Role::OWNER->value,
            ]);

            return (string) $outgoing;
        });

        // 2. A transfer to the current owner changed nothing, so there is nothing to tell.
        if ($previousOwnerId === (string) $newOwner->getKey()) {
            return;
        }

        // 3. Tell the host, after the transfer.
        Event::dispatch(new TeamOwnershipTransferred(
            teamId: (string) $team->getKey(),
            previousOwnerId: $previousOwnerId,
            newOwnerId: (string) $newOwner->getKey(),
            actorId: (string) $actor->getAuthIdentifier(),
        ));
    }

    /**
     * The role the outgoing owner keeps on the team.
     *
     * An admin by default. A host whose households have owners and members
     * only overrides this to return {@see Role::MEMBER}. Never {@see Role::OWNER}:
     * the new owner takes that role in the same transfer.
     */
    protected function outgoingRole(): Role
    {
        return Role::ADMIN;
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
