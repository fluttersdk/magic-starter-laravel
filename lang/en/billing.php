<?php

return [

    /*
     * Human names for the neutral billing-rail vocabulary, keyed by
     * BillingProvider value and read by BillingProvider::label().
     *
     * The package defines the vocabulary, so it ships the words for it: without
     * these every consumer would rewrite the same five strings, and each one
     * would drift. `none` is a real answer here, not an error state; it is what
     * a team no rail has ever charged looks like.
     */
    'providers' => [
        'none' => 'Not billed',
        'stripe' => 'Card',
        'app_store' => 'App Store',
        'play_store' => 'Google Play',
        'manual' => 'Granted manually',
    ],

    /*
     * Human names for the neutral plan-lifecycle vocabulary, keyed by
     * PlanStatus value and read by PlanStatus::label().
     *
     * Written from the CUSTOMER's side, which is why `past_due` reads as a
     * payment problem rather than as a lost plan: both dunning statuses still
     * entitle, and telling somebody their plan ended while it has not is the
     * one wrong thing this list could say.
     */
    'statuses' => [
        'none' => 'No plan',
        'trialing' => 'Trial',
        'active' => 'Active',
        'past_due' => 'Payment due',
        'grace' => 'Payment retrying',
        'canceled' => 'Canceled',
        'expired' => 'Expired',
        'paused' => 'Paused',
    ],

    /*
     * Refusal sentences the billing actions and endpoints raise, keyed by a
     * short reason. Shipped here rather than inlined so every reader gets the
     * same wording in their own locale, and so a redeclaration-guard test can
     * assert the two locales actually differ instead of one silently
     * inheriting the other's text.
     */
    'refusals' => [
        'store_subscription_active' => 'A store subscription is still billing this team. Cancel it in the store account that bought it first: deleting the team now would remove the plan and leave the store charging you, and this app cannot cancel it for you.',
    ],

];
