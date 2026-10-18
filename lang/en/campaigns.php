<?php

return [

    /*
     * Why a campaign save was refused, keyed by reason and raised by the
     * campaign write actions and CampaignCodeValidator. Staff read them in the
     * admin panel, so each one says what to change in Stripe or in the form.
     */
    'refusals' => [
        'product_not_eligible' => 'Only a sellable subscription product with a Stripe price can be in a campaign.',
        'overlap' => 'The enabled campaign ":name" already runs during part of this window; only one campaign can be enabled at a time.',
        'code_not_found' => 'Stripe has no promotion code with this id. Use the promotion code id (promo_...), not the code customers type.',
        'code_mode_mismatch' => 'This promotion code belongs to the other Stripe mode: the configured Stripe key is a test key and the code is live, or the other way around.',
        'code_without_coupon' => 'This promotion code is not backed by a coupon.',
        'code_inactive' => 'This promotion code is inactive in Stripe; reactivate it or use another code.',
        'code_without_expiry' => 'This promotion code never expires; give it an expiry date on or after the campaign end so it stops with the campaign.',
        'code_expires_before_end' => 'This promotion code expires before the campaign ends; extend the code or end the campaign earlier.',
        'code_first_time_only' => 'This promotion code is limited to first-time transactions, which Stripe refuses for any team that has already trialled or paid.',
        'code_minimum_amount' => 'This promotion code has a minimum amount, which a trial checkout never meets; remove the minimum in Stripe.',
        'code_customer_restricted' => 'This promotion code is limited to one customer; a campaign code has to be open to every customer.',
        'code_products_not_covered' => 'The coupon behind this promotion code does not apply to the Stripe product of: :products.',
        'code_currency_missing' => 'The coupon behind this promotion code has no amount in: :currencies. Add a currency option for each.',
        'code_coupon_invalid' => 'The coupon behind this promotion code is no longer valid in Stripe (deleted, expired or fully redeemed); use a code backed by a valid coupon.',
        'code_repeating_within_trial' => 'The coupon behind this promotion code repeats for :months month(s), which Stripe counts from the start of a :days-day trial, so it ends before the first paid invoice; use a once or forever coupon, or a longer repeat.',
        'price_not_found' => 'Stripe has no price for the product ":product"; check its Stripe price in the billing catalogue.',
        'price_mode_mismatch' => 'The Stripe price of the product ":product" belongs to the other Stripe mode than the configured Stripe key.',
        'stripe_error' => 'Stripe refused the check: :message',
    ],

];
