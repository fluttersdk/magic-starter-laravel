<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for accepting a team invitation.
 */
interface AcceptsTeamInvitations
{
    /**
     * Join the invitation's team as the invited user, and consume the invitation.
     *
     * Returns only after a join. Every other outcome is a refusal, so the
     * caller never has to ask afterwards whether the user got in. Whether
     * `$user` is the person the invitation was sent to is the caller's job
     * and is checked before this runs. When `invitation_confirm_email_mismatch`
     * is on, a user whose email differs from the invitation's can reach this
     * method after confirming, so an implementation must not treat `$user->email`
     * as verified by the invitation.
     *
     * An implementation joins inside one transaction with the team row locked,
     * so two accepts for the same team cannot both pass a check that counts
     * its members.
     *
     * @param  Authenticatable  $user  The invited user; matched to the invitation's email, or a confirmed mismatch.
     * @param  Model  $invitation  The invitation being accepted.
     *
     * @throws \FlutterSdk\MagicStarter\Support\TeamInvitationRefused When the invitation has expired
     *                                                                (`expired`) or the user already belongs
     *                                                                to the team (`already_joined`); either way the
     *                                                                invitation is deleted, because it can never
     *                                                                be used.
     * @throws \Illuminate\Validation\ValidationException When an implementation refuses the join (a plan's
     *                                                    member cap, say); nothing is attached and the
     *                                                    invitation stays, so it can be retried once the
     *                                                    refusal no longer holds.
     */
    public function accept(Authenticatable $user, Model $invitation): void;
}
