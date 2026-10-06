<?php

return [

    /*
     * Subscriptions: a read-only view of Cashier's rows. Entitlements are
     * written by the billing rails and the reconciler, never from here.
     */
    'subscriptions' => [
        'navigation_label' => 'Subscriptions',
        'model_label' => 'subscription',
        'plural_model_label' => 'subscriptions',
        'columns' => [
            'billable' => 'Billable',
            'type' => 'Type',
            'status' => 'Status',
            'price' => 'Price',
            'ends_at' => 'Ends at',
        ],
        'reconcile' => [
            'label' => 'Reconcile now',
            'succeeded' => 'Reconciliation finished',
            'failed' => 'Reconciliation could not read every billing rail',
        ],
    ],

    'newsletter' => [
        'navigation_label' => 'Newsletter subscribers',
        'model_label' => 'newsletter subscriber',
        'plural_model_label' => 'newsletter subscribers',
        'columns' => [
            'email' => 'Email',
            'is_active' => 'Active',
            'source' => 'Source',
            'created_at' => 'Subscribed at',
        ],
        'toggle' => [
            'deactivate' => 'Deactivate',
            'activate' => 'Activate',
        ],
        'export' => [
            'label' => 'Export CSV',
        ],
    ],

    'audits' => [
        'navigation_label' => 'Audit trail',
        'model_label' => 'audit entry',
        'plural_model_label' => 'audit trail',
        'columns' => [
            'event' => 'Event',
            'subject_type' => 'Subject type',
            'subject_id' => 'Subject ID',
            'actor' => 'Actor',
            'created_at' => 'When',
        ],
        'filters' => [
            'event' => 'Event',
            'subject_type' => 'Subject type',
            'created_from' => 'From',
            'created_until' => 'Until',
        ],
        'view' => [
            'event' => 'Event',
            'subject_type' => 'Subject type',
            'subject_id' => 'Subject ID',
            'actor_type' => 'Actor type',
            'actor_id' => 'Actor ID',
            'related_user' => 'Related user',
            'created_at' => 'When',
            'old_values' => 'Before',
            'new_values' => 'After',
            'context' => 'Context',
            'field' => 'Field',
            'value' => 'Value',
        ],
        'relation_title' => 'Audit trail',
    ],

    'dashboard' => [
        'stats' => [
            'users' => 'Users',
            'teams' => 'Teams',
            'scheduled_deletions' => 'Scheduled deletions',
            'active_subscriptions' => 'Active subscriptions',
        ],
    ],

];
