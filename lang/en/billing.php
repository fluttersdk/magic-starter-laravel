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
        'stripe_subscription_active' => 'A card subscription is still active on this team. Cancel it in billing and wait for the paid period to end before deleting the team.',

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
         * 'no_published_catalogue' names all three catalogue keys because a
         * sale needs all three: 'tier_order' ranks the tiers and is the only
         * list of tiers that exist, 'tiers' describes them, and 'products'
         * sells them. The removed 'plans' key is not named, because boot
         * refuses a config that still carries it.
         */
        'no_published_catalogue' => 'No plans are published, so there is nothing to buy yet. Rank your tiers cheapest first in magic-starter.billing.tier_order, describe them in magic-starter.billing.tiers and sell them under magic-starter.billing.products.',
        /*
         * A checkout or a swap named a product the card rail cannot sell: not
         * in the catalogue, not a subscription, the free floor, or a
         * subscription with no Stripe price (sold on the stores only). It names
         * the product and the ref that would make it sellable here, because the
         * last case is a config gap rather than a client fault.
         */
        'product_not_sellable' => 'The product :product cannot be bought here: only a paid subscription with a Stripe price is sold on this rail. Choose another plan, or set refs.stripe_price on it under magic-starter.billing.products.',
        'no_subscription' => 'There is no active subscription to change.',
    ],

];
