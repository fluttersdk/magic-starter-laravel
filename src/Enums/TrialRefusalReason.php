<?php

namespace FlutterSdk\MagicStarter\Enums;

/**
 * Why the trial card check refused a trial, as `billing_trials.refusal_reason`
 * stores it.
 *
 * The two differ in who is told: a duplicate's holder still has the earlier
 * trial running, so nothing is sent, while a reused card's holder is mailed
 * that the card had already taken a trial.
 */
enum TrialRefusalReason: string
{
    /** The card was already behind an earlier trial of another person and subject. */
    case CARD_REUSED = 'card_reused';

    /** The same person or the same billed subject already holds an earlier trial. */
    case DUPLICATE = 'duplicate';
}
