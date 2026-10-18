<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The one rule for the day a trial extension proposal may move a trial to,
 * applied the same way when the proposal is recorded and when staff approve it.
 *
 * The day is a strict `Y-m-d` string; it must fall after the current trial end
 * and at most `support.proposals.max_trial_extension_days` past it, so a
 * proposal can never stretch a trial further than staff allow in one step.
 */
final class SupportProposalTrialWindow
{
    /**
     * The day a strict `Y-m-d` string names, at its start, or null.
     *
     * The pattern runs first because PHP rolls an out-of-range month or day
     * over (`2026-13-01` becomes `2027-01-01`), and the round trip catches
     * exactly that roll-over.
     */
    public static function day(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = Carbon::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonInterface && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * Whether `$until` is after the trial end and within the allowed days past it.
     */
    public static function fits(CarbonInterface $until, CarbonInterface $trialEndsAt): bool
    {
        $latest = Carbon::instance($trialEndsAt)->addDays(self::maxDays());

        return $until->greaterThan($trialEndsAt) && ! $until->greaterThan($latest);
    }

    /**
     * The most days a proposal may move the trial end, read with its default.
     */
    public static function maxDays(): int
    {
        return (int) config('magic-starter.support.proposals.max_trial_extension_days', 14);
    }
}
