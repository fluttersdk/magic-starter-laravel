<?php

namespace FlutterSdk\MagicStarter\Actions;

use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use FlutterSdk\MagicStarter\Contracts\WritesTeamEntitlement;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The single code path that writes a team's entitlement columns.
 *
 * A team's tier is one column and more than one payment rail can feed it. Two
 * feeders writing it unconditionally is not two features, it is a race:
 * whichever event is delivered last wins, and delivery order is a property of
 * the internet rather than of the truth. Every feeder passes its claim through
 * here, and the two rules below decide.
 *
 * RULE 1, monotonic per rail. A write from the rail that already granted the
 * entitlement is dropped unless its event is STRICTLY newer than the one on
 * record. This is not defensive coding, it is the documented delivery behaviour
 * of the rails: a store retries a failed webhook on a schedule measured in tens
 * of minutes and can deliver a cancellation hours late, so a promptly delivered
 * EXPIRATION genuinely does arrive before a RENEWAL whose first attempt failed.
 * Applied in delivery order, that sequence puts a paying team on the cheapest
 * tier and leaves it there until somebody complains.
 *
 * RULE 2, a rail may only revoke what it granted. A downgrade whose provider
 * differs from the stored one is dropped. Concretely it is what stops a late
 * card-rail cancellation from revoking a store grant during a web-to-store
 * migration, where both rails legitimately hold a record of the same customer
 * and only one of them is still being paid.
 *
 * Neither rule is symmetric, and that is deliberate. A write wrongly dropped
 * leaves a customer on a tier for longer than they paid for it, and a log line
 * says so; a write wrongly applied takes a tier away from somebody who is
 * paying. Every ambiguity here resolves toward keeping the entitlement.
 *
 * Rule 2 has to compare two tiers, and the tier vocabulary belongs to the
 * consuming application, so the order is read from
 * `config('magic-starter.billing.tier_order')`. When that list is EMPTY the
 * comparison is undecidable, and this package then treats the write as a
 * non-downgrade rather than as a revocation. That is the opposite default from
 * an application that owns its own catalogue, and the reason is the reason the
 * default exists at all: here an absent catalogue is the normal state of a
 * fresh install, so reading it as a revocation would drop EVERY cross-rail
 * write forever and a paying customer would receive nothing. A catalogue that
 * IS published but does not name one of the two tiers is a different thing, a
 * config gap, and that case does count as a revocation exactly as it would in
 * the consuming application.
 *
 * This is the package default. A consumer that needs different arbitration
 * binds its own implementation of {@see WritesTeamEntitlement} over this one.
 */
class WriteTeamEntitlement implements WritesTeamEntitlement
{
    /**
     * Direction labels a write can move the entitlement in.
     *
     * The last two are both "no proven ordering" and they are NOT the same
     * decision. `unknown` means a published catalogue does not name one of the
     * two tiers, which is a config gap and counts as a revocation.
     * `incomparable` means there is nothing to compare against at all, either
     * because no catalogue is published or because the team holds no
     * entitlement yet, and it cannot count as a revocation because there is
     * nothing there to revoke.
     */
    protected const DIRECTION_UPGRADE = 'upgrade';

    protected const DIRECTION_SAME = 'same';

    protected const DIRECTION_DOWNGRADE = 'downgrade';

    protected const DIRECTION_UNKNOWN = 'unknown';

    protected const DIRECTION_INCOMPARABLE = 'incomparable';

    /**
     * Apply one rail's claim to the team's entitlement columns.
     *
     * Returns true when the columns were written, false when a rule dropped the
     * write. Every false return has logged why.
     *
     * @param  Model  $team  The team whose entitlement this claim is about.
     * @param  string  $plan  The consumer-defined plan id the rail says is owed.
     * @param  PlanStatus  $status  Where that tier stands, in neutral words.
     * @param  BillingProvider  $provider  The rail making the claim.
     * @param  CarbonInterface  $eventAt  The source event's own timestamp.
     * @param  string|null  $providerStatus  The rail's own status word, verbatim.
     * @param  string|null  $productId  The rail-native product or price id.
     * @param  CarbonInterface|null  $currentPeriodEnd  End of the paid period.
     * @param  bool|null  $renews  Auto-renew state; null means unknown.
     * @param  CarbonInterface|null  $gracePeriodEndsAt  End of a dunning window.
     * @param  string|null  $manageUrl  Durable subscription management URL.
     */
    public function write(
        Model $team,
        string $plan,
        PlanStatus $status,
        BillingProvider $provider,
        CarbonInterface $eventAt,
        bool $authoritative,
        ?string $providerStatus = null,
        ?string $productId = null,
        ?CarbonInterface $currentPeriodEnd = null,
        ?bool $renews = null,
        ?CarbonInterface $gracePeriodEndsAt = null,
        ?string $manageUrl = null,
    ): bool {
        // 1. Read what is already on record, and rank the move it would make.
        $storedProvider = BillingProvider::fromWire($this->stringAttribute($team, 'plan_provider'));
        $storedPlan = $this->stringAttribute($team, 'plan');
        $storedEventAt = $this->storedEventAt($team);
        $tierOrder = $this->tierOrder();
        $direction = $this->direction($plan, $storedPlan, $tierOrder);

        $context = [
            'team_id' => $team->getKey(),
            'stored_provider' => $storedProvider->value,
            'incoming_provider' => $provider->value,
            'stored_event_at' => $storedEventAt?->toIso8601String(),
            'incoming_event_at' => $eventAt->toIso8601String(),
            'stored_plan' => $storedPlan,
            'incoming_plan' => $plan,
            'direction' => $direction,
        ];

        // 2. RULE 1, monotonic per rail. An event OLDER than the one already on
        // record from the SAME rail is a late or re-ordered delivery, and the
        // record it would overwrite was written from a fresher truth.
        if ($storedProvider === $provider && $this->isOlderThanStored($eventAt, $storedEventAt)) {
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
        // Resolving by direction is what keeps this consistent with rule 2 below:
        // a tie that would TAKE the entitlement away still loses.
        if ($storedProvider === $provider
            && $this->isSameInstantAsStored($eventAt, $storedEventAt)
            && $this->revokes($direction)
        ) {
            $this->logDrop('same-instant revocation', $context);

            return false;
        }

        // 3. RULE 2, a rail may only revoke what it granted. A rail that did not
        // sell this entitlement cannot take it away, and a direction a
        // published catalogue cannot rank counts as revocation for the same
        // reason: an unprovable downgrade applied cross-rail is a revocation
        // with better manners.
        if ($storedProvider !== $provider && $this->revokes($direction)) {
            $this->logDrop('cross-rail revocation', $context);

            return false;
        }

        // 3b. RULE 2b, a PROJECTION may not take over the record of a rail that
        // is still granting.
        //
        // Rule 2 only stops a cross-rail REVOCATION, so a cross-rail write
        // carrying the SAME tier passed and step 5 below then rewrote
        // `plan_provider` unconditionally. The damage lands one step later, which
        // is what makes it hard to see: with the record now naming the new rail,
        // that rail's next revocation is SAME-rail, so rule 2 can no longer see
        // it and rule 1 lets it through. A team billed by one store, still
        // holding an uncancelled subscription on another rail, could have its
        // provenance moved by one ordinary renewal event and then be revoked to
        // free by the cancellation of the rail that was never paying it.
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
        // The status half stays: {@see BillingProvider::grants()} is a per-RAIL
        // table, true for every real rail, so gating on it alone would drop a
        // genuine purchase from a team whose LAPSED record on another rail still
        // names the same tier, and the customer would pay and receive nothing.
        if ($storedProvider !== $provider
            && $storedProvider->grants()
            && $this->storedStatusStillGrants($team)
            && ! $authoritative
        ) {
            $this->logDrop('projected cross-rail takeover', $context);

            return false;
        }

        // 4. A second rail claiming a customer the first rail is still billing
        // means somebody is paying twice. The write lands, but it cannot land
        // quietly. Which of the two lines is right depends on whether rule 2
        // was able to answer at all.
        if ($storedProvider !== $provider && $storedProvider->grants()) {
            if ($tierOrder === []) {
                $this->warnUndecidableTierOrder($context);
            } else {
                $this->warnCrossRailGrant($context);
            }
        }

        // 5. Persist the claim plus the provenance the next write reasons about.
        $team->forceFill([
            'plan' => $plan,
            'plan_status' => $status->value,
            'plan_provider' => $provider->value,
            'plan_source_event_at' => $eventAt,
            'plan_provider_status' => $providerStatus,
            'plan_product_id' => $productId,
            'plan_current_period_end' => $currentPeriodEnd,
            'plan_renews' => $renews,
            'plan_grace_period_ends_at' => $gracePeriodEndsAt,
            'plan_manage_url' => $manageUrl,
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
     * Whether the STATUS already on record is one that entitles the team.
     *
     * Distinct from {@see BillingProvider::grants()}, which answers "is this a
     * real billing rail" for every rail alive. This answers "is that rail
     * currently granting anything", which is the question rule 2b needs: a
     * stored record that has lapsed is not an entitlement another rail can take
     * over, it is a slot another rail can fill.
     */
    protected function storedStatusStillGrants(Model $team): bool
    {
        return PlanStatus::fromWire($this->stringAttribute($team, 'plan_status'))->grants();
    }

    /**
     * Which way this write would move the tier, in terms of the consumer's own
     * published order.
     *
     * Defined once and used by both the rule and the log, because "is this a
     * downgrade" has to mean exactly one thing: two definitions of it would
     * eventually disagree, and the disagreement would be a revocation.
     *
     * @param  list<string>  $tierOrder  The published catalogue, cheapest first.
     * @return self::DIRECTION_* One of the five labels above and nothing else.
     *                           Declared as the closed set rather than as
     *                           `string` so {@see self::revokes()} can be
     *                           checked for exhaustiveness: a sixth direction
     *                           added without a decision in that table is then a
     *                           static error rather than an uncaught exception on
     *                           a payment path.
     */
    protected function direction(string $plan, ?string $storedPlan, array $tierOrder): string
    {
        // No catalogue at all: nothing can be ranked, and on a fresh install
        // that is the normal state rather than a config error.
        if ($tierOrder === []) {
            return self::DIRECTION_INCOMPARABLE;
        }

        // Nothing on record: a write cannot take away what was never granted.
        if ($storedPlan === null) {
            return self::DIRECTION_INCOMPARABLE;
        }

        $incoming = $this->tierRank($plan, $tierOrder);
        $stored = $this->tierRank($storedPlan, $tierOrder);

        if ($incoming === null || $stored === null) {
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
     * `incomparable` is on the other side of that line, and the asymmetry is
     * the same one read from the other end: there is nothing on record for the
     * write to take away, so refusing it could only withhold a tier somebody
     * has already paid for.
     *
     * @param  self::DIRECTION_*  $direction  The closed set, so the `match`
     *                                        below is checked for
     *                                        exhaustiveness rather than only
     *                                        raising at runtime.
     */
    protected function revokes(string $direction): bool
    {
        return match ($direction) {
            self::DIRECTION_DOWNGRADE, self::DIRECTION_UNKNOWN => true,
            self::DIRECTION_UPGRADE, self::DIRECTION_SAME, self::DIRECTION_INCOMPARABLE => false,
        };
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
     * The consumer's tier catalogue, cheapest first.
     *
     * Read from config rather than from any enum here, because the package
     * ships no tier vocabulary at all. Non-string entries are discarded rather
     * than cast: a catalogue holding something other than plan ids is not a
     * catalogue this action can rank against, and silently stringifying it
     * would invent an order.
     *
     * @return list<string>
     */
    protected function tierOrder(): array
    {
        $configured = config('magic-starter.billing.tier_order', []);

        if (! is_array($configured)) {
            return [];
        }

        $order = [];

        foreach ($configured as $planId) {
            if (is_string($planId) && $planId !== '') {
                $order[] = $planId;
            }
        }

        return $order;
    }

    /**
     * Read a stored attribute as a plain string id.
     *
     * A consuming application is free to cast `plan` (or `plan_provider`) to an
     * enum of its own, in which case Eloquent hands back an enum instance
     * rather than the column's text. Unwrapping the backing value here is what
     * keeps rule 2 armed on such a model: a stored tier read as absent would
     * make every cross-rail write look like it had nothing to revoke.
     *
     * Anything that is neither a backed enum nor a non-empty string reads as
     * absent, which is the direction that cannot revoke.
     */
    protected function stringAttribute(Model $team, string $attribute): ?string
    {
        $value = $team->getAttribute($attribute);

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The stored source-event timestamp, whether or not the consumer's model
     * casts that column.
     *
     * The package ships the column but not the model that owns it, so this
     * accepts a cast `datetime` and a raw string alike. A value that is neither
     * reads as absent, which lets the incoming write apply; a malformed date
     * string raises rather than being quietly reordered, because a timestamp
     * this action wrote itself cannot be unparseable without the row being
     * corrupt.
     */
    protected function storedEventAt(Model $team): ?CarbonInterface
    {
        $stored = $team->getAttribute('plan_source_event_at');

        if ($stored instanceof DateTimeInterface) {
            return Carbon::instance($stored);
        }

        if (is_string($stored) && $stored !== '') {
            return Carbon::parse($stored);
        }

        return null;
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
     * Report that rule 2 could not be evaluated because no tier order is
     * published, and that the write was therefore allowed through.
     *
     * This is the honest version of a protection that is switched off. The
     * cross-rail downgrade guard needs an order to compare against, so without
     * one it cannot refuse anything, and an operator who sees rails changing
     * hands on a customer deserves to be told which config key would restore
     * the guard rather than to assume it is working.
     *
     * @param  array<string, mixed>  $context
     */
    protected function warnUndecidableTierOrder(array $context): void
    {
        Log::warning(
            'Entitlement claimed by a second billing rail and the tiers could not be compared; '
            . 'publish magic-starter.billing.tier_order to enable the cross-rail downgrade guard.',
            $context,
        );
    }
}
