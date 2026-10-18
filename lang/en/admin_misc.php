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
            'consented_at' => 'Consented at',
            'consent_version' => 'Consent version',
            'consent_source' => 'Consent source',
            'unsubscribed_at' => 'Unsubscribed at',
            'created_at' => 'Subscribed at',
        ],
        'deactivate' => 'Deactivate',
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
            'subject' => 'Subject',
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
            'actor' => 'Actor',
            'system' => 'System',
            'deleted_user' => 'Deleted user',
            'related_user' => 'Related user',
            'created_at' => 'When',
            'changes' => 'Changes',
            'old_values' => 'Before',
            'new_values' => 'After',
            'context' => 'Context',
            'field' => 'Field',
            'value' => 'Value',
        ],
        'relation_title' => 'Audit trail',
    ],

    /*
     * Billing events: the read-only history of what the package decided about
     * a subscriber's plan, and by which path.
     */
    'billing_events' => [
        'navigation_label' => 'Billing events',
        'model_label' => 'billing event',
        'plural_model_label' => 'billing events',
        'columns' => [
            'created_at' => 'When',
            'type' => 'Type',
            'source' => 'Source',
            'provider' => 'Provider',
            'billable' => 'Billable',
            'reason' => 'Reason',
            'external_id' => 'External ID',
            'actor' => 'Actor',
        ],
        'filters' => [
            'type' => 'Type',
            'source' => 'Source',
            'provider' => 'Provider',
            'created_from' => 'From',
            'created_until' => 'Until',
        ],
        'view' => [
            'properties' => 'Properties',
        ],
    ],

    /*
     * Webhook deliveries: the events the Stripe and RevenueCat webhooks claimed
     * as processed, newest first. Retention is the prune command's job.
     */
    'webhook_deliveries' => [
        'navigation_label' => 'Webhook deliveries',
        'model_label' => 'webhook delivery',
        'plural_model_label' => 'webhook deliveries',
        'providers' => [
            'stripe' => 'Stripe',
            'revenuecat' => 'RevenueCat',
        ],
        'columns' => [
            'processed_at' => 'Processed at',
            'provider' => 'Provider',
            'event_id' => 'Event ID',
            'type' => 'Type',
        ],
        'filters' => [
            'provider' => 'Provider',
        ],
        'view' => [
            'billing_events' => 'Billing events',
            'billing_events_note' => 'Only rows recorded under this event id appear. Checkout, plan change and trial'
                . ' check rows key on other ids and are not listed here.',
            'no_billing_events' => 'No billing event was recorded under this id: the delivery changed nothing.',
        ],
    ],

    /*
     * Releases: the notes clients read from the public releases feed. Only a
     * published release with a date is served.
     */
    'releases' => [
        'navigation_label' => 'Releases',
        'model_label' => 'release',
        'plural_model_label' => 'releases',
        'columns' => [
            'version' => 'Version',
            'status' => 'Status',
            'published_at' => 'Published at',
            'ios_build' => 'iOS build',
            'android_build' => 'Android build',
        ],
        'fields' => [
            'version' => 'Version',
            'status' => 'Status',
            'published_at' => 'Published at',
            'ios_build' => 'iOS build',
            'android_build' => 'Android build',
            'items' => 'Release notes',
            'type' => 'Type',
            'text' => 'Text (:locale)',
        ],
        'statuses' => [
            'draft' => 'Draft',
            'published' => 'Published',
        ],
        'item_types' => [
            'new' => 'New',
            'improved' => 'Improved',
            'fixed' => 'Fixed',
        ],
        'actions' => [
            'create' => 'New release',
            'delete' => [
                'label' => 'Delete release',
                'heading' => 'Delete this release?',
                'description' => 'Clients stop seeing it in the release notes. This cannot be undone.',
                'success' => 'Release deleted',
            ],
        ],
    ],

    /*
     * Campaigns: time-boxed Stripe promotion codes offered on some products. The
     * discount shown is the coupon as Stripe described it when the code was last
     * checked on save.
     */
    'campaigns' => [
        'navigation_label' => 'Campaigns',
        'model_label' => 'campaign',
        'plural_model_label' => 'campaigns',
        'columns' => [
            'name' => 'Name',
            'starts_at' => 'Starts at',
            'ends_at' => 'Ends at',
            'enabled' => 'Enabled',
            'discount' => 'Discount',
            'campaign_checkouts' => 'Campaign checkouts',
        ],
        'fields' => [
            'name' => 'Name',
            'enabled' => 'Enabled',
            'starts_at' => 'Starts at',
            'ends_at' => 'Ends at',
            'products' => 'Products',
            'headline' => 'Headline (:locale)',
            'body' => 'Body (:locale)',
            'stripe_promotion_code_id' => 'Promotion code id',
            'discount' => 'Discount (as Stripe described it on the last check)',
        ],
        'filters' => [
            'active_now' => 'Active now',
        ],
        'actions' => [
            'create' => 'New campaign',
        ],
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
