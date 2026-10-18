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

    /*
     * The billing requests the AI desk recorded for the account owner, decided by staff on the thread page.
     */
    'proposals' => [
        'title' => 'Fatura talepleri',
        'actions' => [
            'extend_trial' => 'Deneme süresini uzat',
            'cancel_at_period_end' => 'Dönem sonunda iptal et',
            'resume' => 'Aboneliği sürdür',
        ],
        'columns' => [
            'action' => 'Talep',
            'params' => 'Ayrıntı',
            'status' => 'Durum',
            'requested_by' => 'Talep eden',
            'created_at' => 'Talep zamanı',
            'expires_at' => 'Geçerlilik sonu',
        ],
        'statuses' => [
            'draft' => 'Taslak',
            'pending' => 'Bekliyor',
            'executing' => 'Uygulanıyor',
            'approved' => 'Onaylandı',
            'rejected' => 'Reddedildi',
            'failed' => 'Başarısız',
            'expired' => 'Süresi doldu',
        ],
        'labels' => [
            'until' => ':date tarihine kadar',
            'owner' => ':name (hesap sahibi)',
            'expired' => 'süresi doldu',
            'customer_words' => 'Müşterinin kendi sözleri (doğrulanmadı)',
            'rationale' => 'Yapay zekâ gerekçesi (doğrulanmadı)',
            'trial_ends_at' => 'Mevcut deneme sonu',
            'prior_extensions' => 'Önceki deneme uzatmaları',
            'messages_since' => 'Talepten sonraki müşteri mesajları',
            'reason' => 'Neden',
        ],
        'decisions' => [
            'view' => [
                'label' => 'Ayrıntılar',
            ],
            'approve' => [
                'label' => 'Onayla',
                'heading' => 'Onayla: :action',
                'description' => 'Onay, bu değişikliği müşterinin faturalandırmasına hemen uygular. '
                    . 'Aşağıdaki müşteri sözleri ve yapay zekâ gerekçesi doğrulanmamıştır.',
                'success' => 'Talep onaylandı',
            ],
            'reject' => [
                'label' => 'Reddet',
                'heading' => 'Reddet: :action',
                'description' => 'Müşteriye talebin reddedildiği bildirilir. Yazdığınız neden ekipte kalır.',
                'success' => 'Talep reddedildi',
            ],
            'mark_failed' => [
                'label' => 'Başarısız olarak işaretle',
                'heading' => 'Başarısız olarak işaretle: :action',
                'description' => 'Yalnızca değişikliğin uygulanmadığını ödeme sağlayıcısında doğruladıktan sonra. '
                    . 'Müşteriye bildirilmez; görüşmede siz takip edin.',
                'success' => 'Talep başarısız olarak işaretlendi',
            ],
            'mark_applied' => [
                'label' => 'Uygulandı olarak işaretle',
                'heading' => 'Uygulandı olarak işaretle: :action',
                'description' => 'Yalnızca değişikliğin uygulandığını fatura olaylarında doğruladıktan sonra. '
                    . 'Faturalama yeniden çağrılmaz; müşteriye talebin onaylandığı bildirilir.',
                'success' => 'Talep uygulandı olarak işaretlendi',
            ],
        ],
        'refusals' => [
            'unauthorized' => 'Fatura taleplerine karar verme yetkiniz yok.',
            'in_transaction' => 'Onay bir veritabanı işleminin dışında çalışmalıdır, '
                . 'böylece faturalama değişikliği kendi başına kaydedilir.',
            'already_decided' => 'Bu talep için zaten karar verildi.',
            'expired' => 'Bu talebin süresi karar verilmeden doldu.',
            'no_billable' => 'Görüşmede değiştirilecek bir fatura hesabı artık yok.',
            'billable_changed' => 'Görüşme artık talebin yapıldığı fatura hesabından farklı bir hesaba ait.',
            'owner_changed' => 'Talepte bulunan müşteri artık hesap sahibi değil.',
            'params_stale' => 'İstenen deneme bitiş tarihi mevcut deneme için artık geçerli değil.',
            'not_executing' => 'Yalnızca onayı hâlâ süren bir talep başarısız ya da uygulandı olarak işaretlenebilir.',
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
            'proposal' => 'Yapay zekâ masası, bir ekip arkadaşının onayını bekleyen bir fatura talebi kaydetti.',
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
