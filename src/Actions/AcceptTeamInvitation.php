<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\AcceptsTeamInvitations;
use FlutterSdk\MagicStarter\Events\TeamMemberJoined;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\TeamInvitationRefused;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * Default action for accepting a team invitation.
 *
 * The join runs in one transaction with the team row locked, so the check an
 * application adds in {@see self::ensureCanJoin()} (a plan's member cap, which
 * counts the team's members) and the attach that follows it cannot interleave
 * with another accept for the same team. Without the lock two invitations
 * accepted together both count the same members, both pass, and the team ends
 * one over its cap. The lock is the team's row rather than the invitation's
 * because it is the team's membership being counted.
 *
 * Bind a subclass over {@see AcceptsTeamInvitations} and override
 * `ensureCanJoin()`; the rest of the flow stays the package's.
 *
 * Dispatches {@see TeamMemberJoined} for a join that went through, from inside
 * the transaction: the event waits for the commit, and a join that rolls back
 * never reaches a listener.
 */
class AcceptTeamInvitation implements AcceptsTeamInvitations
{
    /**
     * Join the invitation's team as `$user` and consume the invitation.
     *
     * @param  Authenticatable  $user  The invited user, already matched to the invitation's email.
     * @param  Model  $invitation  The invitation being accepted.
     *
     * @throws TeamInvitationRefused When the invitation expired or the user already belongs to the team.
     * @throws ValidationException When {@see self::ensureCanJoin()} refuses.
     */
    public function accept(Authenticatable $user, Model $invitation): void
    {
        // 1. An expired invitation can never join anyone. It is deleted here, before the transaction,
        // so the refusal below cannot roll the delete back.
        if ($invitation->isExpired()) {
            $invitation->delete();

            throw TeamInvitationRefused::because(TeamInvitationRefused::EXPIRED);
        }

        // 2. Join under the team's row lock. A refusal from the hook rolls everything back, which leaves
        // the invitation in place; a user who already belongs is not attached twice, and only their
        // stale invitation is cleared.
        $joined = DB::transaction(function () use ($user, $invitation): bool {
            $team = $this->lockTeam($invitation);

            if ($this->isMember($team, $user)) {
                $invitation->delete();

                return false;
            }

            $this->ensureCanJoin($team, $user);

            $team->users()->attach($user->getAuthIdentifier(), ['role' => $invitation->role]);
            $invitation->delete();

            Event::dispatch(new TeamMemberJoined(
                teamId: (string) $team->getKey(),
                userId: (string) $user->getAuthIdentifier(),
                actorId: (string) $user->getAuthIdentifier(),
            ));

            return true;
        });

        // 3. Refuse outside the transaction, so the invitation delete above commits with it.
        if (! $joined) {
            throw TeamInvitationRefused::because(TeamInvitationRefused::ALREADY_JOINED);
        }
    }

    /**
     * Read the invitation's team again with its row locked.
     *
     * A fresh read, not the invitation's cached relation: the locked row is
     * the one `ensureCanJoin()` may trust for the rest of the transaction.
     */
    protected function lockTeam(Model $invitation): Model
    {
        $teamModel = MagicStarter::teamModel();

        return $teamModel::query()->lockForUpdate()->findOrFail($invitation->team_id);
    }

    /**
     * Whether `$user` already belongs to `$team`.
     *
     * Ownership lives on `teams.user_id` and writes no pivot row, so the
     * owner counts as a member although the pivot has nothing for them.
     */
    protected function isMember(Model $team, Authenticatable $user): bool
    {
        $userId = $user->getAuthIdentifier();

        return (string) $team->user_id === (string) $userId
            || $team->users()->where('user_id', $userId)->exists();
    }

    /**
     * Refuse a join the application does not allow, such as a plan's member cap.
     *
     * Runs inside the transaction with `$team` locked and freshly read, after
     * the already-a-member check and before the attach, so a count taken here
     * is still true when the attach commits. Throw a {@see ValidationException}
     * to refuse (give it a stable `code`, the way `RemoveTeamMember` does):
     * the transaction rolls back and the invitation stays. Open by default.
     *
     * @param  Model  $team  The team being joined, locked for the rest of the transaction.
     * @param  Authenticatable  $user  The user about to join.
     *
     * @throws ValidationException
     */
    protected function ensureCanJoin(Model $team, Authenticatable $user): void
    {
        // Open by default; an application's subclass decides who may join.
    }
}
