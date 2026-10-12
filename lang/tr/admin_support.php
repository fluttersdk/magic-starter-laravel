<?php

return [

    /*
     * The staff support inbox: its navigation, the list, the thread page and
     * the four actions that answer a conversation.
     */
    'navigation_label' => 'Destek kutusu',
    'model_label' => 'görüşme',
    'plural_model_label' => 'görüşmeler',
    'visitor' => 'ziyaretçi',
    'thread' => 'Görüşme',

    'fields' => [
        'number' => 'Numara',
        'subject' => 'Konu',
        'source' => 'Kaynak',
        'status' => 'Durum',
        'priority' => 'Öncelik',
        'assignee' => 'Atanan kişi',
        'opener' => 'Başlatan',
        'team' => 'Ekip',
        'opened' => 'Açılış',
        'last_activity' => 'Son etkinlik',
    ],

    'authors' => [
        'internal_note' => 'Dahili not',
        'activity' => 'Etkinlik',
        'customer' => 'Müşteri',
        'staff' => 'Ekip üyesi',
        'ai' => 'Yapay zekâ',
        'system' => 'Sistem',
    ],

    'enums' => [
        'source' => [
            'in_app' => 'Uygulama içi',
            'contact_sales' => 'Satışla iletişim',
            'public_form' => 'Herkese açık form',
            'feedback' => 'Geri bildirim',
        ],
        'status' => [
            'pending' => 'Beklemede',
            'open' => 'Açık',
            'snoozed' => 'Ertelendi',
            'resolved' => 'Çözüldü',
        ],
        'priority' => [
            'low' => 'Düşük',
            'normal' => 'Normal',
            'high' => 'Yüksek',
            'urgent' => 'Acil',
        ],
    ],

    'actions' => [
        'reply' => [
            'label' => 'Yanıtla',
            'body' => 'Yanıt',
            'sent' => 'Yanıt gönderildi',
        ],
        'note' => [
            'label' => 'Not ekle',
            'body' => 'Not',
            'description' => 'Notu yalnızca ekip üyeleri okuyabilir. Müşteriye hiçbir şey bildirilmez.',
            'added' => 'Not eklendi',
        ],
        'assign' => [
            'label' => 'Ata',
            'unassigned' => 'Atanmamış',
            'saved' => 'Atanan kişi kaydedildi',
        ],
        'change' => [
            'label' => 'Durumu veya önceliği değiştir',
            'updated' => 'Görüşme güncellendi',
        ],
    ],

    'refusals' => [
        'assignee_not_staff' => 'Atanan kişi bir destek ekibi üyesi olmalıdır.',
        'thread_url_missing' => 'Ziyaretçiye yanıt vermeden önce MAGIC_STARTER_SUPPORT_THREAD_URL ayarlanmalıdır: '
            . 'yanıt e-postası görüşmenin bağlantısını taşır.',
    ],

    /*
     * What staff and customers are told when a conversation moves.
     */
    'notifications' => [
        'staff' => [
            'title' => 'Destek görüşmesi #:number',
            'opened' => 'Bir müşteri yeni bir görüşme başlattı.',
            'customer_wrote' => 'Bir müşteri yeniden yazdı ve yanıt bekliyor.',
            'open_panel' => 'Yanıtlamak için yönetim panelini açın.',
        ],
        'reply' => [
            'title' => 'Destekten yeni yanıt',
            'summary' => 'Destek görüşmenize yeni bir yanıt geldi.',
            'mail_subject' => 'Ynt: [#:number] :subject',
            'mail_intro' => 'Destek ekibimiz mesajınızı yanıtladı:',
            'mail_action' => 'Görüşmeyi görüntüle',
            'mail_footer' => 'Daha yeni bir yanıt geldiğinde bu bağlantı çalışmaz; son e-postadaki bağlantıyı kullanın.',
        ],
    ],

];
