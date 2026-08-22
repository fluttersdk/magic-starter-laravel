<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Where a paid plan stands in its lifecycle, in rail-neutral words.
 *
 * The eight cases are the union of the FACTS the payment rails report, not of
 * the words they use for them. Each rail's own status word maps into one of
 * these and survives verbatim in the separate, debug-only
 * `plan_provider_status` column, so no reader downstream has to know which rail
 * sold the plan in order to answer "is this plan still owed to them".
 *
 * `PAUSED` is modelled even though only one of the mobile stores (Google Play)
 * has a pause primitive and the other does not. A rail without the primitive
 * simply never produces the case, whereas leaving it out and adding it later
 * would be a wire break for every client already decoding this vocabulary.
 * One unused case is the cheap side of that trade.
 */
enum PlanStatus: string
{
    /**
     * No plan lifecycle at all. The value of a subscriber who has never had a
     * paid plan, and also the safe landing place for a value this build does
     * not know (see {@see self::fromWire()}).
     */
    case NONE = 'none';

    /** Inside a trial: entitled, and not yet charged. */
    case TRIALING = 'trialing';

    /** Paid and current. */
    case ACTIVE = 'active';

    /**
     * The money has not arrived and the rail is still trying. Both cases are
     * "the plan is still owed to the subscriber", which is why both entitle;
     * they differ only in which side is holding the window open.
     */
    case PAST_DUE = 'past_due';

    case GRACE = 'grace';

    /**
     * Finished. `CANCELED` is a lifecycle the subscriber or the rail ended,
     * `EXPIRED` is one that ran out; the distinction is worth keeping because
     * only one of them tends to come back.
     */
    case CANCELED = 'canceled';

    case EXPIRED = 'expired';

    /**
     * Suspended by the subscriber with the intent of resuming. Not a
     * cancellation, and not entitled while it lasts.
     */
    case PAUSED = 'paused';

    /**
     * Resolve a status from a raw wire or column value, defaulting to
     * {@see self::NONE} when the value is null or unrecognised.
     *
     * The fallback is what lets a newer backend ship a ninth status without
     * crashing an older reader, and it lands on a NON-granting value on
     * purpose: an unrecognised word must never entitle by accident.
     */
    public static function fromWire(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::NONE;
    }

    /**
     * True while the status still entitles the subscriber to their paid plan.
     *
     * The two dunning statuses grant deliberately. `PAST_DUE` and `GRACE` both
     * mean the charge has not landed yet and the plan is still owed, and
     * cutting access off at the first failed charge punishes a customer for an
     * expired card. What revokes is a lifecycle that has finished (`CANCELED`,
     * `EXPIRED`), one the subscriber suspended (`PAUSED`), or the absence of a
     * plan (`NONE`).
     *
     * The `match` carries no `default` arm deliberately. The two error
     * directions here are not symmetric: a wrong `false` revokes someone who
     * paid, so a ninth case has to be decided in this table rather than
     * inheriting a quiet `false`, and an unlisted case raises instead.
     */
    public function grants(): bool
    {
        return match ($this) {
            self::TRIALING, self::ACTIVE, self::PAST_DUE, self::GRACE => true,
            self::NONE, self::CANCELED, self::EXPIRED, self::PAUSED => false,
        };
    }

    /**
     * The translated, human-readable name of this lifecycle state.
     *
     * Resolved from the package's own `billing` catalogue so the neutral
     * vocabulary reaches a customer in their language without every consumer
     * writing the same eight strings again.
     */
    public function label(): string
    {
        return (string) __('magic-starter::billing.statuses.' . $this->value);
    }
}
