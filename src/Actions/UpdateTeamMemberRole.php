<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\TransfersTeamOwnership;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeamMemberRoles;
use FlutterSdk\MagicStarter\Enums\Role;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Default action for updating a team member's role.
 *
 * Ownership is `teams.user_id` plus the `owner` pivot role, so neither half is
 * written here: the owner's role is locked, and the `owner` role is given only
 * by {@see TransfersTeamOwnership}, which moves both halves together.
 */
class UpdateTeamMemberRole implements UpdatesTeamMemberRoles
{
    /**
     * Update the role of the given team member.
     *
     * @param  Authenticatable  $user  The user performing the action.
     * @param  Model  $team  The team the member belongs to.
     * @param  Model  $teamMember  The member whose role is being updated.
     * @param  string  $role  One of {@see Role::assignable()}.
     *
     * @throws ValidationException When `$teamMember` owns the team, or `$role`
     *                             is not assignable.
     */
    public function update(Authenticatable $user, Model $team, Model $teamMember, string $role): void
    {
        self::ensureAssignable($team, $teamMember, $role);

        $team->users()->updateExistingPivot($teamMember->getKey(), [
            'role' => $role,
        ]);
    }

    /**
     * Refuse changing the owner's role, and refuse handing out the `owner` role.
     *
     * Public and static so the role endpoint enforces the rule whatever an
     * application binds over {@see UpdatesTeamMemberRoles}, as it did before
     * the rule moved here.
     *
     * @throws ValidationException With code `owner_role_locked` or `role_not_assignable`.
     */
    public static function ensureAssignable(Model $team, Model $teamMember, string $role): void
    {
        if ((string) $team->getAttribute('user_id') === (string) $teamMember->getKey()) {
            self::refuse('owner_role_locked', 'member');
        }

        if (! in_array($role, Role::assignable(), true)) {
            self::refuse('role_not_assignable', 'role');
        }
    }

    /**
     * @param  string  $code  a key of `magic-starter::teams.members`
     * @param  string  $field  the input the message is reported under
     *
     * @throws ValidationException
     */
    private static function refuse(string $code, string $field): never
    {
        $message = (string) __('magic-starter::teams.members.' . $code);

        $exception = ValidationException::withMessages([
            $field => $message,
        ]);

        $exception->response = new JsonResponse([
            'message' => $message,
            'code' => $code,
            'errors' => $exception->errors(),
        ], 422);

        throw $exception;
    }
}
