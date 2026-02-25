<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\InvitesTeamMembers;
use FlutterSdk\MagicStarter\Models\TeamInvitation;
use FlutterSdk\MagicStarter\Notifications\TeamInvitationNotification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Default action for inviting a member to a team via email.
 */
class InviteTeamMember implements InvitesTeamMembers
{
    /**
     * Invite a new team member to the given team.
     *
     * @param  Authenticatable  $user  The user performing the invitation.
     * @param  Model  $team  The team to invite the member to.
     * @param  string  $email  The email address to invite.
     * @param  string  $role  The role to assign on acceptance.
     * @return Model The created invitation.
     */
    public function invite(Authenticatable $user, Model $team, string $email, string $role): Model
    {
        /** @var TeamInvitation $invitation */
        $invitation = $team->invitations()->create([
            'email' => $email,
            'role' => $role,
            'token' => Str::random(32),
        ]);

        Notification::route('mail', $email)->notify(new TeamInvitationNotification($invitation));

        return $invitation;
    }
}
