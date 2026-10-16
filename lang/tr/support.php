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

    'rating' => [
        'not_resolved' => 'Yalnızca çözülmüş bir görüşme puanlanabilir.',
    ],

    'feedback' => [
        'subject' => 'Geri bildirim: :score/10',
        'no_comment' => 'Puan :score/10, yorum yok',
    ],

    'ai' => [
        'name' => ':app Yapay Zekâ Asistanı',
        'handoff' => 'Bu görüşmeyi destek ekibimize aktardım. Bir ekip arkadaşımız size buradan yanıt verecek.',
    ],

    /*
     * Package copy only: a mail line is Markdown, so nothing the customer typed ever goes into one.
     */
    'mail' => [
        'ticket_created' => [
            'subject' => '#:number numaralı destek talebinizi aldık',
            'intro' => '#:number numaralı talebinizi aldık ve yanıtımızı bu görüşmede ileteceğiz.',
            'visitor' => 'Görüşmeyi aşağıdaki bağlantıdan takip edip yanıtlayabilirsiniz. Gönderdiğimiz her yanıtla '
                . 'birlikte bu bağlantının yerini alan yeni bir bağlantı gelir.',
            'account' => 'Görüşmeyi uygulamadan takip edip yanıtlayabilirsiniz.',
            'action' => 'Görüşmeyi görüntüleyin',
        ],
        'csat_invitation' => [
            'subject' => '#:number numaralı talebinizi değerlendirin',
            'intro' => 'Destek talebiniz (#:number) çözüldü.',
            'ask' => 'Görüşmeyi tek dokunuşla puanlayarak deneyiminizi bizimle paylaşabilirsiniz.',
            'action' => 'Görüşmeyi puanlayın',
        ],
    ],

];
