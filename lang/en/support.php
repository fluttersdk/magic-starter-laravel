<?php

return [

    /*
     * Customer-facing support lines: the topics an opener files under, the AI
     * desk's sender name and handoff line, and when staff usually reply.
     */
    'topics' => [
        'billing' => 'Billing',
        'technical' => 'Technical',
        'account' => 'Account',
        'sales' => 'Sales',
        'other' => 'Other',
    ],

    'attachments' => [
        'daily_limit' => 'You have reached today\'s limit for attachments. Please try again tomorrow.',
    ],

    'rating' => [
        'not_resolved' => 'Only a resolved conversation can be rated.',
    ],

    'feedback' => [
        'subject' => 'Feedback: :score/10',
        'no_comment' => 'Score :score/10, no comment',
    ],

    'ai' => [
        'name' => ':app AI',
        'handoff' => 'I have passed this conversation to our support team. A person will reply here.',
    ],

    /*
     * A staff decision on a billing change the customer asked the AI for, posted
     * into the thread in the conversation's language. The staff refusals live in
     * admin_support.php.
     */
    'proposals' => [
        'approved' => 'A teammate approved your request: :action.',
        'declined' => 'A teammate declined your request: :action.',
        'failed' => 'We could not apply your request (:action). A teammate will follow up here.',
    ],

    /*
     * Package copy only: a mail line is Markdown, so nothing the customer typed ever goes into one.
     */
    'mail' => [
        'ticket_created' => [
            'subject' => 'We received your support request #:number',
            'intro' => 'We received your request #:number and will reply in this conversation.',
            'visitor' => 'Follow the conversation and reply through the link below. Every reply we send brings a '
                . 'new link, which replaces this one.',
            'account' => 'You can follow the conversation and reply in the app.',
            'action' => 'View conversation',
        ],
        'csat_invitation' => [
            'subject' => 'How did we do with request #:number?',
            'intro' => 'Your support request #:number has been resolved.',
            'ask' => 'Rating the conversation takes a single tap and tells us how we did.',
            'action' => 'Rate the conversation',
        ],
    ],

];
