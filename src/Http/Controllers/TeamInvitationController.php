<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\InvitesTeamMembers;
use FlutterSdk\MagicStarter\Http\Requests\StoreTeamInvitationRequest;
use FlutterSdk\MagicStarter\Http\Resources\TeamInvitationResource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\TeamInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Handles team invitation CRUD and token-based acceptance.
 */
class TeamInvitationController
{
    public function index(string $team): AnonymousResourceCollection
    {
        $teamModel = $this->findTeam($team);
        $user = request()->user();
        Gate::forUser($user)->authorize('manageInvitations', $teamModel);

        return TeamInvitationResource::collection($teamModel->invitations);
    }

    public function store(StoreTeamInvitationRequest $request, string $team, InvitesTeamMembers $inviter): TeamInvitationResource|JsonResponse
    {
        $teamModel = $this->findTeam($team);
        $user = $request->user();
        Gate::forUser($user)->authorize('manageInvitations', $teamModel);

        $validated = $request->validated();

        if ($teamModel->invitations()->where('email', $validated['email'])->exists()) {
            return response()->json([
                'message' => 'An invitation has already been sent to this email.',
                'errors' => ['email' => ['An invitation has already been sent to this email.']],
            ], 422);
        }

        $userModelClass = MagicStarter::userModel();
        $existingUser = $userModelClass::query()->where('email', $validated['email'])->first();

        if ($existingUser && ($teamModel->users()->where('user_id', $existingUser->getKey())->exists() || (string) $teamModel->user_id === (string) $existingUser->getKey())) {
            return response()->json([
                'message' => 'This user is already a member of the team.',
                'errors' => ['email' => ['This user is already a member of the team.']],
            ], 422);
        }

        $invitation = $inviter->invite(
            $user,
            $teamModel,
            $validated['email'],
            $validated['role'],
        );

        return new TeamInvitationResource($invitation);
    }

    public function destroy(string $team, string $invitation): JsonResponse
    {
        $teamModel = $this->findTeam($team);
        $user = request()->user();
        Gate::forUser($user)->authorize('manageInvitations', $teamModel);

        $invitationModel = TeamInvitation::query()->findOrFail($invitation);

        if ((string) $invitationModel->team_id !== (string) $teamModel->getKey()) {
            abort(404);
        }

        $invitationModel->delete();

        return response()->json(['message' => 'Invitation canceled successfully.']);
    }

    public function accept(string $token): JsonResponse
    {
        $invitation = TeamInvitation::query()->where('token', $token)->firstOrFail();

        $user = request()->user();

        if (mb_strtolower($invitation->email) !== mb_strtolower($user->email)) {
            return response()->json([
                'message' => 'This invitation was sent to a different email address.',
            ], 403);
        }

        if ($invitation->isExpired()) {
            $invitation->delete();

            return response()->json([
                'message' => 'This invitation has expired.',
            ], 410);
        }
        if ($invitation->team->users()->where('user_id', $user->id)->exists() || (string) $invitation->team->user_id === (string) $user->id) {
            $invitation->delete();

            return response()->json(['message' => 'You are already a member of this team.']);
        }

        $invitation->team->users()->attach($user->id, ['role' => $invitation->role]);
        $invitation->delete();

        return response()->json(['message' => 'Invitation accepted. You have joined the team.']);
    }

    /**
     * Find a team by its ID.
     */
    private function findTeam(string $id): mixed
    {
        $modelClass = MagicStarter::teamModel();

        return $modelClass::query()->findOrFail($id);
    }
}
