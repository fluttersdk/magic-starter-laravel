<?php

return [

    /*
     * Subscriptions: a read-only view of Cashier's rows. Entitlements are
     * written by the billing rails and the reconciler, never from here.
     */
    'subscriptions' => [
        'navigation_label' => 'Abonelikler',
        'model_label' => 'abonelik',
        'plural_model_label' => 'abonelikler',
        'columns' => [
            'billable' => 'Faturalanan',
            'type' => 'Tür',
            'status' => 'Durum',
            'price' => 'Fiyat',
            'ends_at' => 'Bitiş',
        ],
        'reconcile' => [
            'label' => 'Şimdi uzlaştır',
            'succeeded' => 'Uzlaştırma tamamlandı',
            'failed' => 'Uzlaştırma tüm ödeme kanallarını okuyamadı',
        ],
    ],

    'newsletter' => [
        'navigation_label' => 'Bülten aboneleri',
        'model_label' => 'bülten abonesi',
        'plural_model_label' => 'bülten aboneleri',
        'columns' => [
            'email' => 'E-posta',
            'is_active' => 'Aktif',
            'source' => 'Kaynak',
            'created_at' => 'Abonelik tarihi',
        ],
        'toggle' => [
            'deactivate' => 'Pasifleştir',
            'activate' => 'Aktifleştir',
        ],
        'export' => [
            'label' => 'CSV dışa aktar',
        ],
    ],

    'audits' => [
        'navigation_label' => 'Denetim kayıtları',
        'model_label' => 'denetim kaydı',
        'plural_model_label' => 'denetim kayıtları',
        'columns' => [
            'event' => 'Olay',
            'subject' => 'Konu',
            'actor' => 'İşlemi yapan',
            'created_at' => 'Zaman',
        ],
        'filters' => [
            'event' => 'Olay',
            'subject_type' => 'Konu türü',
            'created_from' => 'Başlangıç',
            'created_until' => 'Bitiş',
        ],
        'view' => [
            'event' => 'Olay',
            'subject_type' => 'Konu türü',
            'subject_id' => 'Konu ID',
            'actor' => 'İşlemi yapan',
            'system' => 'Sistem',
            'deleted_user' => 'Silinmiş kullanıcı',
            'related_user' => 'İlgili kullanıcı',
            'created_at' => 'Zaman',
            'changes' => 'Değişiklikler',
            'old_values' => 'Önce',
            'new_values' => 'Sonra',
            'context' => 'Bağlam',
            'field' => 'Alan',
            'value' => 'Değer',
        ],
        'relation_title' => 'Denetim kaydı',
    ],

    'dashboard' => [
        'stats' => [
            'users' => 'Kullanıcılar',
            'teams' => 'Ekipler',
            'scheduled_deletions' => 'Planlanmış silmeler',
            'active_subscriptions' => 'Aktif abonelikler',
        ],
    ],

];
