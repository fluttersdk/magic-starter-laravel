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
     * A staff decision on a billing change the customer asked the AI for. The
     * three lines are posted into the thread in the conversation's language;
     * the refusals are what the deciding staff member reads in the panel.
     */
    'proposals' => [
        'approved' => 'A teammate approved your request: :action.',
        'declined' => 'A teammate declined your request: :action.',
        'failed' => 'We could not apply your request (:action). A teammate will follow up here.',
        'refusals' => [
            'unauthorized' => 'You are not allowed to decide billing requests.',
            'in_transaction' => 'Approval must run outside a database transaction; '
                . 'the panel must not wrap actions in databaseTransactions().',
            'already_decided' => 'This request has already been decided.',
            'expired' => 'This request expired before it was approved.',
            'no_billable' => 'The conversation no longer has a billing account to change.',
            'billable_changed' => 'The conversation now belongs to another billing account than the one '
                . 'the request was made for.',
            'owner_changed' => 'The customer who asked is no longer the account owner.',
            'params_stale' => 'The requested trial end is no longer valid for the current trial.',
            'not_executing' => 'Only a request whose approval is still running can be marked as failed.',
        ],
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
