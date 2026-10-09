<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Who pays the store's cut when a store price is derived from the web price,
 * as `magic-starter.billing.pricing.commission.mode` names it.
 */
enum CommissionMode: string
{
    /**
     * The store's cut comes out of the web price: the store channel charges the
     * web figure and the adopter nets less. The default, because it is the rule
     * that never raises a price nobody configured.
     */
    case ABSORB = 'absorb';

    /**
     * The store channel charges more, so the adopter nets the web figure after
     * the store's cut.
     */
    case GROSS_UP = 'gross_up';
}
