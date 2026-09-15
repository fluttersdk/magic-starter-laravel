<?php

return [

    /*
     * Outcomes of the read-state writes on the in-app notification list.
     */
    'marked_as_read' => 'Notification marked as read.',
    'all_marked_as_read' => 'All notifications marked as read.',
    'deleted' => 'Notification deleted.',

    /*
     * The three refusals the preference matrix raises, and each one names the
     * value that did not match. A person editing their own preferences never
     * sees these (the client builds its switches from the matrix the API
     * publishes, so it cannot ask for a type or a channel that is not in it);
     * the reader is an adopter whose registry and whose client have drifted
     * apart, and the offending identifier is the only thing that tells them
     * which one to fix.
     *
     * `:type` and `:channel` carry the registry's own identifiers, so they stay
     * in their original form in every locale; only the sentence around them
     * changes.
     */
    'preferences' => [
        'unknown_type' => "The notification type ':type' is not registered.",
        'channel_unavailable' => "The channel ':channel' is not available for type ':type'.",
        'channel_locked' => "The channel ':channel' is locked for type ':type' and cannot be changed.",
    ],

    /*
     * The four refusals the self-test push endpoint raises, and they are four
     * because each sends the reader somewhere different: a config key that is
     * off, an account that may not ask, a deployment that cannot deliver push
     * at all, and a provider that did not answer. Collapsing any two would send
     * somebody hunting the wrong one.
     */
    'push_test' => [
        'disabled' => 'The push test endpoint is not enabled for this application.',
        'guest_refused' => 'A guest account cannot send a test push notification.',
        'not_provisioned' => 'Push notifications are not provisioned for this application.',
        'provider_unreachable' => 'The push provider could not be reached.',
    ],

];
