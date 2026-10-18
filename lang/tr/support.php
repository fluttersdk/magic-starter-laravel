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
     * A staff decision on a billing change the customer asked the AI for. The
     * three lines are posted into the thread in the conversation's language;
     * the refusals are what the deciding staff member reads in the panel.
     */
    'proposals' => [
        'approved' => 'Bir ekip arkadaşımız talebinizi onayladı: :action.',
        'declined' => 'Bir ekip arkadaşımız talebinizi reddetti: :action.',
        'failed' => 'Talebinizi uygulayamadık (:action). Bir ekip arkadaşımız size buradan dönüş yapacak.',
        'refusals' => [
            'unauthorized' => 'Fatura taleplerine karar verme yetkiniz yok.',
            'in_transaction' => 'Onay bir veritabanı işleminin dışında çalışmalıdır, '
                . 'böylece faturalama değişikliği kendi başına kaydedilir.',
            'already_decided' => 'Bu talep için zaten karar verildi.',
            'expired' => 'Bu talebin süresi onaylanmadan doldu.',
            'no_billable' => 'Görüşmede değiştirilecek bir fatura hesabı artık yok.',
            'billable_changed' => 'Görüşme artık talebin yapıldığı fatura hesabından farklı bir hesaba ait.',
            'owner_changed' => 'Talepte bulunan müşteri artık hesap sahibi değil.',
            'params_stale' => 'İstenen deneme bitiş tarihi mevcut deneme için artık geçerli değil.',
            'not_executing' => 'Yalnızca onayı hâlâ süren bir talep başarısız olarak işaretlenebilir.',
        ],
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
