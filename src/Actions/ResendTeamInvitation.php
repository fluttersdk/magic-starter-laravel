<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\ResendsTeamInvitations;
use FlutterSdk\MagicStarter\Notifications\TeamInvitationNotification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;

/**
 * Default action for sending a pending team invitation again.
 */
class ResendTeamInvitation implements ResendsTeamInvitations
{
    /**
     * Restart the invitation's expiry window and mail it again.
     *
     * The window restarts from now, not from the old `expires_at`: an
     * invitation resent after it lapsed would otherwise go out already expired.
     *
     * @param  Authenticatable  $actor  The user resending the invitation.
     */
    public function resend(Authenticatable $actor, Model $invitation): void
    {
        $invitation->forceFill([
            'expires_at' => now()->addDays((int) config('magic-starter.invitation_expiry_days', 7)),
        ])->save();

        Notification::route('mail', $invitation->getAttribute('email'))
            ->notify(new TeamInvitationNotification($invitation));
    }
}
