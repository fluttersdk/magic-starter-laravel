<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * What a support proposal would do to the customer's subscription, as `support_proposals.action` stores it.
 *
 * The values are stored and read back, so a value never changes once shipped.
 */
enum SupportProposalAction: string
{
    /**
     * Move the trial end to a later date, at most `support.proposals.max_trial_extension_days`
     * past the current end.
     */
    case EXTEND_TRIAL = 'extend_trial';

    /** Keep the subscription running to the end of the paid period and then stop it. */
    case CANCEL_AT_PERIOD_END = 'cancel_at_period_end';

    /** Undo a scheduled cancellation. */
    case RESUME = 'resume';

    /**
     * The translated, human-readable name of this action.
     *
     * @param  string|null  $locale  The language to name it in; the app's current locale when null, so a line
     *                               written for the customer can follow the conversation's language instead.
     */
    public function label(?string $locale = null): string
    {
        return (string) __('magic-starter::admin_support.proposals.actions.' . $this->value, [], $locale);
    }
}
