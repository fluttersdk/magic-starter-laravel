<?php

return [

    /*
     * The confirmation a person gets after subscribing. Package copy only: a mail line is Markdown.
     */
    'mail' => [
        'subject' => 'You subscribed to our newsletter',
        'intro' => 'You subscribed to product news, and we will send it to this address.',
        'consented_on' => 'You consented on :date.',
        'unsubscribe_action' => 'Unsubscribe',
        'outro' => 'You can leave at any time: the button above and the unsubscribe option in your mail app '
            . 'both work with one click.',
    ],

    /*
     * The public unsubscribe page, opened from a mail link without signing in.
     */
    'page' => [
        'title' => 'Newsletter',
        'confirm_heading' => 'Unsubscribe from the newsletter?',
        'confirm_body' => 'You will stop receiving product news at this address. You can subscribe again from the '
            . 'app at any time.',
        'confirm_button' => 'Unsubscribe',
        'done_heading' => 'You are unsubscribed',
        'done_body' => 'You will not receive product news from us anymore.',
        'not_found_heading' => 'Link not found',
        'not_found_body' => 'This unsubscribe link is not valid.',
    ],

];
