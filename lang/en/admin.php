<?php

return [

    /*
     * The sidebar's groups, in AdminNavigationGroup case order. The plugin's
     * `navigationGroup()` puts every package resource under one name instead.
     */
    'navigation_groups' => [
        'customers' => 'Customers',
        'support' => 'Support',
        'billing' => 'Billing',
        'ai' => 'AI',
        'communication' => 'Communication',
        'operations' => 'Operations',
    ],

    // The code view a payload, a prompt or a trace is shown in.
    'code' => [
        'json' => 'JSON',
        'text' => 'Text',
        'lines' => ':count line|:count lines',
        'copy' => 'Copy',
        'copied' => 'Copied',
    ],

    'two_factor' => [
        // Shown where Filament's profile page lists second factors.
        'managed_in_app' => 'Two-factor authentication is turned on and off in the app. The panel asks for a code at sign in when it is on.',
    ],

];
