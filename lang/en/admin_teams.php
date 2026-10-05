<?php

return [

    'navigation_label' => 'Teams',
    'model_label' => 'team',
    'plural_model_label' => 'teams',

    'fields' => [
        'name' => 'Name',
    ],

    'columns' => [
        'name' => 'Name',
        'owner' => 'Owner',
        'personal' => 'Personal',
        'members' => 'Members',
        'plan' => 'Plan',
        'plan_status' => 'Plan status',
        'created_at' => 'Created',
    ],

    /*
     * The role labels. The keys are the stored role values, so a new assignable
     * role needs its label here; an unlisted one renders as its raw value.
     */
    'roles' => [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'editor' => 'Editor',
        'member' => 'Member',
    ],

    'actions' => [
        'delete' => [
            'label' => 'Delete team',
            'heading' => 'Delete this team?',
            'description' => 'Its members are detached and its invitations are removed. This cannot be undone.',
            'success' => 'Team deleted.',
        ],
    ],

    'members' => [
        'heading' => 'Members',
        'columns' => [
            'name' => 'Name',
            'email' => 'Email',
            'role' => 'Role',
        ],
        'fields' => [
            'email' => 'Email',
            'role' => 'Role',
        ],
        'actions' => [
            'add' => [
                'label' => 'Add member',
                'success' => 'Member added.',
            ],
            'change_role' => [
                'label' => 'Change role',
                'success' => 'Role updated.',
            ],
            'remove' => [
                'label' => 'Remove',
                'heading' => 'Remove this member?',
                'success' => 'Member removed.',
            ],
            'make_owner' => [
                'label' => 'Make owner',
                'heading' => 'Make this member the owner?',
                'description' => 'The current owner becomes an admin of the team.',
                'success' => 'Ownership transferred.',
            ],
        ],
    ],

    'invitations' => [
        'heading' => 'Invitations',
        'columns' => [
            'email' => 'Email',
            'role' => 'Role',
            'expires_at' => 'Expires',
            'status' => 'Status',
            'created_at' => 'Sent',
        ],
        'status' => [
            'expired' => 'Expired',
        ],
        'fields' => [
            'email' => 'Email',
            'role' => 'Role',
        ],
        'actions' => [
            'invite' => [
                'label' => 'Invite',
                'success' => 'Invitation sent.',
            ],
            'cancel' => [
                'label' => 'Cancel invitation',
                'heading' => 'Cancel this invitation?',
                'success' => 'Invitation canceled.',
            ],
            'resend' => [
                'label' => 'Resend',
                'success' => 'Invitation sent again.',
            ],
        ],
    ],

];
