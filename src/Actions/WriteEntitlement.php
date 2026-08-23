<?php

namespace FlutterSdk\MagicStarter\Actions;

use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Contracts\WritesEntitlement;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Support\EntitlementWrite;
use FlutterSdk\MagicStarter\Support\ReadsBillableAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The single code path that writes a billable subject's entitlement columns.
 *
 * A billable's tier is one column and more than one payment rail can feed it.
 * Two feeders writing it unconditionally is not two features, it is a race:
 * whichever event is delivered last wins, and delivery order is a property of
 * the internet rather than of the truth. Every feeder passes its claim through
 * here, and the four rules below decide.
 *
 * RULE 1, monotonic per rail. A write from the rail that already granted the
 * entitlement is dropped unless its event is STRICTLY newer than the one on
 * record. This is not defensive coding, it is the documented delivery behaviour
 * of the rails: a store retries a failed webhook on a schedule measured in tens
 * of minutes and can deliver a cancellation hours late, so a promptly delivered
 * EXPIRATION genuinely does arrive before a RENEWAL whose first attempt failed.
 * Applied in delivery order, that sequence puts a paying customer on the
 * cheapest tier and leaves it there until somebody complains.
 *
 * RULE 1b, a TIE is decided by what the write would DO, not by arrival order.
 * An equal timestamp is not evidence of late delivery, it is an absence of
 * evidence either way, so the write applies unless it would leave the billable
 * holding LESS than it holds now: a lower tier, or the same tier on a status
 * that no longer grants. Dropping every tie outright looked safer and was not,
 * because a rail that stamps to the SECOND (Stripe's `created` is a Unix second)
 * emits paired events from one API call inside that second in an order it does
 * not guarantee, and the loser was routinely a paid upgrade.
 *
 * RULE 2, a rail may only revoke what it granted. A cross-rail write that would
 * leave the billable holding LESS access than it holds now is dropped, whether
 * the loss arrives as a lower tier or as the same tier on a status that no
 * longer grants. Concretely it is what stops a late card-rail cancellation from
 * revoking a store grant during a web-to-store migration, where both rails
 * legitimately hold a record of the same customer and only one of them is still
 * being paid.
 *
 * RULE 2b, a PROJECTION may not take over the record of a rail that is still
 * granting. Rule 2 only ever stopped a cross-rail REVOCATION, so a cross-rail
 * write that takes nothing away passed and the persist step then rewrote
 * `plan_provider`, after which that rail's own next revocation was SAME-rail:
 * rule 2 could no longer see it and rule 1 let it through. The test is the
 * WRITER's standing rather than the tier, because the tier could never answer
 * the question. A claim assembled from a row the rail wrote into this database
 * earlier cannot testify to a handover; the rail speaking now can.
 *
 * No rule here is symmetric, and that is deliberate. A write wrongly dropped
 * leaves a customer on a tier for longer than they paid for it, and a log line
 * says so; a write wrongly applied takes a tier away from somebody who is
 * paying. Every ambiguity here resolves toward keeping the entitlement.
 *
 * Rule 2 has to compare two tiers, and the tier vocabulary belongs to the
 * consuming application, so the order is read from
 * `config('magic-starter.billing.tier_order')`. When that list is EMPTY the
 * comparison is undecidable, and an undecidable cross-rail write against a tier
 * the billable HOLDS is refused, exactly as a published catalogue missing one
 * of the two tiers is. This is the second formulation. The first read an empty
 * list as a non-downgrade, on the argument that an absent catalogue is the
 * normal state of a fresh install rather than a config error, so refusing every
 * cross-rail write would leave a paying customer with nothing. That argument is
 * about a billable holding NOTHING, and that case is answered on its own terms:
 * nothing on record cannot be taken away, so such a write applies whether or
 * not an order is published. What the empty-list reading actually governed was
 * a billable that already holds a tier while this package's SHIPPED DEFAULT
 * leaves the order empty, which is the one state where being permissive costs a
 * payer the tier they are paying for.
 *
 * This is the package default. A consumer that needs different arbitration
 * binds its own implementation of {@see WritesEntitlement} over this one.
 */
class WriteEntitlement implements WritesEntitlement
{
    /**
     * The provenance columns are decoded through the shared readers rather than
     * read directly, and they live outside this class because they are not this
     * class's rule. The package ships the columns and not the model that casts
     * them, so every controller, resource, job and command on the billing rails
     * has to decode the same way this action does; a protected method here would
     * have made each of them write its own copy, and two copies of a decode rule
     * disagree while both sides' tests pass.
     */
    use ReadsBillableAttributes;

    /**
     * Direction labels a write can move the entitlement in.
     */
    protected const DIRECTION_UPGRADE = 'upgrade';

    protected const DIRECTION_SAME = 'same';

    protected const DIRECTION_DOWNGRADE = 'downgrade';

    /**
     * The published catalogue does not name one of the two tiers.
     *
     * A config GAP rather than a fact about the customer, and {@see
     * self::revokes()} reads it as a revocation for that reason: an unprovable
     * downgrade applied cross-rail is a revocation with better manners.
     */
    protected const DIRECTION_UNKNOWN = 'unknown';

    /**
     * No catalogue is published at all, so nothing here can be ranked.
     *
     * Split out of `unknown`, and for the same reason `nothing-stored` was: one
     * label was answering two rules that need opposite answers. {@see
     * self::revokes()} still reads this as a revocation, because rule 2 asks
     * whether a rail may revoke what ANOTHER rail granted and must refuse a claim
     * it cannot rank. But rule 1b asks something narrower, whether THIS write
     * reduces access, and an unpublished catalogue is not an answer to that, it is
     * the absence of one, so {@see self::reducesAccess()} excludes it while {@see
     * self::takesAccessAway()} counts it.
     *
     * Merged, the fail-closed decision taken for rule 2 reached rule 1b through
     * the shared `direction()` and dropped every same-rail same-instant write
     * against a held tier on this package's SHIPPED DEFAULT of an empty
     * `tier_order`. That is precisely the paid upgrade rule 1b was added to keep:
     * a Stripe plan swap emits its pair inside one second, and the half carrying
     * the tier the customer just bought is the one that loses. Both existing
     * empty-order tests were cross-rail, so nothing saw it.
     */
    protected const DIRECTION_NO_ORDER = 'no-order';

    /**
     * The billable holds no tier at all, so no write can move it in any
     * direction.
     *
     * Distinct from `unknown`, and the distinction is load-bearing rather than
     * descriptive. `unknown` means the comparison failed and the write might be
     * a loss. This one means there is nothing on record to lose, so reading it
     * as a revocation would refuse every cross-rail write against a billable
     * that holds nothing, including the purchase that grants it a tier for the
     * first time. Absence and unrankability are different facts and one label
     * cannot carry both; it carried both once, and the tier-holding half of it
     * is what let a cross-rail downgrade through on the shipped default.
     */
    protected const DIRECTION_NOTHING_STORED = 'nothing-stored';

    /**
     * Apply one rail's claim to the billable's entitlement columns.
     *
     * Returns true when the columns were written, false when a rule dropped the
     * write. Every false return has logged why.
     *
     * @param  EntitlementWrite  $write  One rail's complete claim. Every field
     *                                   it carries is documented on the value
     *                                   object itself, including why the tier is
     *                                   a plain string, why `plan` may be null,
     *                                   and why `authoritative` has no default.
     */
    public function write(EntitlementWrite $write): bool
    {
        $billable = $write->billable;

        // 1. Read what is already on record, and rank the move it would make.
        $storedProvider = BillingProvider::fromWire($this->stringAttribute($billable, 'plan_provider'));
        $storedPlan = $this->stringAttribute($billable, 'plan');
        $storedEventAt = $this->storedEventAt($billable);
        $tierOrder = $this->tierOrder();
        $direction = $this->direction($write->plan, $storedPlan, $tierOrder);

        $context = [
            'billable_id' => $billable->getKey(),
            'stored_provider' => $storedProvider->value,
            'incoming_provider' => $write->provider->value,
            'stored_event_at' => $storedEventAt?->toIso8601String(),
            'incoming_event_at' => $write->eventAt->toIso8601String(),
            'stored_plan' => $storedPlan,
            'incoming_plan' => $write->plan,
            'direction' => $direction,
        ];

        // 2. RULE 1, monotonic per rail. An event OLDER than the one already on
        // record from the SAME rail is a late or re-ordered delivery, and the
        // record it would overwrite was written from a fresher truth.
        if ($storedProvider === $write->provider && $this->isOlderThanStored($write->eventAt, $storedEventAt)) {
            $this->logDrop('stale', $context);

            return false;
        }

        // 2b. RULE 1b, a TIE resolves by direction rather than by arrival order.
        // Treating an equal timestamp as stale looks safe and is not. A rail that
        // stamps to the SECOND (Stripe's `created` is a Unix second) emits paired
        // events from a single API call inside one second, in an order it does not
        // guarantee, and one of those two carries a stale tier read from the
        // consumer's own local state. Dropping the other one left a customer who
        // had just paid for a higher tier on the lower one.
        //
        // Resolving by what the write would DO is what keeps this consistent
        // with rule 2 below: a tie that would TAKE the entitlement away still
        // loses. "Away" is not only a lower tier, and reading it that way left
        // half the promise unkept. {@see revokes()} ranks two TIERS, so a
        // same-instant pair that keeps the tier and moves the status from a
        // granting one to a lapsed one ranked as SAME and applied: the guard
        // held for a downgrade and not for a cancellation, which is the same
        // loss arriving through a different column.
        if ($storedProvider === $write->provider
            && $this->isSameInstantAsStored($write->eventAt, $storedEventAt)
            && $this->reducesAccess($direction, $write->status, $billable)
        ) {
            $this->logDrop('same-instant revocation', $context);

            return false;
        }

        // 3. RULE 2, a rail may only revoke what it granted. A rail that did not
        // sell this entitlement cannot take it away, and a direction a
        // published catalogue cannot rank counts as revocation for the same
        // reason: an unprovable downgrade applied cross-rail is a revocation
        // with better manners.
        //
        // The predicate is {@see takesAccessAway()} and not the tier-only
        // {@see revokes()}, which is the same correction rule 1b already
        // carries: access leaves through two columns and the tier is one of
        // them. Ranked on the tier alone, a cross-rail claim carrying the SAME
        // tier on a status that no longer grants read as no change, so it
        // passed here, and it passed rule 2b as well whenever the rail was
        // speaking for itself, which is the standing rule 2b exists to honour.
        // The write then put a lapsed status over a rail that is still billing
        // and every reader gating on `plan_status` cut off a paying customer.
        if ($storedProvider !== $write->provider && $this->takesAccessAway($direction, $write->status, $billable)) {
            // One line, not two, and which one depends on WHY the direction
            // could not be proven. An operator whose only config gap is the
            // unpublished catalogue needs the key named; the generic drop line
            // would send them looking for a rail problem that is not there.
            if ($direction === self::DIRECTION_NO_ORDER) {
                $this->warnUndecidableTierOrder($context);
            } else {
                $this->logDrop('cross-rail revocation', $context);
            }

            return false;
        }

        // 3b. RULE 2b, a PROJECTION may not take over the record of a rail that
        // is still granting.
        //
        // Rule 2 only stops a cross-rail write that takes access away, so a
        // cross-rail write carrying the SAME tier on a status that STILL grants
        // passed and step 5 below then rewrote `plan_provider` unconditionally.
        // The damage lands one step later, which is what makes it hard to see:
        // with the record now naming the new rail, that rail's next revocation
        // is SAME-rail, so rule 2 can no longer see it and rule 1 lets it
        // through. A billable paid up on one store, still holding an uncancelled
        // subscription on another rail, could have its provenance moved by one
        // ordinary renewal event and then be revoked by the cancellation of the
        // rail that was never paying it.
        //
        // The test is the WRITER's standing, and getting there took one wrong
        // turn worth recording. This guard was first written as a blanket
        // SAME-TIER drop, which closed the direction above by opening its
        // mirror: a store selling the tier a customer already holds on another
        // rail is a MIGRATION, and refusing it left the record on the rail that
        // was about to stop billing, whose cancellation was then SAME-rail and
        // revoked a tier somebody was still paying for. The tier could never
        // answer the question; how well the writer knows its own claim can.
        //
        // A PROJECTION is assembled from state the rail wrote into the
        // consumer's database earlier, so it cannot testify to a handover: it
        // may refresh what the record says and may not decide who is billing.
        // An AUTHORITATIVE claim is the rail speaking now, and it takes the
        // record. This also removes the `tier_order` hazard the previous
        // formulation had to work around, since no direction is consulted here
        // at all.
        //
        // Consulting no direction widened this in one more way, and it is the
        // intended reading rather than a side effect: a projected cross-rail
        // UPGRADE is now dropped too, where the tier formulation applied it and
        // warned. A projection is stale by construction, so the higher tier it
        // reports is one this database already held, and the rail's own event
        // follows carrying the standing to move the record. The cost is one
        // delayed upgrade with a log line naming it; the alternative is letting
        // a stale row move provenance, which is the defect above.
        //
        // The status half stays: {@see BillingProvider::grants()} is a per-RAIL
        // table, true for every real rail, so gating on it alone would drop a
        // genuine purchase from a billable whose LAPSED record on another rail
        // still names the same tier, and the customer would pay and receive
        // nothing.
        if ($storedProvider !== $write->provider
            && $storedProvider->grants()
            && $this->storedStatusStillGrants($billable)
            && ! $write->authoritative
        ) {
            $this->logDrop('projected cross-rail takeover', $context);

            return false;
        }

        // 4. A second rail claiming a customer the first rail is still billing
        // means somebody is paying twice. The write lands, but it cannot land
        // quietly.
        //
        // This used to fork here, warning instead that the tier order was
        // unpublished and the guard therefore switched off. That line has moved
        // to the drop at rule 2, because the guard is no longer switched off: an
        // unpublished order now REFUSES a cross-rail write against a held tier,
        // and the only writes that still reach this point with no order
        // published are the ones that had nothing to compare against anyway.
        if ($storedProvider !== $write->provider && $storedProvider->grants()) {
            $this->warnCrossRailGrant($context);
        }

        // 5. Persist the claim plus the provenance the next write reasons about.
        $billable->forceFill([
            'plan' => $write->plan,
            'plan_status' => $write->status->value,
            'plan_provider' => $write->provider->value,
            'plan_source_event_at' => $write->eventAt,
            'plan_provider_status' => $write->providerStatus,
            'plan_product_id' => $write->productId,
            'plan_current_period_end' => $write->currentPeriodEnd,
            'plan_renews' => $write->renews,
            'plan_grace_period_ends_at' => $write->gracePeriodEndsAt,
            'plan_manage_url' => $write->manageUrl,
        ])->save();

        return true;
    }

    /**
     * Whether the incoming event is STRICTLY older than the one that wrote the
     * stored entitlement, which is the only case rule 1 drops outright.
     *
     * A null stored timestamp means this rail has never written here, so there
     * is nothing for the incoming event to be older THAN and it applies.
     *
     * This was its own inverse until the tie case was separated out. The old
     * docblock argued that "equal timestamps carry no ordering information and
     * the safe reading is to keep what is already there", and named the cost: a
     * rail that stamps to the second lets only the first of two events inside
     * one second through. The reasoning was right and the conclusion was wrong,
     * because it assumed the loser of a tie is always the write that takes
     * something away. It is routinely a paid upgrade instead, so a tie now goes
     * to {@see self::isSameInstantAsStored()} and is judged by direction.
     */
    protected function isOlderThanStored(CarbonInterface $eventAt, ?CarbonInterface $storedEventAt): bool
    {
        return $storedEventAt !== null && $eventAt->lessThan($storedEventAt);
    }

    /**
     * Whether the incoming event carries the SAME instant as the stored one.
     *
     * A tie is not evidence of late delivery, it is an absence of evidence
     * either way, which is why the caller resolves it with the same predicate
     * rule 2 uses instead of with arrival order.
     */
    protected function isSameInstantAsStored(CarbonInterface $eventAt, ?CarbonInterface $storedEventAt): bool
    {
        return $storedEventAt !== null && $eventAt->equalTo($storedEventAt);
    }

    /**
     * Whether the STATUS already on record is one that entitles the billable.
     *
     * Distinct from {@see BillingProvider::grants()}, which answers "is this a
     * real billing rail" for every rail alive. This answers "is that rail
     * currently granting anything", which is the question rule 2b needs: a
     * stored record that has lapsed is not an entitlement another rail can take
     * over, it is a slot another rail can fill.
     */
    protected function storedStatusStillGrants(Model $billable): bool
    {
        return PlanStatus::fromWire($this->stringAttribute($billable, 'plan_status'))->grants();
    }

    /**
     * Which way this write would move the tier, in terms of the consumer's own
     * published order.
     *
     * Defined once and used by both the rule and the log, because "is this a
     * downgrade" has to mean exactly one thing: two definitions of it would
     * eventually disagree, and the disagreement would be a revocation.
     *
     * An incoming null is a rail saying nothing is owed, and it ranks BELOW
     * every tier the catalogue names rather than off the ladder: a revocation is
     * not a maybe. A STORED null is the other way round, off the ladder
     * entirely, because there is nothing on record for the write to take away.
     * Same absence, two different facts, which is why one direction label could
     * not serve both.
     *
     * @param  string|null  $plan  The tier the rail says is owed, or null for a
     *                             rail saying nothing is owed.
     * @param  list<string>  $tierOrder  The published catalogue, cheapest first.
     * @return self::DIRECTION_* One of the five labels above and nothing else.
     *                           Declared as the closed set rather than as
     *                           `string` so {@see self::revokes()} can be
     *                           checked for exhaustiveness: a sixth direction
     *                           added without a decision in that table is then a
     *                           static error rather than an uncaught exception on
     *                           a payment path.
     */
    protected function direction(?string $plan, ?string $storedPlan, array $tierOrder): string
    {
        // 1. Nothing on record: a write cannot take away what was never
        //    granted. FIRST, because this is the branch that carries the
        //    fresh-install argument, and the order used to be the other way
        //    round.
        if ($storedPlan === null) {
            return self::DIRECTION_NOTHING_STORED;
        }

        // 2. A tier is held and no catalogue is published, so the comparison
        //    cannot be made. This branch used to fail OPEN, on the argument
        //    that an unpublished catalogue is the normal state of a fresh
        //    install rather than a config error. The argument was sound and it
        //    belongs to branch 1: a fresh install holds no tier. Subtract that
        //    and what is left is a billable that ALREADY holds a tier on this
        //    package's SHIPPED DEFAULT of an empty `tier_order`, which is the
        //    one state where failing open costs a payer the tier they hold. So
        //    it fails closed, and {@see self::warnUndecidableTierOrder()} names
        //    the key that would let the write be ranked instead of refused.
        if ($tierOrder === []) {
            return self::DIRECTION_NO_ORDER;
        }

        $stored = $this->tierRank($storedPlan, $tierOrder);

        if ($stored === null) {
            return self::DIRECTION_UNKNOWN;
        }

        // 3. A rail saying nothing is owed ranks BELOW every tier the catalogue
        //    names, so a revocation always reads as a downgrade.
        if ($plan === null) {
            return self::DIRECTION_DOWNGRADE;
        }

        $incoming = $this->tierRank($plan, $tierOrder);

        if ($incoming === null) {
            return self::DIRECTION_UNKNOWN;
        }

        return match (true) {
            $incoming > $stored => self::DIRECTION_UPGRADE,
            $incoming < $stored => self::DIRECTION_DOWNGRADE,
            default => self::DIRECTION_SAME,
        };
    }

    /**
     * Whether a write moving the tier in this direction would take entitlement
     * away.
     *
     * `unknown` is grouped with `downgrade` rather than left to a default arm:
     * the two error directions are not symmetric here. Reading an unrankable
     * write as harmless lets it land, and if it was in fact a downgrade the
     * customer loses a tier they paid for; reading it as a revocation only
     * delays a legitimate change until an operator sees the log.
     *
     * `nothing-stored` is the one absence that is NOT grouped with them, for the
     * reason its own constant records: the asymmetry above is about what the
     * customer stands to lose, and a billable holding no tier stands to lose
     * nothing. Grouping it here would have rule 2 refuse the first purchase of
     * every billable whose plan column is null.
     *
     * @param  self::DIRECTION_*  $direction  The closed set, so the `match`
     *                                        below is checked for
     *                                        exhaustiveness rather than only
     *                                        raising at runtime.
     */
    protected function revokes(string $direction): bool
    {
        return match ($direction) {
            self::DIRECTION_DOWNGRADE, self::DIRECTION_UNKNOWN, self::DIRECTION_NO_ORDER => true,
            self::DIRECTION_UPGRADE, self::DIRECTION_SAME, self::DIRECTION_NOTHING_STORED => false,
        };
    }

    /**
     * Whether a write would leave the billable holding LESS access than it has
     * now.
     *
     * A tier can be taken away through either of two columns, and {@see
     * revokes()} only reads one of them. A same-rail pair stamped to the same
     * second that keeps the tier and moves `plan_status` from a granting value
     * to a lapsed one ranks as `same`, so rule 1b's tie-break let the
     * cancellation half of that pair land: the loss was real and the direction
     * could not see it. Access is what is being arbitrated, so the tie-break
     * asks about access.
     *
     * Rules 1b AND 2 both ask this, and the wrong turn worth recording is that
     * they did not. This predicate was introduced for rule 1b alone, with a
     * docblock arguing that rule 2 asks a different question and that a
     * cross-rail status change is already answered by rule 2b's standing test.
     * The second half of that was false: rule 2b honours an AUTHORITATIVE claim
     * by design, so a rail speaking for itself, carrying the tier already on
     * record on a status that no longer grants, passed rule 2 as `same` and
     * passed rule 2b as authoritative, and wrote a lapsed status over a rail
     * that was still billing. Both rules arbitrate access, so both ask about
     * access. {@see revokes()} stays as the tier-only half used inside here and
     * nowhere else, because "would this move the tier down" is still a distinct
     * question the log and this predicate both need answered separately.
     *
     * @param  self::DIRECTION_*  $direction  Where this write moves the tier.
     * @param  PlanStatus  $status  The status this write would leave behind.
     * @param  Model  $billable  The subject, read for the status on record.
     */
    protected function takesAccessAway(string $direction, PlanStatus $status, Model $billable): bool
    {
        return $this->revokes($direction) || $this->statusRevokes($status, $billable);
    }

    /**
     * Rule 1b's question: would this write reduce access, as far as anything here
     * can actually tell?
     *
     * The same two axes as {@see self::takesAccessAway()} with ONE difference, and
     * the difference is the whole reason both exist. Rule 2 asks whether a rail may
     * revoke what ANOTHER rail granted and refuses a claim it cannot rank, so an
     * unpublished catalogue counts against the write. Rule 1b asks only whether
     * THIS write takes something away, and an unpublished catalogue is not an
     * answer to that: with no order published the tier axis cannot speak at all, so
     * only the status axis decides.
     *
     * Collapsing the two into one predicate is a mistake I made twice while
     * getting here. Sharing it and counting `no-order` dropped every same-rail tie
     * against a held tier on the SHIPPED DEFAULT, which is the paid upgrade rule 1b
     * exists to keep. Sharing it and NOT counting `no-order` disarmed rule 2's
     * deliberate fail-closed on the same default. Two callers wanted opposite
     * answers from one question, which is the same shape as the direction labels
     * this class has already had to split twice.
     */
    protected function reducesAccess(string $direction, PlanStatus $status, Model $billable): bool
    {
        $tierAxisSpeaks = $direction !== self::DIRECTION_NO_ORDER;

        return ($tierAxisSpeaks && $this->revokes($direction))
            || $this->statusRevokes($status, $billable);
    }

    /**
     * The status axis, shared by both rules: this write leaves the billable on a
     * status that no longer grants, where the record still does.
     *
     * Needs no catalogue, which is why it is the half that still answers when the
     * tier axis cannot.
     */
    protected function statusRevokes(PlanStatus $status, Model $billable): bool
    {
        return ! $status->grants() && $this->storedStatusStillGrants($billable);
    }

    /**
     * A tier's position in the consumer's published catalogue.
     *
     * Null when the catalogue does not name the tier at all. The two id sets
     * are not the same set in either direction: a catalogue may carry a tier no
     * billing rail sells, and a catalogue edited to drop a row leaves a live
     * stored tier unrankable. An unrankable tier has no direction, and callers
     * must treat that as unknown rather than as cheapest, which would read
     * every write as an upgrade.
     *
     * @param  list<string>  $tierOrder  The published catalogue, cheapest first.
     */
    protected function tierRank(string $plan, array $tierOrder): ?int
    {
        $rank = array_search($plan, $tierOrder, true);

        return $rank === false ? null : $rank;
    }

    /**
     * Report a dropped write with everything needed to reconstruct the decision.
     *
     * A drop is the action declining to change a customer's plan, so it is
     * never silent: without this line the only symptom is a support ticket
     * saying the plan did not change, and nothing to answer it with. Warning
     * rather than info for the same reason. Both drop reasons mean two sources
     * disagreed about a paying customer, which is not routine even though the
     * retry schedules that produce it are.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logDrop(string $reason, array $context): void
    {
        Log::warning('Entitlement write dropped; the stored entitlement stands.', [
            'reason' => $reason,
            ...$context,
        ]);
    }

    /**
     * Report a rail taking over an entitlement another rail is still billing.
     *
     * Warning level, and addressed to an operator rather than to code: nothing
     * automated can resolve this. Refunding the losing side is a money movement
     * no webhook handler should make on its own, and cancelling it needs to
     * know which subscription the customer meant to keep, which the payload
     * cannot say. So the write applies, the customer keeps what they paid for,
     * and a human is told there are two live subscriptions.
     *
     * @param  array<string, mixed>  $context
     */
    protected function warnCrossRailGrant(array $context): void
    {
        Log::warning(
            'Entitlement claimed by a second billing rail; the incoming claim applied.',
            $context,
        );
    }

    /**
     * Report that rule 2 refused a cross-rail write because no tier order is
     * published and the two tiers therefore could not be compared.
     *
     * This message once reported the opposite outcome, a protection switched
     * off and the write allowed through, and it is the same sentence because it
     * carries the same operator-facing fact: the only config key that decides
     * this is unpublished. What changed is which side of the decision an
     * unpublished key lands on. An operator seeing a customer's rails change
     * hands deserves to be told which key governs it, whether the guard let the
     * write through or refused it.
     *
     * @param  array<string, mixed>  $context
     */
    protected function warnUndecidableTierOrder(array $context): void
    {
        Log::warning(
            'Entitlement claimed by a second billing rail and the tiers could not be compared; '
            . 'the write was dropped. Publish magic-starter.billing.tier_order so a cross-rail '
            . 'claim can be ranked rather than refused.',
            $context,
        );
    }
}
