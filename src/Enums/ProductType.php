<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * What a billing catalogue product is, as its `type` names it in config.
 *
 * Only a subscription names a tier and a cycle. Every reader that grants a tier
 * checks for {@see self::SUBSCRIPTION} first, because nothing refuses a `tier`
 * written on a one-off product, and a credit pack carrying one must never move
 * a subscriber.
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
