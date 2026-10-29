<?php

namespace FlutterSdk\MagicStarter\Enums;

use RuntimeException;

/**
 * What account deletion does with a subscription that bills the user directly,
 * as `magic-starter.account_deletion.billing` names it.
 *
 * The package ships the choice and not the answer: whether a person leaving
 * should have to cancel first, or should leave and have the card rail ended for
 * them, is a product decision. Only the user's own subscription is in question.
 * A team a subscription bills is the team owner's to settle on either setting.
 */
enum AccountDeletionBilling: string
{
    /**
     * Refuse the request while a subscription bills the user, and hold the
     * purge. Nothing is cancelled on the customer's behalf; they cancel first.
     */
    case REFUSE = 'refuse';

    /**
     * End the card rail's subscriptions when the deletion is asked for, report
     * a store's (which only the customer can cancel there), and let the deletion
     * go ahead after the grace period either way.
     */
    case RELEASE = 'release';

    /**
     * The mode the host configured.
     *
     * The literal default is load-bearing: `mergeConfigFrom` is a shallow merge,
     * so a config published before the key existed replaces the whole
     * `account_deletion` array and finds no such path.
     *
     * @throws RuntimeException When the configured value is not a mode. Guessing
     *                          which way a typo meant would either strand a
     *                          charge or cancel one nobody asked for.
     */
    public static function configured(): self
    {
        $mode = config('magic-starter.account_deletion.billing', self::REFUSE->value);

        return (is_string($mode) ? self::tryFrom($mode) : null) ?? throw new RuntimeException(sprintf(
            'Unsupported account deletion billing mode [%s]. '
            . "Set [magic-starter.account_deletion.billing] to 'refuse' or 'release'.",
            is_string($mode) ? $mode : get_debug_type($mode),
        ));
    }
}
