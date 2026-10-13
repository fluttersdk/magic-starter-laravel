<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Why a conversation went from the AI desk to staff, as `support_conversations.handoff_reason` stores it.
 *
 * The values are stored and read back, so a value never changes once shipped.
 */
enum SupportHandoffReason: string
{
    /** The customer asked for a person. */
    case HUMAN_REQUESTED = 'human_requested';

    /** Money moves or a charge is disputed; the AI never settles it. */
    case BILLING = 'billing';

    /** Account takeover, credentials or a suspected breach. */
    case SECURITY = 'security';

    /** The customer reports the service itself is down. */
    case OUTAGE = 'outage';

    /** The answer came back below the confidence the desk accepts. */
    case LOW_CONFIDENCE = 'low_confidence';

    /** The responder failed `support.ai.failures_before_handoff` times in a row. */
    case FAILURES = 'failures';

    /** The customer is upset enough that a person should answer. */
    case ANGER = 'anger';

    /** The user, team, new-account or pool quota is spent. */
    case QUOTA = 'quota';

    /** No responder answered: unbound, threw, or the turn was swept unanswered. */
    case UNAVAILABLE = 'unavailable';
}
