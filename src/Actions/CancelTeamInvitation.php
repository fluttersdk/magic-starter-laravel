<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\CancelsTeamInvitations;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Default action for cancelling a pending team invitation.
 */
class CancelTeamInvitation implements CancelsTeamInvitations
{
    /**
     * Delete the invitation, so its link no longer joins the team.
     *
     * @param  Authenticatable  $actor  The user cancelling the invitation.
     */
    public function cancel(Authenticatable $actor, Model $invitation): void
    {
        $invitation->delete();
    }
}
