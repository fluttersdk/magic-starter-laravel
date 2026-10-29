<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\AcceptsTeamInvitations;
use FlutterSdk\MagicStarter\Contracts\InvitesTeamMembers;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Requests\StoreTeamInvitationRequest;
use FlutterSdk\MagicStarter\Http\Resources\TeamInvitationResource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\TeamInvitationRefused;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Handles team invitation CRUD and token-based acceptance.
 */
class TeamInvitationController
{
    /**
     * The domain Sign in with Apple hands out when a person hides their address.
     */
    private const APPLE_RELAY_DOMAIN = 'privaterelay.appleid.com';

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
     *
     * A different email answers 403. With `invitation_confirm_email_mismatch`
     * on, a user who may confirm (an active Sign in with Apple link, or an
     * address on Apple's private relay domain) is asked first: 409 with
     * `code: email_mismatch` and the invited address masked, nothing attached
     * and the invitation untouched. A repeat request with
     * `confirm_email_mismatch: true` goes on to the contract like any other.
     */
    public function accept(Request $request, string $token, AcceptsTeamInvitations $accepter): JsonResponse
    {
        $invitationModelClass = MagicStarter::teamInvitationModel();
        $invitation = $invitationModelClass::query()->where('token', $token)->firstOrFail();
        $user = $request->user();

        if (! $this->isInvitedAddress($invitation, $user->email)) {
            if (! $this->mayConfirmEmailMismatch($user)) {
                return response()->json([
                    'message' => __('magic-starter::teams.invitations.wrong_email'),
                ], 403);
            }

            if (! $request->boolean('confirm_email_mismatch')) {
                return $this->askToConfirmEmailMismatch($invitation);
            }
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
     * Whether `$email` is the address the invitation was sent to, ignoring case.
     */
    private function isInvitedAddress(Model $invitation, ?string $email): bool
    {
        return $email !== null && mb_strtolower($invitation->email) === mb_strtolower($email);
    }

    /**
     * Whether a user whose address is not the invited one may confirm joining anyway.
     *
     * Off unless `invitation_confirm_email_mismatch` is set. Then only the people
     * Sign in with Apple strands: an address on its private relay domain, or an
     * Apple link that has not been revoked. Anyone else was not invited at this
     * address, and no confirmation changes that.
     */
    private function mayConfirmEmailMismatch(Authenticatable $user): bool
    {
        if (! config('magic-starter.invitation_confirm_email_mismatch')) {
            return false;
        }

        return $this->hasAppleRelayAddress($user->email) || $this->hasActiveAppleLink($user);
    }

    /**
     * Whether `$email` sits on Apple's private relay domain, ignoring case.
     *
     * The match is on the whole domain after the `@`, so a lookalike such as
     * `privaterelay.appleid.com.example.test` does not pass.
     */
    private function hasAppleRelayAddress(?string $email): bool
    {
        return $email !== null && str_ends_with(mb_strtolower($email), '@' . self::APPLE_RELAY_DOMAIN);
    }

    /**
     * Whether the user holds a Sign in with Apple link that has not been revoked.
     *
     * An application without social login has no such link and may not have the
     * `social_accounts` table, so it is not asked.
     */
    private function hasActiveAppleLink(Authenticatable $user): bool
    {
        if (! Features::hasSocialLoginFeatures()) {
            return false;
        }

        return MagicStarter::socialAccountModel()::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('provider', 'apple')
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * Answer that joining needs the person's say-so, without touching the invitation.
     *
     * The address is masked to its first character and its domain: the person
     * is not yet known to own it, and the domain is what they need to
     * recognise it as theirs.
     */
    private function askToConfirmEmailMismatch(Model $invitation): JsonResponse
    {
        $email = (string) $invitation->email;

        return response()->json([
            'message' => __('magic-starter::teams.invitations.email_mismatch'),
            'code' => 'email_mismatch',
            'requires_confirmation' => true,
            'masked_email' => mb_substr($email, 0, 1) . '***@' . Str::afterLast($email, '@'),
        ], 409);
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
