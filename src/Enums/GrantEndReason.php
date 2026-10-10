<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * How a manual plan grant ended, as `billing_grants.end_reason` stores it.
 *
 * The values are stored and read back, so a value never changes once shipped.
 */
enum GrantEndReason: string
{
    /** The grant reached its `expires_at` and the expiry command released it. */
    case EXPIRED = 'expired';

    /** An operator ended the grant before its expiry. */
    case REVOKED = 'revoked';

    /** A newer grant for the same billable took its place. */
    case SUPERSEDED = 'superseded';
}
