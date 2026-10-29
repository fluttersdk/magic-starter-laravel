<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\AcceptsTeamInvitations;
use FlutterSdk\MagicStarter\Contracts\InvitesTeamMembers;
use FlutterSdk\MagicStarter\Http\Requests\StoreTeamInvitationRequest;
use FlutterSdk\MagicStarter\Http\Resources\TeamInvitationResource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\TeamInvitationRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Handles team invitation CRUD and token-based acceptance.
 */
class TeamInvitationController
{
    /**
     * List all pending invitations for the specified team with pagination.
     */
    public function index(Request $request, string $team): AnonymousResourceCollection
    {
        $teamModel = $this->findTeam($team);
        $user = $request->user();
        Gate::forUser($user)->authorize('manageInvitations', $teamModel);

        $perPage = min(
            (int) $request->input('per_page', 15),
            100,
        );

        return TeamInvitationResource::collection(
            $teamModel->invitations()
                ->latest()
                ->paginate($perPage),
        );
    }

    public function store(StoreTeamInvitationRequest $request, string $team, InvitesTeamMembers $inviter): TeamInvitationResource|JsonResponse
    {
        $teamModel = $this->findTeam($team);
        $user = $request->user();
        Gate::forUser($user)->authorize('manageInvitations', $teamModel);

        $validated = $request->validated();

        if ($teamModel->invitations()->where('email', $validated['email'])->exists()) {
            $message = __('magic-starter::teams.invitations.already_sent');

            return response()->json([
                'message' => $message,
                'errors' => [
                    'email' => [
                        $message,
                    ],
                ],
            ], 422);
        }

        $userModelClass = MagicStarter::userModel();
        $existingUser = $userModelClass::query()->where('email', $validated['email'])->first();

        if ($existingUser && ($teamModel->users()->where('user_id', $existingUser->getKey())->exists() || (string) $teamModel->user_id === (string) $existingUser->getKey())) {
            $message = __('magic-starter::teams.members.already_a_member');

            return response()->json([
                'message' => $message,
                'errors' => ['email' => [$message]],
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

    /**
     * Cancel a team invitation.
     */
    public function destroy(Request $request, string $team, string $invitation): JsonResponse
    {
        $teamModel = $this->findTeam($team);
        $user = $request->user();
        Gate::forUser($user)->authorize('manageInvitations', $teamModel);

        $invitationModelClass = MagicStarter::teamInvitationModel();
        $invitationModel = $invitationModelClass::query()->findOrFail($invitation);
        if ((string) $invitationModel->team_id !== (string) $teamModel->getKey()) {
            abort(404);
        }

        $invitationModel->delete();

        return response()->json([
            'data' => null,
            'message' => __('magic-starter::teams.invitations.canceled'),
        ]);
    }

    /**
     * Accept a team invitation via token.
     *
     * The token and the email match stay here; the join itself, and whatever an
     * application refuses it for, is {@see AcceptsTeamInvitations}. An expired
     * invitation answers 410, and one whose person already belongs answers 200,
     * since they are where the link was taking them. Any other refusal is the
     * binding's own validation error and propagates as its 422.
     */
    public function accept(Request $request, string $token, AcceptsTeamInvitations $accepter): JsonResponse
    {
        $invitationModelClass = MagicStarter::teamInvitationModel();
        $invitation = $invitationModelClass::query()->where('token', $token)->firstOrFail();
        $user = $request->user();

        if ($user->email === null || mb_strtolower($invitation->email) !== mb_strtolower($user->email)) {
            return response()->json([
                'message' => __('magic-starter::teams.invitations.wrong_email'),
            ], 403);
        }

        try {
            $accepter->accept($user, $invitation);
        } catch (TeamInvitationRefused $refusal) {
            return match ($refusal->refusal()) {
                TeamInvitationRefused::EXPIRED => response()->json([
                    'message' => $refusal->getMessage(),
                ], 410),
                TeamInvitationRefused::ALREADY_JOINED => response()->json([
                    'data' => null,
                    'message' => $refusal->getMessage(),
                ]),
                default => throw $refusal,
            };
        }

        return response()->json([
            'data' => null,
            'message' => __('magic-starter::teams.invitations.accepted'),
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
}
