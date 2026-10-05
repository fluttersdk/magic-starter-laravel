<?php

return [

    'label' => 'User',
    'plural_label' => 'Users',

    'fields' => [
        'name' => 'Name',
        'email' => 'Email',
        'locale' => 'Locale',
        'timezone' => 'Timezone',
        'phone' => 'Phone',
    ],

    'columns' => [
        'name' => 'Name',
        'email' => 'Email',
        'verified' => 'Verified',
        'two_factor_confirmed' => 'Two-factor',
        'guest' => 'Guest',
        'deletion_scheduled' => 'Deletion scheduled',
        'teams' => 'Teams',
        'created_at' => 'Created',
    ],

    'filters' => [
        'verified' => 'Verified',
        'scheduled' => 'Deletion scheduled',
        'guest' => 'Guest',
    ],

    'actions' => [
        'create' => 'New user',
        'edit' => 'Edit',

        'reset_two_factor' => [
            'label' => 'Reset two-factor',
            'description' => 'Removes the second factor. The user can set it up again from their profile.',
            'success' => 'Two-factor authentication reset.',
        ],

        'revoke_tokens' => [
            'label' => 'Revoke all tokens',
            'description' => 'Signs the user out of every device.',
            'success' => 'All tokens revoked.',
        ],

        'schedule_deletion' => [
            'label' => 'Schedule deletion',
            'description' => 'Locks the account now and deletes it once the grace period has passed.',
            'immediately' => 'Delete immediately, without waiting out the grace period',
            'success' => 'Deletion scheduled.',
        ],

        'cancel_deletion' => [
            'label' => 'Cancel deletion',
            'description' => 'Unlocks the account and keeps it.',
            'success' => 'Deletion cancelled.',
            'failure' => 'There was no deletion to cancel.',
        ],

        'resend_verification' => [
            'label' => 'Resend verification email',
            'success' => 'Verification email sent.',
        ],
    ],

    'relations' => [
        'tokens' => [
            'title' => 'API tokens',
            'name' => 'Name',
            'ip_address' => 'IP address',
            'last_used_at' => 'Last used',
            'expires_at' => 'Expires',
            'created_at' => 'Created',
            'revoke' => 'Revoke',
            'revoked' => 'Token revoked.',
        ],

        'social_accounts' => [
            'title' => 'Social accounts',
            'provider' => 'Provider',
            'email' => 'Email at link',
            'owner_confirmed' => 'Confirmed',
            'revoked_at' => 'Revoked',
            'created_at' => 'Linked',
            'disconnect' => 'Disconnect',
            'disconnected' => 'Account disconnected.',
        ],

        'push_devices' => [
            'title' => 'Push devices',
            'subscription_id' => 'Subscription',
            'external_id' => 'External ID',
            'reachability' => 'Reachability',
            'reported_at' => 'Last reported',
            'release' => 'Release',
            'released' => 'Device released.',
        ],

        'teams' => [
            'title' => 'Teams',
            'name' => 'Name',
            'role' => 'Role',
            'personal_team' => 'Personal',
        ],
    ],

];
