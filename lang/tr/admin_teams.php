<?php

return [

    'navigation_label' => 'Takımlar',
    'model_label' => 'takım',
    'plural_model_label' => 'takımlar',

    'fields' => [
        'name' => 'Ad',
    ],

    'columns' => [
        'name' => 'Ad',
        'owner' => 'Sahip',
        'personal' => 'Kişisel',
        'members' => 'Üyeler',
        'plan' => 'Plan',
        'plan_status' => 'Plan durumu',
        'created_at' => 'Oluşturulma',
    ],

    /*
     * Rol etiketleri. Anahtarlar saklanan rol değerleridir; yeni bir atanabilir
     * rol için buraya etiket eklenmelidir, listelenmeyen rol ham değeriyle görünür.
     */
    'roles' => [
        'owner' => 'Sahip',
        'admin' => 'Yönetici',
        'editor' => 'Editör',
        'member' => 'Üye',
    ],

    'actions' => [
        'edit' => 'Düzenle',

        'delete' => [
            'label' => 'Takımı sil',
            'heading' => 'Bu takım silinsin mi?',
            'description' => 'Üyeleri ayrılır ve davetleri kaldırılır. Bu işlem geri alınamaz.',
            'success' => 'Takım silindi.',
        ],
    ],

    'members' => [
        'heading' => 'Üyeler',
        'columns' => [
            'name' => 'Ad',
            'email' => 'E-posta',
            'role' => 'Rol',
        ],
        'fields' => [
            'email' => 'E-posta',
            'role' => 'Rol',
        ],
        'actions' => [
            'add' => [
                'label' => 'Üye ekle',
                'success' => 'Üye eklendi.',
            ],
            'change_role' => [
                'label' => 'Rolü değiştir',
                'success' => 'Rol güncellendi.',
            ],
            'remove' => [
                'label' => 'Çıkar',
                'heading' => 'Bu üye çıkarılsın mı?',
                'success' => 'Üye çıkarıldı.',
            ],
            'make_owner' => [
                'label' => 'Sahip yap',
                'heading' => 'Bu üye takımın sahibi yapılsın mı?',
                'description' => 'Mevcut sahip takımın yöneticisi olur.',
                'success' => 'Sahiplik devredildi.',
            ],
        ],
    ],

    'invitations' => [
        'heading' => 'Davetler',
        'columns' => [
            'email' => 'E-posta',
            'role' => 'Rol',
            'expires_at' => 'Son geçerlilik',
            'status' => 'Durum',
            'created_at' => 'Gönderilme',
        ],
        'status' => [
            'expired' => 'Süresi doldu',
        ],
        'fields' => [
            'email' => 'E-posta',
            'role' => 'Rol',
        ],
        'actions' => [
            'invite' => [
                'label' => 'Davet et',
                'success' => 'Davet gönderildi.',
            ],
            'cancel' => [
                'label' => 'Daveti iptal et',
                'heading' => 'Bu davet iptal edilsin mi?',
                'success' => 'Davet iptal edildi.',
            ],
            'resend' => [
                'label' => 'Yeniden gönder',
                'success' => 'Davet yeniden gönderildi.',
            ],
        ],
    ],

];
