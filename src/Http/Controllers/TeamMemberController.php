<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\RemovesTeamMembers;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeamMemberRoles;
use FlutterSdk\MagicStarter\Enums\Role;
use FlutterSdk\MagicStarter\Http\Requests\UpdateTeamMemberRequest;
use FlutterSdk\MagicStarter\Http\Resources\TeamMemberResource;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Manages team members: listing, adding, updating roles, removing, and leaving.
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

        if ((string) $teamModel->user_id === (string) $member->getKey()) {
            abort(403, 'Cannot change role of team owner.');
        }

        app(UpdatesTeamMemberRoles::class)->update(
            $actor,
            $teamModel,
            $member,
            $request->validated('role'),
        );

        return response()->json([
            'data' => null,
            'message' => 'Team member updated successfully.',
        ]);
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

        if ((string) $teamModel->user_id === (string) $member->getKey()) {
            abort(403, 'Cannot remove team owner.');
        }

        $remover->remove($actor, $teamModel, $member);

        return response()->json([
            'data' => null,
            'message' => 'Team member removed successfully.',
        ]);
    }

    /**
     * Allow the authenticated user to leave the specified team.
     */
    public function leave(string $team): JsonResponse
    {
        $remover = app(RemovesTeamMembers::class);
        $teamModel = $this->findTeam($team);
        $user = request()->user();

        if ((string) $teamModel->user_id === (string) $user->getKey()) {
            abort(403, 'Team owner cannot leave the team. Transfer ownership first or delete the team.');
        }

        if (! $teamModel->users()->where('user_id', $user->getKey())->exists()) {
            abort(404, 'You are not a member of this team.');
        }

        $remover->remove($user, $teamModel, $user);

        if ((string) $user->current_team_id === (string) $teamModel->getKey()) {
            $nextTeam = $user->allTeams()->where('id', '!=', $teamModel->getKey())->first();
            $user->update(['current_team_id' => $nextTeam?->getKey()]);
        }

        return response()->json([
            'data' => null,
            'message' => 'You have left the team.',
        ]);
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
