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
    'details' => 'Ayrıntılar',
    'waiting' => ':time bekliyor',
    'note_hidden' => 'müşteri göremez',
    'overdue' => 'Gecikti',
    'ai_summary' => 'Önceki mesajların yapay zeka özeti',
    'ai_summary_hint' => 'Görüşmeyi kısa tutmak için yapay zeka yazar. Bir kayıt değil, bir özettir.',

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
        'topic' => 'Konu başlığı',
        'ai_turns' => 'Yapay zekâ turları',
        'ai_failures' => 'Yapay zekâ hataları',
        'handoff_reason' => 'Devir nedeni',
        'csat' => 'Değerlendirme',
        'csat_comment' => 'Değerlendirme yorumu',
        'respond_by' => 'Son yanıt zamanı',
    ],

    'authors' => [
        'internal_note' => 'Dahili not',
        'activity' => 'Etkinlik',
        'customer' => 'Müşteri',
        'staff' => 'Ekip üyesi',
        'ai' => 'Yapay zekâ',
        'system' => 'Sistem',
        'sent_as_visitor' => 'Ziyaretçi olarak gönderildi',
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
        'handoff_reason' => [
            'human_requested' => 'Müşteri bir kişiyle görüşmek istedi',
            'billing' => 'Faturalama',
            'security' => 'Güvenlik',
            'outage' => 'Kesinti bildirildi',
            'low_confidence' => 'Düşük güven',
            'failures' => 'Yapay zekâ tekrar tekrar başarısız oldu',
            'anger' => 'Müşteri memnun değil',
            'quota' => 'Yapay zekâ kotası doldu',
            'unavailable' => 'Yapay zekâ kullanılamıyor',
        ],
    ],

    'actions' => [
        'reply' => [
            'label' => 'Yanıtla',
            'body' => 'Yanıt',
            'sent' => 'Yanıt gönderildi',
        ],
        'draft_reply' => [
            'label' => 'Taslak yanıt',
        ],
        'summarize' => [
            'label' => 'Özetle',
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

    /*
     * The AI summary card, a draft and the lines staff read while one is prepared.
     */
    'ai' => [
        'summary' => 'Yapay zekâ özeti',
        'confidence' => 'güven :value',
        'draft_queued' => 'Taslak hazırlanıyor. Bir dakika sonra Taslak yanıt\'ı yeniden açın.',
        'summarize_queued' => 'Özet hazırlanıyor ve görüşmede görünecek.',
    ],

    /*
     * The customer's rating of a resolved conversation.
     */
    'csat' => [
        'up' => 'Beğendi',
        'down' => 'Beğenmedi',
        'none' => 'Değerlendirilmedi',
    ],

    /*
     * Files in the thread and on a staff reply. A PDF is the customer's file and is never opened in the panel.
     */
    'attachments' => [
        'label' => 'Ekler',
        'untrusted_pdf' => 'PDF indir (güvenilmeyen dosya)',
        'auto_screenshot' => 'Uygulamanın aldığı ekran görüntüsü',
    ],

    /*
     * The customer context beside the thread: what the app sent, what the host app knows, recent account activity.
     */
    'context' => [
        'title' => 'Müşteri bilgileri',
        'client' => 'Uygulama bilgisi',
        'customer' => 'Hesap',
        'activity' => 'Son etkinlikler',
        'no_activity' => 'Kayıtlı etkinlik yok.',
        'client_keys' => [
            'route' => 'Ekran',
            'app_version' => 'Uygulama sürümü',
            'build_number' => 'Derleme',
            'platform' => 'Platform',
            'os_version' => 'İşletim sistemi sürümü',
            'locale' => 'Dil',
            'timezone' => 'Saat dilimi',
        ],
    ],

    /*
     * The read-only list of in-app 0-10 feedback answers.
     */
    'feedback' => [
        'navigation_label' => 'Geri bildirimler',
        'model_label' => 'geri bildirim',
        'plural_model_label' => 'geri bildirimler',
        'dismissed' => 'Kapatıldı',
        'columns' => [
            'score' => 'Puan',
            'comment' => 'Yorum',
            'user' => 'Kullanıcı',
            'conversation' => 'Görüşme',
            'created_at' => 'Tarih',
        ],
        'bands' => [
            'detractor' => '0 ile 6 arası',
            'passive' => '7 ile 8 arası',
            'promoter' => '9 ile 10 arası',
            'dismissed' => 'Kapatıldı',
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
            'handoff' => 'Yapay zekâ masası bir görüşmeyi ekibe devretti: :reason.',
            'negative_rating' => 'Bir müşteri çözülmüş bir görüşmeyi olumsuz puanladı.',
            'feedback' => 'Bir müşteri uygulama içi geri bildirimde düşük puan verdi.',
            'open_panel' => 'Yanıtlamak için yönetim panelini açın.',
            'open_action' => 'Görüşmeyi açın',
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
