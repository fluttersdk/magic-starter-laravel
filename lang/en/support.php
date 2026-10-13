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

    'ai' => [
        'name' => ':app AI',
        'handoff' => 'I have passed this conversation to our support team. A person will reply here.',
    ],

];
