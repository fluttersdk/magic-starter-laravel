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
    ],

];
