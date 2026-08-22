<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for the single code path that writes a team's entitlement.
 *
 * A team's tier is one column, and more than one payment rail can feed it. Two
 * feeders writing the same column unconditionally is not two features, it is a
 * race: whichever event is delivered last wins, and delivery order is a
 * property of the internet rather than of the truth. Every feeder therefore
 * goes through an implementation of this contract, which owns the ordering
 * decision so no feeder has to.
 *
 * The tier arrives as a STRING plan id, never an enum, because the tier
 * vocabulary belongs to the consuming application: this package has no opinion
 * about what `business` means or how many tiers exist. `$status` and
 * `$provider` are enums for the opposite reason: they ARE this package's
 * vocabulary, so an unrecognised word must not be able to reach the column
 * that every reader downstream gates on. A rail's own word is mapped by the
 * feeder that speaks it and survives verbatim in `$providerStatus`.
 *
 * @see \FlutterSdk\MagicStarter\Actions\WriteTeamEntitlement the default
 *      implementation and the two ordering rules it enforces
 */
interface WritesTeamEntitlement
{
    /**
     * Apply one rail's claim to the given team's entitlement columns.
     *
     * CALL THIS WITH NAMED ARGUMENTS. Six of the twelve parameters are
     * nullable, three of them are strings and two are timestamps, so a
     * positional call site can transpose a pair without any type error to show
     * for it. Named arguments are what makes that impossible.
     *
     * @param  Model  $team  The team whose entitlement this claim is about.
     * @param  string  $plan  The consumer-defined plan id the rail says is owed.
     * @param  PlanStatus  $status  Where that tier stands, in neutral words.
     * @param  BillingProvider  $provider  The rail making the claim.
     * @param  CarbonInterface  $eventAt  The SOURCE event's own timestamp, not
     *                                    the moment of delivery and not `now()`.
     *                                    This is what makes an out-of-order
     *                                    delivery detectable at all, so a feeder
     *                                    passing the receipt time instead of the
     *                                    event time disarms the ordering rule
     *                                    while looking correct.
     * @param  bool  $authoritative  Whether this claim comes from READING the
     *                               rail, or from projecting state the rail
     *                               wrote into your database earlier. Required,
     *                               with no default, because a new feeder has to
     *                               decide which it is rather than inherit a
     *                               quiet answer. True for a webhook payload or
     *                               a re-read of the rail's API; FALSE for a
     *                               claim assembled from a local row, which can
     *                               be a whole period behind while looking
     *                               exactly like a fresh one. Only an
     *                               authoritative claim may move a team from one
     *                               rail to another.
     * @param  string|null  $providerStatus  The rail's own status word, verbatim.
     * @param  string|null  $productId  The rail-native product or price id.
     * @param  CarbonInterface|null  $currentPeriodEnd  When the paid period ends,
     *                                                  whether or not it renews.
     * @param  bool|null  $renews  Auto-renew state. Null means the rail has not
     *                             said, which is not the same claim as false.
     * @param  CarbonInterface|null  $gracePeriodEndsAt  End of a dunning window.
     * @param  string|null  $manageUrl  Where the customer manages this
     *                                  subscription, on the rail that sold it.
     *                                  Only durable destinations belong here; a
     *                                  short-lived portal session does not.
     * @return bool True when the columns were written, false when an ordering
     *              rule dropped the write. Every false return has logged why.
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
    ): bool;
}
