<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * What a billing catalogue product is, as its `type` names it in config.
 *
 * Only a subscription is validated to name a ranked tier and a known cycle.
 * Nothing refuses a `tier` written on a one-off product, so a reader that
 * grants a tier from a Stripe price checks for {@see self::SUBSCRIPTION} first
 * (`StripeSubscriptionState::planForPrice()`).
 */
enum ProductType: string
{
    /** A recurring charge that grants a tier for as long as it renews. */
    case SUBSCRIPTION = 'subscription';

    /** A one-off purchase used up as it is spent, such as a credit pack. */
    case CONSUMABLE = 'consumable';

    /** A one-off purchase kept for good, such as an unlock. */
    case NON_CONSUMABLE = 'non_consumable';

    /** A physical good, shipped rather than unlocked. */
    case PHYSICAL = 'physical';
}
