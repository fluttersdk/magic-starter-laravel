<?php

return [

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
        'rail_error' => 'Ödeme sağlayıcısına ulaşılamadı; hiçbir şey değişmedi. Biraz sonra yeniden deneyin.',
        'no_subscription' => 'Bu müşterinin Stripe aboneliği yok.',
        'not_trialing' => 'Bu abonelik deneme sürecinde değil.',
        'date_in_past' => 'Yeni deneme bitiş tarihi gelecekte olmalı.',
        'already_cancelled' => 'Bu abonelik zaten iptal edilmiş.',
        'not_on_grace_period' => 'Yalnızca iptal edilmiş ve henüz sona ermemiş bir abonelik sürdürülebilir.',
        'invalid_reason' => 'İade nedenini seçin: müşteri talebi ya da mükerrer ödeme.',
        'nothing_refundable' => 'Bu müşterinin iade edilebilecek ödenmiş bir faturası yok.',
        'nothing_to_sync' => 'Bu müşterinin eşitlenecek bir ödeme sağlayıcısı aboneliği yok.',
        'unmapped_price' => 'Stripe bu müşteriyi faturalama kataloğunda bir plana eşlenmemiş bir fiyattan ücretlendiriyor; hiçbir şey değişmedi.',
    ],

];
