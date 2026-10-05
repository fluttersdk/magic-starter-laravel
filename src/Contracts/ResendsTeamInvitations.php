<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for sending a pending team invitation again.
 */
interface ResendsTeamInvitations
{
    /**
     * Mail the invitation again and restart its expiry window.
     *
     * The token is kept, so a link the invitee already holds keeps working.
     * Refuses nothing, an expired invitation included: resending is how it is
     * revived. Authorizing `$actor` is the caller's job.
     *
     * @param  Authenticatable  $actor  The user resending the invitation.
     */
    public function resend(Authenticatable $actor, Model $invitation): void;
}
