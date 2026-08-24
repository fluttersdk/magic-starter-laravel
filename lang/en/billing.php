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
     * Human names for the two billing cycles, keyed by the wire word.
     *
     * They exist because `unmapped_price` NAMES the cycle, and the wire word is
     * English: without this a Turkish adopter read "Bu plani monthly dongusunde
     * satan bir Stripe fiyati yok", with the one dimension that sentence was
     * rewritten to surface left untranslated. The wire word itself never
     * changes; only what a human is shown.
     */
    'cycles' => [
        'monthly' => 'monthly',
        'annual' => 'annual',
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

        /*
         * The two 409 sentences the billing endpoints raise, and they are
         * deliberately two rather than one. "Manage this where you bought it"
         * and "there is nothing to manage yet" are opposite instructions, so a
         * single shared sentence would leave the customer guessing which of
         * them they had been given. Each travels beside a machine-readable
         * reason so the client never has to parse the prose to decide.
         */
        'managed_by_store' => 'This subscription is managed by the store that sold it and cannot be changed here.',
        'no_billing_account' => 'There is no billing account to manage yet.',

        /*
         * The third, and it leads somewhere neither of those two does: the
         * customer is not being sent elsewhere and not being told there is
         * nothing to manage, they are being told to CHANGE what they already
         * have. So the sentence names the action instead of the obstacle.
         *
         * It fires most often on a CANCELLED subscription inside its paid
         * period, which is the moment a customer is most likely to buy again,
         * so it has to read sensibly to somebody who believes they have already
         * cancelled. "You already have a subscription" would sound like a
         * contradiction to them; "has not ended yet" is the fact that
         * reconciles it.
         *
         * "Still active" said the same thing and was wrong for one of the four
         * states that reach here: `past_due` grants (see
         * {@see StripeSubscriptionState::GRANTING_STATUSES}), so a customer
         * whose card has just been declined would have been told their
         * subscription is active while Stripe retries the charge. What is true
         * of all four, cancelled-in-period included, is that the subscription
         * has not ended, so that is what the sentence claims.
         */
        'subscription_exists' => 'Your subscription has not ended yet, so change your plan instead of buying a second one.',

        /*
         * The three refusals the card-rail WRITES raise. Two of them describe a
         * gap in the adopter's own configuration rather than a fault in the
         * request, and they name the key that closes it: without that, an
         * adopter reads their client's request body looking for a problem that
         * is in their config file.
         *
         * 'no_published_catalogue' names BOTH keys because either one answers.
         * The ranking is read cheapest-first from 'tier_order' when it is
         * published and from the catalogue's entry ids when it is not, so an
         * adopter who has published only the catalogue already has a valid list
         * and naming the other key alone would send half of them to the wrong
         * file.
         */
        'no_published_catalogue' => 'No plans are published, so there is nothing to buy yet. Publish magic-starter.billing.plans or magic-starter.billing.tier_order to sell one.',
        /*
         * It names the CYCLE because the cycle is what fails. Since a checkout
         * asks for a (tier, cycle) pair, an adopter selling `business` annually
         * only meets this on EVERY monthly checkout, and the sentence used to
         * tell them to map a price they had already mapped while never naming
         * the dimension that did not match. The one thing the reader needs was
         * the one thing it omitted.
         */
        'unmapped_price' => 'No Stripe price sells this plan on a :cycle cycle. Map one under magic-starter.billing.prices, or offer the other cycle.',
        'no_subscription' => 'There is no active subscription to change.',
    ],

];
