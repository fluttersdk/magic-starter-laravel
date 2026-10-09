<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * How often a subscription product charges.
 *
 * The words match `magic_payments`' `BillingCycle` on the Dart side, which is
 * what lets the client send one and read one back without a translation table
 * in between. Two cases and no more: an interval this package cannot name is
 * refused rather than defaulted, because every default here is a statement
 * about what somebody is being charged.
 *
 * Each vendor names the same period in its own dialect, so each has a method
 * below. Their `match` arms carry no `default`: a third cycle added later has
 * to be spelt for every vendor, and an unlisted case raises rather than
 * publishing a period nobody chose.
 */
enum BillingCycle: string
{
    case MONTHLY = 'monthly';

    case ANNUAL = 'annual';

    /** The App Store Connect subscription period. */
    public function appStorePeriod(): string
    {
        return match ($this) {
            self::MONTHLY => 'ONE_MONTH',
            self::ANNUAL => 'ONE_YEAR',
        };
    }

    /** The Play base plan billing period, an ISO 8601 duration. */
    public function playBillingPeriod(): string
    {
        return match ($this) {
            self::MONTHLY => 'P1M',
            self::ANNUAL => 'P1Y',
        };
    }

    /** The Stripe price `recurring.interval`. */
    public function stripeInterval(): string
    {
        return match ($this) {
            self::MONTHLY => 'month',
            self::ANNUAL => 'year',
        };
    }
}
