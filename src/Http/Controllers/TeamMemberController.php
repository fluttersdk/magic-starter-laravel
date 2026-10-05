<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Actions\RemoveTeamMember;
use FlutterSdk\MagicStarter\Actions\UpdateTeamMemberRole;
use FlutterSdk\MagicStarter\Contracts\RemovesTeamMembers;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeamMemberRoles;
use FlutterSdk\MagicStarter\Enums\Role;
use FlutterSdk\MagicStarter\Http\Requests\UpdateTeamMemberRequest;
use FlutterSdk\MagicStarter\Http\Resources\TeamMemberResource;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Manages team members: listing, adding, updating roles, removing, and leaving.
 *
 * The owner rules live in the default actions. Each endpoint runs the action's
 * guard itself before the contract, so the rule holds whatever an application
 * binds, and maps the action's 422 refusal back to the 403 this API has always
 * answered.
 */
class TeamMemberController
{
    /**
     * List all members of the specified team.
     */
    public function index(string $team): AnonymousResourceCollection
    {
        $teamModel = $this->findTeam($team);
        $user = request()->user();
        Gate::forUser($user)->authorize('view', $teamModel);

        $ownerClass = MagicStarter::userModel();
        $owner = $ownerClass::query()->find($teamModel->user_id);

        if ($owner) {
            $owner->role = Role::OWNER->value;
        }

        $members = $teamModel->users;
        $allMembers = collect([$owner])->merge($members)->unique('id')->filter();

        return TeamMemberResource::collection($allMembers);
    }

    /**
     * Update the role of a team member.
     */
    public function update(UpdateTeamMemberRequest $request, string $team, string $user): JsonResponse
    {
        $teamModel = $this->findTeam($team);
        $member = $this->findUser($user);
        $actor = $request->user();
        Gate::forUser($actor)->authorize('manageMembers', $teamModel);

        $role = (string) $request->validated('role');

        try {
            UpdateTeamMemberRole::ensureAssignable($teamModel, $member, $role);
        } catch (ValidationException $refusal) {
            abort(403, $refusal->getMessage());
        }

        app(UpdatesTeamMemberRoles::class)->update($actor, $teamModel, $member, $role);

        return response()->json(['message' => __('magic-starter::teams.members.updated')]);
    }

    /**
     * Remove a member from the specified team.
     */
    public function destroy(string $team, string $user): JsonResponse
    {
        $remover = app(RemovesTeamMembers::class);
        $teamModel = $this->findTeam($team);
        $member = $this->findUser($user);
        $actor = request()->user();
        Gate::forUser($actor)->authorize('manageMembers', $teamModel);

        try {
            RemoveTeamMember::ensureRemovable($teamModel, $member, leaving: false);
        } catch (ValidationException $refusal) {
            abort(403, $refusal->getMessage());
        }

        $remover->remove($actor, $teamModel, $member);

        return response()->json(['message' => __('magic-starter::teams.members.removed')]);
    }

    /**
     * Allow the authenticated user to leave the specified team.
     */
    public function leave(string $team): JsonResponse
    {
        $remover = app(RemovesTeamMembers::class);
        $teamModel = $this->findTeam($team);
        $user = request()->user();

        // Before the membership check: an owner asking to leave is told why
        // they cannot, not that they are not a member.
        try {
            RemoveTeamMember::ensureRemovable($teamModel, $user, leaving: true);
        } catch (ValidationException $refusal) {
            abort(403, $refusal->getMessage());
        }

        if (! $teamModel->users()->where('user_id', $user->getKey())->exists()) {
            abort(404, (string) __('magic-starter::teams.not_a_member'));
        }

        $remover->remove($user, $teamModel, $user);

        if ((string) $user->current_team_id === (string) $teamModel->getKey()) {
            $nextTeam = $user->allTeams()->where('id', '!=', $teamModel->getKey())->first();
            // current_team_id is kept out of $fillable; forceFill persists it.
            $user->forceFill(['current_team_id' => $nextTeam?->getKey()])->save();
        }

        return response()->json(['message' => __('magic-starter::teams.members.left')]);
    }

    /**
     * Find a team by its ID.
     */
    private function findTeam(string $id): mixed
    {
        $modelClass = MagicStarter::teamModel();

        return $modelClass::query()->findOrFail($id);
    }

    /**
     * Find a user by their ID.
     */
    private function findUser(string $id): mixed
    {
        $modelClass = MagicStarter::userModel();

        return $modelClass::query()->findOrFail($id);
    }
}
