<?php

return [

    /*
     * The team invitation mail. A person without an account creates one from the
     * same Accept link, so the mail carries one button and no register button.
     */
    'invitation' => [
        'subject' => 'Team Invitation',
        'greeting' => 'Hello!',
        'invited' => 'You have been invited to join the :team team!',
        'no_account' => 'Select the button below to accept the invitation. '
            . 'If you do not have an account yet, you can create one from the same link.',
        'action' => 'Accept Invitation',
        'discard' => 'If you did not expect to receive an invitation to this team, you may discard this email.',
    ],

    /*
     * The email verification mail.
     */
    'verify' => [
        'subject' => 'Verify Email Address',
        'intro' => 'Please click the button below to verify your email address.',
        'action' => 'Verify Email',
    ],

];
