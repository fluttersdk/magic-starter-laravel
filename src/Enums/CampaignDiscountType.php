<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * How a campaign's coupon discounts, as the save snapshotted it from Stripe.
 *
 * The values are stored and sent to clients as `discount.type` on `GET billing/campaign`,
 * so a value never changes once shipped.
 */
enum CampaignDiscountType: string
{
    /** A percentage off: `discount_value` is the percent. */
    case PERCENT = 'percent';

    /** A fixed amount off: `discount_value` is in the minor unit of `discount_currency`. */
    case AMOUNT = 'amount';
}
