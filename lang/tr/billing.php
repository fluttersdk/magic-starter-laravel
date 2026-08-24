<?php

return [

    /*
     * Human names for the neutral billing-rail vocabulary, keyed by
     * BillingProvider value and read by BillingProvider::label().
     *
     * The two store names stay in their original form on purpose: they are the
     * brands a customer sees on the receipt and inside the store app itself, so
     * translating them would name something that does not exist.
     */
    'providers' => [
        'none' => 'Ücretlendirilmiyor',
        'stripe' => 'Kart',
        'app_store' => 'App Store',
        'play_store' => 'Google Play',
        'manual' => 'Elle tanımlandı',
    ],

    /*
     * Human names for the neutral plan-lifecycle vocabulary, keyed by
     * PlanStatus value and read by PlanStatus::label().
     *
     * Written from the CUSTOMER's side: both dunning statuses still entitle, so
     * they read as a payment problem rather than as a lost plan.
     */
    'statuses' => [
        'none' => 'Plan yok',
        'trialing' => 'Deneme',
        'active' => 'Etkin',
        'past_due' => 'Ödeme bekliyor',
        'grace' => 'Ödeme yeniden deneniyor',
        'canceled' => 'İptal edildi',
        'expired' => 'Süresi doldu',
        'paused' => 'Duraklatıldı',
    ],

    /*
     * Human names for the two billing cycles, keyed by the wire word. See the
     * English file for why they exist: `unmapped_price` names the cycle, and the
     * wire word is English.
     */
    'cycles' => [
        'monthly' => 'aylık',
        'annual' => 'yıllık',
    ],

    /*
     * Refusal sentences the billing actions and endpoints raise, keyed by a
     * short reason. Shipped here rather than inlined so every reader gets the
     * same wording in their own locale, and so a test can assert the two
     * locales actually differ instead of one silently carrying the other's
     * text.
     */
    'refusals' => [
        'store_subscription_active' => 'Bu takımı hâlâ bir mağaza aboneliği faturalandırıyor. Önce satın aldığınız mağaza hesabından aboneliği iptal edin: takımı şimdi silmek planı kaldırır, mağaza ise sizi ücretlendirmeye devam eder ve bu uygulama bunu sizin yerinize iptal edemez.',

        /*
         * The two 409 sentences the billing endpoints raise, and they are
         * deliberately two rather than one: "manage this where you bought it"
         * and "there is nothing to manage yet" are opposite instructions, so a
         * shared sentence would leave the customer guessing which one they got.
         * Each travels beside a machine-readable reason, so the client never
         * parses the prose to decide.
         */
        'managed_by_store' => 'Bu abonelik onu satan mağaza tarafından yönetiliyor ve buradan değiştirilemez.',
        'no_billing_account' => 'Yönetilecek bir ödeme hesabı henüz yok.',
        'subscription_exists' => 'Aboneliğiniz henüz sona ermedi, bu yüzden ikinci bir abonelik almak yerine planınızı değiştirin.',

        /*
         * The three refusals the card-rail WRITES raise. Two of them describe a
         * gap in the adopter's own configuration rather than a fault in the
         * request, so they name the key that closes it; the key names stay in
         * their original form because they are literal config paths and a
         * translated one would point at nothing.
         *
         * 'no_published_catalogue' names BOTH keys because either one answers:
         * the ranking falls back to the catalogue's entry ids when no explicit
         * order is published.
         */
        'no_published_catalogue' => 'Yayımlanmış bir plan yok, dolayısıyla henüz satın alınabilecek bir şey de yok. Bir plan satmak için magic-starter.billing.plans ya da magic-starter.billing.tier_order anahtarını yayımlayın.',
        'unmapped_price' => 'Bu planı :cycle döngüsünde satan bir Stripe fiyatı yok. magic-starter.billing.prices altında bir fiyat tanımlayın veya diğer döngüyü sunun.',
        'no_subscription' => 'Değiştirilecek etkin bir abonelik yok.',
    ],

];
