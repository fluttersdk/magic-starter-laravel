<?php

return [

    'title' => 'Faturalama',

    'summary' => [
        'heading' => 'Özet',
        'plan' => 'Plan',
        'status' => 'Durum',
        'provider' => 'Sağlayıcı',
        'period_end' => 'Dönem bitişi',
        'grant' => 'Manuel plan',
        'grant_expires' => 'Manuel plan bitişi',
        'no_expiry' => 'Süresiz',
        'trial_ends' => 'Stripe deneme bitişi',
        'none' => '-',
    ],

    'fields' => [
        'plan' => 'Plan',
        'reason' => 'Neden',
        'expires_at' => 'Son geçerli gün',
        'expires_at_help' => 'Plan bu günün sonuna kadar geçerlidir. '
            . 'Geri alınana kadar sürsün diye boş bırakın.',
        'until' => 'Deneme bitiş tarihi',
        'refund_reason' => 'İade nedeni',
    ],

    'refund_reasons' => [
        'requested_by_customer' => 'Müşteri talebi',
        'duplicate' => 'Mükerrer ödeme',
    ],

    'actions' => [
        'grant' => [
            'label' => 'Plan ver',
            'heading' => 'Plan ver',
            'description' => 'Müşteri bu planı ödeme olmadan, bitiş tarihine ya da geri alınana kadar kullanır.',
            'success' => 'Plan verildi.',
        ],
        'revoke' => [
            'label' => 'Planı geri al',
            'heading' => 'Manuel planı geri al',
            'description' => 'Müşteri verilen planı kaybeder; varsa ücretli abonelik yeniden kayda geçer.',
            'success' => 'Plan geri alındı.',
        ],
        'extend_trial' => [
            'label' => 'Denemeyi uzat',
            'heading' => 'Stripe denemesini uzat',
            'description' => 'Stripe deneme bitişini ileri alır; müşteri o tarihe kadar ücretlendirilmez.',
            'success' => 'Deneme uzatıldı.',
        ],
        'end_trial' => [
            'label' => 'Denemeyi bitir',
            'heading' => 'Stripe denemesini şimdi bitir',
            'description' => 'Stripe denemeyi bitirir ve müşteriye hemen fatura keser.',
            'success' => 'Deneme bitirildi.',
        ],
        'cancel_subscription' => [
            'label' => 'Aboneliği iptal et',
            'heading' => 'Dönem sonunda iptal et',
            'description' => 'Abonelik ödenmiş dönem bitene kadar planını korur, sonra yenilenmez.',
            'success' => 'Abonelik dönem sonunda iptal edilecek.',
        ],
        'resume_subscription' => [
            'label' => 'Aboneliği sürdür',
            'heading' => 'Aboneliği sürdür',
            'description' => 'İptal kaldırılır ve abonelik eskisi gibi yenilenir.',
            'success' => 'Abonelik sürdürüldü.',
        ],
        'refund' => [
            'label' => 'Son faturayı iade et',
            'heading' => 'Son ödenmiş faturayı iade et',
            'description' => 'Stripe :invoice faturası için :amount tutarının tamamını iade eder. '
                . 'Abonelik olduğu gibi kalır.',
            'success' => 'Fatura iade edildi.',
        ],
        'sync' => [
            'label' => 'Şimdi eşitle',
            'heading' => 'Ödeme sağlayıcısıyla eşitle',
            'description' => 'Sağlayıcı şimdi okunur ve müşterinin planı onun söylediğine göre güncellenir.',
            'success' => 'Faturalama eşitlendi.',
        ],
    ],

    /*
     * Why an operator's billing action was refused. The key is the stable
     * reason `BillingAdministrationRefused::reason()` carries and the
     * `request_refused` row records; the sentence is what the panel shows.
     */
    'refusals' => [
        'paid_rail_active' => 'Bu müşterinin etkin bir ücretli aboneliği var; plan vermeden önce onu iptal edin.',
        'unknown_plan' => 'Bu plan faturalama kataloğunda yok.',
        'expiry_in_past' => 'Bitiş tarihi gelecekte olmalı.',
        'entitlement_refused' => 'Faturalama kuralları bu değişikliği reddetti; müşterinin planı değişmedi.',
        'not_manual' => 'Bu plan bir yönetici tarafından verilmedi; bunun yerine aboneliği iptal edin.',
        'rail_error' => 'Ödeme sağlayıcısı bir hata döndürdü. '
            . 'Yeniden denemeden önce aboneliği sağlayıcıda kontrol edin.',
        'no_subscription' => 'Bu müşterinin Stripe aboneliği yok.',
        'not_trialing' => 'Bu abonelik deneme sürecinde değil.',
        'date_in_past' => 'Yeni deneme bitiş tarihi gelecekte olmalı.',
        'not_later' => 'Yeni deneme bitiş tarihi mevcut bitişten sonra olmalı.',
        'already_cancelled' => 'Bu abonelik zaten iptal edilmiş.',
        'not_on_grace_period' => 'Yalnızca iptal edilmiş ve henüz sona ermemiş bir abonelik sürdürülebilir.',
        'scheduled_cancel' => 'Bu abonelik belirli bir tarihte iptal edilecek; sağlayıcıda sürdürün.',
        'invalid_reason' => 'İade nedenini seçin: müşteri talebi ya da mükerrer ödeme.',
        'nothing_refundable' => 'Bu müşterinin iade edilebilecek ödenmiş bir faturası yok.',
        'stale_target' => 'İade açıldığından beri daha yeni bir fatura ödendi; '
            . 'neyi iade edeceğini görmek için yeniden açın.',
        'nothing_to_sync' => 'Bu müşterinin eşitlenecek bir ödeme sağlayıcısı aboneliği yok.',
        'unmapped_price' => 'Stripe bu müşteriyi faturalama kataloğunda bir plana eşlenmemiş bir fiyattan '
            . 'ücretlendiriyor; hiçbir şey değişmedi.',
    ],

];
