<?php

return [

    /*
     * The sidebar's groups, in AdminNavigationGroup case order. The plugin's
     * `navigationGroup()` puts every package resource under one name instead.
     */
    'navigation_groups' => [
        'customers' => 'Müşteriler',
        'support' => 'Destek',
        'billing' => 'Faturalama',
        'ai' => 'Yapay zekâ',
        'communication' => 'İletişim',
        'operations' => 'Operasyon',
    ],

    // The code view a payload, a prompt or a trace is shown in.
    'code' => [
        'json' => 'JSON',
        'text' => 'Metin',
        'lines' => ':count satır|:count satır',
        'copy' => 'Kopyala',
        'copied' => 'Kopyalandı',
    ],

    'two_factor' => [
        // Shown where Filament's profile page lists second factors.
        'managed_in_app' => 'İki adımlı doğrulama uygulamadan açılıp kapatılır. Açıksa panel girişte kod ister.',
    ],

];
