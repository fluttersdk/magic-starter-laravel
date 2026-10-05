<?php

return [

    'label' => 'Kullanıcı',
    'plural_label' => 'Kullanıcılar',

    'fields' => [
        'name' => 'Ad',
        'email' => 'E-posta',
        'locale' => 'Dil',
        'timezone' => 'Saat dilimi',
        'phone' => 'Telefon',
    ],

    'columns' => [
        'name' => 'Ad',
        'email' => 'E-posta',
        'verified' => 'Doğrulanmış',
        'two_factor_confirmed' => 'İki adımlı doğrulama',
        'guest' => 'Misafir',
        'deletion_scheduled' => 'Silme planlandı',
        'teams' => 'Takımlar',
        'created_at' => 'Oluşturulma',
    ],

    'filters' => [
        'verified' => 'Doğrulanmış',
        'scheduled' => 'Silme planlandı',
        'guest' => 'Misafir',
    ],

    'actions' => [
        'create' => 'Yeni kullanıcı',
        'edit' => 'Düzenle',

        'reset_two_factor' => [
            'label' => 'İki adımlı doğrulamayı sıfırla',
            'description' => 'İkinci adımı kaldırır. Kullanıcı profilinden yeniden kurabilir.',
            'success' => 'İki adımlı doğrulama sıfırlandı.',
        ],

        'revoke_tokens' => [
            'label' => 'Tüm token\'ları iptal et',
            'description' => 'Kullanıcının tüm cihazlardaki oturumunu kapatır.',
            'success' => 'Tüm token\'lar iptal edildi.',
        ],

        'schedule_deletion' => [
            'label' => 'Silmeyi planla',
            'description' => 'Hesabı hemen kilitler ve bekleme süresi dolunca siler.',
            'immediately' => 'Bekleme süresini beklemeden hemen sil',
            'success' => 'Silme planlandı.',
        ],

        'cancel_deletion' => [
            'label' => 'Silmeyi iptal et',
            'description' => 'Hesabın kilidini açar ve hesabı korur.',
            'success' => 'Silme iptal edildi.',
            'failure' => 'İptal edilecek bir silme yoktu.',
        ],

        'resend_verification' => [
            'label' => 'Doğrulama e-postasını yeniden gönder',
            'success' => 'Doğrulama e-postası gönderildi.',
        ],
    ],

    'relations' => [
        'tokens' => [
            'title' => 'API token\'ları',
            'name' => 'Ad',
            'ip_address' => 'IP adresi',
            'last_used_at' => 'Son kullanım',
            'expires_at' => 'Bitiş',
            'created_at' => 'Oluşturulma',
            'revoke' => 'İptal et',
            'revoked' => 'Token iptal edildi.',
        ],

        'social_accounts' => [
            'title' => 'Sosyal hesaplar',
            'provider' => 'Sağlayıcı',
            'email' => 'Bağlanırkenki e-posta',
            'owner_confirmed' => 'Onaylı',
            'revoked_at' => 'İptal',
            'created_at' => 'Bağlanma',
            'disconnect' => 'Bağlantıyı kes',
            'disconnected' => 'Hesap bağlantısı kesildi.',
        ],

        'push_devices' => [
            'title' => 'Push cihazları',
            'subscription_id' => 'Abonelik',
            'external_id' => 'Harici kimlik',
            'reachability' => 'Erişilebilirlik',
            'reported_at' => 'Son bildirim',
            'release' => 'Serbest bırak',
            'released' => 'Cihaz serbest bırakıldı.',
        ],

        'teams' => [
            'title' => 'Takımlar',
            'name' => 'Ad',
            'role' => 'Rol',
            'personal_team' => 'Kişisel',
        ],
    ],

];
