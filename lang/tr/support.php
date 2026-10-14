<?php

return [

    /*
     * Customer-facing support lines: the topics an opener files under, the AI
     * desk's sender name and handoff line, and when staff usually reply.
     */
    'topics' => [
        'billing' => 'Faturalandırma',
        'technical' => 'Teknik',
        'account' => 'Hesap',
        'sales' => 'Satış',
        'other' => 'Diğer',
    ],

    'attachments' => [
        'daily_limit' => 'Bugünkü ek sınırına ulaştınız. Lütfen yarın tekrar deneyin.',
    ],

    'ai' => [
        'name' => ':app Yapay Zekâ Asistanı',
        'handoff' => 'Bu görüşmeyi destek ekibimize aktardım. Bir ekip arkadaşımız size buradan yanıt verecek.',
    ],

];
