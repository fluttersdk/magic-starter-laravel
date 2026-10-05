<?php

return [

    /*
     * The operations tools: Horizon, Pulse and Telescope keep their own
     * dashboards; the panel links to them and summarises them.
     */
    'navigation_group' => 'Operasyon',

    'navigation' => [
        'horizon' => 'Horizon',
        'pulse' => 'Pulse',
        'telescope' => 'Telescope',
        'sentry' => 'Sentry',
    ],

    'horizon' => [
        'status' => 'Kuyruk durumu',
        'running' => 'Çalışıyor',
        'paused' => 'Duraklatıldı',
        'inactive' => 'Etkin değil',
        'recently_failed' => 'Son başarısız işler',
    ],

    'pulse' => [
        'slow_requests' => 'Yavaş istekler (son bir saat)',
        'exceptions' => 'Hatalar (son bir saat)',
    ],

];
