<?php

return [

    /*
     * Names the package WRITES into a row, rather than sentences it answers
     * with. They are resolved once, at creation, in the locale the account
     * carries at that moment, and the stored value never re-resolves; a team
     * renamed by a later locale change would be a rename the person did not ask
     * for.
     */
    'personal_team_name' => ":name's Team",
    'guest_name' => 'Guest',

    /*
     * Outcomes and refusals on the team itself.
     *
     * `not_a_member` answers two endpoints with the same fact (switching to a
     * team and leaving one), and deliberately with the same sentence: the
     * reader is being told the same thing about the same relationship, and the
     * status code already distinguishes the 403 from the 404.
     */
    'deleted' => 'Team deleted successfully.',
    'switched' => 'Team switched successfully',
    'not_found' => 'The selected team does not exist.',
    'not_a_member' => 'You are not a member of this team.',
    'personal_team_undeletable' => 'You may not delete your personal team.',

    /*
     * Membership. The three owner refusals are separate lines because they
     * refuse three different actions, and only one of them can name a way
     * forward: an owner who wants out has to transfer ownership or delete the
     * team, so that sentence carries the instruction the other two cannot.
     */
    'members' => [
        'updated' => 'Team member updated successfully.',
        'removed' => 'Team member removed successfully.',
        'left' => 'You have left the team.',
        'owner_role_locked' => 'Cannot change role of team owner.',
        'owner_not_removable' => 'Cannot remove team owner.',
        'owner_cannot_leave' => 'Team owner cannot leave the team. Transfer ownership first or delete the team.',
        'user_not_found' => 'The selected user could not be found.',
        'already_a_member' => 'This user is already a member of the team.',
    ],

    /*
     * Invitations, and the two "already a member" lines are two rather than
     * one because they address opposite people. `members.already_a_member`
     * tells an inviter about somebody else; `invitations.already_joined` tells
     * the invited person about themselves. One sentence would be wrong in the
     * second person for whichever half it was not written for.
     */
    'invitations' => [
        'already_sent' => 'An invitation has already been sent to this email.',
        'canceled' => 'Invitation canceled successfully.',
        'wrong_email' => 'This invitation was sent to a different email address.',
        'expired' => 'This invitation has expired.',
        'already_joined' => 'You are already a member of this team.',
        'accepted' => 'Invitation accepted. You have joined the team.',
    ],

];
