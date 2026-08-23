<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Contracts\WritesEntitlement;
use FlutterSdk\MagicStarter\Support\EntitlementWrite;
use PHPUnit\Framework\Assert;

/**
 * Holds the FEEDER INVARIANT on every claim that passes through it.
 *
 * The invariant: no feeder ever pairs a non-granting {@see PlanStatus} with a
 * non-null tier. A revocation nulls the tier; it never leaves a tier named
 * beside a status that grants nothing.
 *
 * WHY THIS NEEDS ITS OWN GUARD AT ALL
 *
 * {@see \FlutterSdk\MagicStarter\Actions\WriteEntitlement}'s rule 2 refuses a
 * cross-rail write that would take access away, and it decides that by asking
 * whether the incoming claim leaves the subject holding less. It depends on the
 * invariant and has NO guard for its absence: given a claim that revokes while
 * still naming a tier, the comparison it makes is against the wrong thing.
 *
 * Rule 2's own tests cannot catch that, because they SUPPLY the pairing rather
 * than checking it: each one constructs the claim it wants and asserts what the
 * rule did with it. So a new feeder that revoked without nulling the tier would
 * pass every test in the package, and the failure would first appear as a rail
 * being allowed to revoke something it did not grant.
 *
 * WHY A DECORATOR RATHER THAN A TEST
 *
 * A test would have to enumerate the revocation paths, and the ones that matter
 * are the ones nobody thought of. Installed over the contract in a feeder's own
 * suite, this checks EVERY claim that suite produces, which means every
 * revocation scenario those files already drive becomes an invariant check
 * without anybody having to remember to add one. A new feeder inherits the
 * check by being tested at all.
 *
 * It delegates to the real implementation rather than standing in for it, so the
 * suites it is installed in keep asserting what they were asserting.
 */
class FeederInvariantWriter implements WritesEntitlement
{
    /**
     * How many claims have been checked, across every instance.
     *
     * A decorator that is installed but never reached is indistinguishable from
     * one that passed, so the count is what a caller asserts against to prove
     * the check was not vacuous.
     */
    public static int $checked = 0;

    public function __construct(private WritesEntitlement $inner) {}

    public function write(EntitlementWrite $write): bool
    {
        self::$checked++;

        if (! $write->status->grants()) {
            Assert::assertNull(
                $write->plan,
                sprintf(
                    'FEEDER INVARIANT VIOLATED: a claim carries status [%s], which grants nothing, '
                    . 'while still naming tier [%s]. A revocation must null the tier. WriteEntitlement '
                    . 'rule 2 reads this pairing to decide whether a cross-rail write takes access '
                    . 'without nulling the tier lets a rail revoke what it never granted.',
                    $write->status->value,
                    (string) $write->plan,
                ),
            );
        }

        return $this->inner->write($write);
    }

    public static function reset(): void
    {
        self::$checked = 0;
    }
}
