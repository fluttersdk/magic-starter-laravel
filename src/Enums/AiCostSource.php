<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * How the cost of an AI run or attempt was known, as `cost_source` stores it.
 *
 * The values are stored and read back, so a value never changes once shipped.
 */
enum AiCostSource: string
{
    /** The provider reported the charge with its answer. */
    case PROVIDER = 'provider';

    /** The host computed it from the reported tokens and its own price table. */
    case COMPUTED = 'computed';

    /** A run whose costed attempts disagree on how their cost was known; never an attempt's own. */
    case MIXED = 'mixed';

    /** The call was made with no known price, so its cost is unknown rather than zero. */
    case UNPRICED = 'unpriced';
}
