<?php

return [

    /*
     * The operations tools: Horizon, Pulse and Telescope keep their own
     * dashboards; the panel links to them and summarises them.
     */
    'navigation_group' => 'Operations',

    'navigation' => [
        'horizon' => 'Horizon',
        'pulse' => 'Pulse',
        'telescope' => 'Telescope',
        'sentry' => 'Sentry',
    ],

    'horizon' => [
        'status' => 'Queue status',
        'running' => 'Running',
        'paused' => 'Paused',
        'inactive' => 'Inactive',
        'recently_failed' => 'Recently failed jobs',
    ],

    'pulse' => [
        'slow_requests' => 'Slow requests (last hour)',
        'exceptions' => 'Exceptions (last hour)',
    ],

];
