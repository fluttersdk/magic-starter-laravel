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
            'open_panel' => 'Open the admin panel to answer it.',
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
