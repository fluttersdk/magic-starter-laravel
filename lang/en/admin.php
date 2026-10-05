<?php

return [

    /*
     * The navigation group the admin panel's starter resources sit under,
     * unless the plugin is given its own with `navigationGroup()`.
     */
    'navigation_group' => 'Accounts',

    'two_factor' => [
        // Shown where Filament's profile page lists second factors.
        'managed_in_app' => 'Two-factor authentication is turned on and off in the app. The panel asks for a code at sign in when it is on.',
    ],

];
