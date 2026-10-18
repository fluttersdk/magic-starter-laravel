<?php

return [

    /*
     * Why a campaign save was refused, keyed by reason and raised by the
     * campaign write actions and CampaignCodeValidator. Staff read them in the
     * admin panel, so each one says what to change in Stripe or in the form.
     */
    'refusals' => [
        'product_not_eligible' => 'Kampanyaya yalnızca satışta olan ve Stripe fiyatı tanımlı bir abonelik ürünü eklenebilir.',
        'overlap' => 'Etkin ":name" kampanyası bu aralığın bir kısmında zaten yayında; aynı anda yalnızca bir kampanya etkin olabilir.',
        'code_not_found' => 'Stripe bu kimlikte bir promosyon kodu bulamadı. Müşterinin yazdığı kodu değil, promosyon kodu kimliğini (promo_...) kullanın.',
        'code_mode_mismatch' => 'Bu promosyon kodu diğer Stripe moduna ait: tanımlı Stripe anahtarı test anahtarı, kod ise canlı (ya da tersi).',
        'code_without_coupon' => 'Bu promosyon kodunun arkasında bir kupon yok.',
        'code_inactive' => 'Bu promosyon kodu Stripe\'ta etkin değil; kodu yeniden etkinleştirin ya da başka bir kod kullanın.',
        'code_without_expiry' => 'Bu promosyon kodunun bitiş tarihi yok; kampanyayla birlikte sona ermesi için kampanya bitişinde ya da sonrasında bir bitiş tarihi verin.',
        'code_expires_before_end' => 'Bu promosyon kodu kampanya bitmeden sona eriyor; kodun süresini uzatın ya da kampanyayı daha erken bitirin.',
        'code_first_time_only' => 'Bu promosyon kodu yalnızca ilk işlemlerle sınırlı; Stripe daha önce deneme başlatmış ya da ödeme yapmış her ekip için bunu reddeder.',
        'code_minimum_amount' => 'Bu promosyon kodunda en düşük tutar sınırı var ve deneme ile başlayan bir ödeme bunu hiçbir zaman karşılamaz; sınırı Stripe\'ta kaldırın.',
        'code_customer_restricted' => 'Bu promosyon kodu tek bir müşteriyle sınırlı; kampanya kodu her müşteriye açık olmalı.',
        'code_products_not_covered' => 'Bu promosyon kodunun kuponu şu ürünlerin Stripe ürününe uygulanmıyor: :products.',
        'code_currency_missing' => 'Bu promosyon kodunun kuponunda şu para birimleri için tutar yok: :currencies. Her biri için bir para birimi seçeneği ekleyin.',
        'code_coupon_invalid' => 'Bu promosyon kodunun kuponu Stripe\'ta artık geçerli değil (silinmiş, süresi dolmuş ya da kullanım sınırına ulaşmış); geçerli bir kuponu olan bir kod kullanın.',
        'code_repeating_within_trial' => 'Bu promosyon kodunun kuponu :months ay boyunca tekrarlanıyor ve Stripe bu süreyi :days günlük denemenin başından sayıyor, yani ilk ücretli faturadan önce bitiyor; tek seferlik ya da süresiz bir kupon veya daha uzun bir tekrar kullanın.',
        'price_not_found' => 'Stripe ":product" ürününün fiyatını bulamadı; faturalandırma kataloğundaki Stripe fiyatını kontrol edin.',
        'price_mode_mismatch' => '":product" ürününün Stripe fiyatı, tanımlı Stripe anahtarından farklı bir Stripe moduna ait.',
        'stripe_error' => 'Stripe denetimi reddetti: :message',
    ],

];
