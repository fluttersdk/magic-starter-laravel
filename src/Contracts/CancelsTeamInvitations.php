<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for cancelling a pending team invitation.
 */
interface CancelsTeamInvitations
{
    /**
     * Cancel the invitation, so its link no longer joins the team.
     *
     * Refuses nothing; authorizing `$actor` is the caller's job.
     *
     * @param  Authenticatable  $actor  The user cancelling the invitation.
     */
    public function cancel(Authenticatable $actor, Model $invitation): void;
}
