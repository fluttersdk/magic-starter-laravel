<?php

return [

    /*
     * The staff support inbox: its navigation, the list, the thread page and
     * the four actions that answer a conversation.
     */
    'navigation_label' => 'Support inbox',
    'model_label' => 'conversation',
    'plural_model_label' => 'conversations',
    'visitor' => 'visitor',
    'thread' => 'Conversation',
    'details' => 'Details',
    'waiting' => 'waiting :time',
    'note_hidden' => 'customer cannot see',
    'overdue' => 'Overdue',

    'fields' => [
        'number' => 'Number',
        'subject' => 'Subject',
        'source' => 'Source',
        'status' => 'Status',
        'priority' => 'Priority',
        'assignee' => 'Assignee',
        'opener' => 'Opener',
        'team' => 'Team',
        'opened' => 'Opened',
        'last_activity' => 'Last activity',
        'topic' => 'Topic',
        'ai_turns' => 'AI turns',
        'ai_failures' => 'AI failures',
        'handoff_reason' => 'Handoff reason',
        'csat' => 'Rating',
        'csat_comment' => 'Rating comment',
        'respond_by' => 'Respond by',
    ],

    'authors' => [
        'internal_note' => 'Internal note',
        'activity' => 'Activity',
        'customer' => 'Customer',
        'staff' => 'Staff',
        'ai' => 'AI',
        'system' => 'System',
        'sent_as_visitor' => 'Sent as a visitor',
    ],

    'enums' => [
        'source' => [
            'in_app' => 'In app',
            'contact_sales' => 'Contact sales',
            'public_form' => 'Public form',
            'feedback' => 'Feedback',
        ],
        'status' => [
            'pending' => 'Pending',
            'open' => 'Open',
            'snoozed' => 'Snoozed',
            'resolved' => 'Resolved',
        ],
        'priority' => [
            'low' => 'Low',
            'normal' => 'Normal',
            'high' => 'High',
            'urgent' => 'Urgent',
        ],
        'handoff_reason' => [
            'human_requested' => 'Customer asked for a person',
            'billing' => 'Billing',
            'security' => 'Security',
            'outage' => 'Outage reported',
            'low_confidence' => 'Low confidence',
            'failures' => 'Repeated AI failures',
            'anger' => 'Customer upset',
            'quota' => 'AI quota spent',
            'unavailable' => 'AI unavailable',
        ],
    ],

    'actions' => [
        'reply' => [
            'label' => 'Reply',
            'body' => 'Reply',
            'sent' => 'Reply sent',
        ],
        'draft_reply' => [
            'label' => 'Draft reply',
        ],
        'summarize' => [
            'label' => 'Summarize',
        ],
        'note' => [
            'label' => 'Add note',
            'body' => 'Note',
            'description' => 'Only staff can read a note. The customer is never told.',
            'added' => 'Note added',
        ],
        'assign' => [
            'label' => 'Assign',
            'unassigned' => 'Unassigned',
            'saved' => 'Assignee saved',
        ],
        'change' => [
            'label' => 'Change status or priority',
            'updated' => 'Conversation updated',
        ],
    ],

    /*
     * The AI summary card, a draft and the lines staff read while one is prepared.
     */
    'ai' => [
        'summary' => 'AI summary',
        'confidence' => 'confidence :value',
        'draft_queued' => 'The draft is being prepared. Open Draft reply again in a minute.',
        'summarize_queued' => 'The summary is being prepared and will appear in the thread.',
    ],

    /*
     * The customer's rating of a resolved conversation.
     */
    'csat' => [
        'up' => 'Thumbs up',
        'down' => 'Thumbs down',
        'none' => 'Not rated',
    ],

    /*
     * Files in the thread and on a staff reply. A PDF is the customer's file and is never opened in the panel.
     */
    'attachments' => [
        'label' => 'Attachments',
        'untrusted_pdf' => 'Download PDF (untrusted)',
        'auto_screenshot' => 'Screenshot captured by the app',
    ],

    /*
     * The customer context beside the thread: what the app sent, what the host app knows, recent account activity.
     */
    'context' => [
        'title' => 'Customer context',
        'client' => 'Client info',
        'customer' => 'Account',
        'activity' => 'Recent activity',
        'no_activity' => 'No recorded activity.',
        'client_keys' => [
            'route' => 'Screen',
            'app_version' => 'App version',
            'build_number' => 'Build',
            'platform' => 'Platform',
            'os_version' => 'OS version',
            'locale' => 'Locale',
            'timezone' => 'Timezone',
        ],
    ],

    /*
     * The read-only list of in-app 0-10 feedback answers.
     */
    'feedback' => [
        'navigation_label' => 'Feedback',
        'model_label' => 'feedback answer',
        'plural_model_label' => 'feedback answers',
        'dismissed' => 'Dismissed',
        'columns' => [
            'score' => 'Score',
            'comment' => 'Comment',
            'user' => 'User',
            'conversation' => 'Conversation',
            'created_at' => 'Date',
        ],
        'bands' => [
            'detractor' => '0 to 6',
            'passive' => '7 to 8',
            'promoter' => '9 to 10',
            'dismissed' => 'Dismissed',
        ],
    ],

    /*
     * The subscription changes proposed inside a conversation.
     */
    'proposals' => [
        'actions' => [
            'extend_trial' => 'Extend the trial',
            'cancel_at_period_end' => 'Cancel at the end of the period',
            'resume' => 'Resume the subscription',
        ],
    ],

    'refusals' => [
        'assignee_not_staff' => 'The assignee must be a support staff member.',
        'thread_url_missing' => 'Set MAGIC_STARTER_SUPPORT_THREAD_URL before replying to a visitor: '
            . 'the reply mail carries the link to the thread.',
    ],

    /*
     * What staff and customers are told when a conversation moves.
     */
    'notifications' => [
        'staff' => [
            'title' => 'Support conversation #:number',
            'opened' => 'A customer opened a new conversation.',
            'customer_wrote' => 'A customer wrote again and is waiting for an answer.',
            'handoff' => 'The AI desk handed a conversation to staff: :reason.',
            'negative_rating' => 'A customer rated a resolved conversation thumbs down.',
            'feedback' => 'A customer left a low score in the in-app feedback.',
            'proposal' => 'The AI desk recorded a billing request that needs a teammate\'s approval.',
            'open_panel' => 'Open the admin panel to answer it.',
            'open_action' => 'Open conversation',
        ],
        'reply' => [
            'title' => 'New reply from support',
            'summary' => 'Your support conversation has a new reply.',
            'mail_subject' => 'Re: [#:number] :subject',
            'mail_intro' => 'Our support team replied to your message:',
            'mail_action' => 'View conversation',
            'mail_footer' => 'This link stops working when a newer reply arrives; use the one in the latest mail.',
        ],
    ],

];
