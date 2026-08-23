<?php

namespace FlutterSdk\MagicStarter\Support;

use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Contracts\WritesEntitlement;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * One rail's complete claim about what a billable is entitled to, and when it
 * said so.
 *
 * This is the only shape {@see WritesEntitlement} accepts, and it is a value
 * object rather than a parameter list for two reasons. Twelve positional
 * arguments of which seven are nullable, four of them strings and three of them
 * timestamps, is a call site nobody can read, and worse, two of those arguments
 * can be silently transposed; and the ordering rules the implementation enforces
 * need `provider` and `eventAt` to travel WITH the tier they justify. A caller
 * that can pass a tier without saying which rail claimed it and when could not
 * be checked at all.
 *
 * The subject is a plain `Model`, named for the ROLE it plays rather than for any
 * one kind of record, because which kind an application bills is the
 * application's own decision: a team on one deployment, a user on another.
 * Nothing here reads a team relation, a membership or an owner, so nothing here
 * has to know which it was handed.
 *
 * `provider` and `status` are enums, not strings, deliberately. A rail's own
 * vocabulary is mapped by the feeder that speaks it, before it reaches here, so
 * an unrecognised word cannot arrive in a column that the neutral wire and the
 * `grants()` predicates both read. `plan` is a plain string for the opposite
 * reason: the tier vocabulary belongs to the consuming application, and this
 * package has no opinion about what `business` means or how many tiers exist.
 *
 * `providerStatus` is the other half of that trade: the rail's word survives
 * verbatim, in a field that is opaque debug text and gates nothing.
 */
readonly class EntitlementWrite
{
    /**
     * CONSTRUCT THIS WITH NAMED ARGUMENTS. Seven of the twelve fields are
     * nullable, four of them are strings and three are timestamps, so a
     * positional call site can transpose a pair without any type error to show
     * for it. Named arguments are what makes that impossible.
     *
     * @param  Model  $billable  The subject whose entitlement this claim is
     *                           about, whatever an application bills.
     * @param  string|null  $plan  The consumer-defined plan id the rail says is
     *                             owed, or NULL for a rail saying nothing is
     *                             owed at all. Null is how a revocation says
     *                             what it means. The alternative is naming a
     *                             free-tier id, which this package cannot know
     *                             (the vocabulary is the consumer's), and an
     *                             implementation that invents one gets the
     *                             comparison wrong in both directions at once:
     *                             no change against a billable already on that
     *                             tier, and unrankable in a catalogue that
     *                             publishes no such row. Both readings let a
     *                             cross-rail cancellation through. A free tier
     *                             stays a tier a product may sell and remains a
     *                             legitimate value here; what it may not mean is
     *                             absence.
     * @param  PlanStatus  $status  Where that tier stands, in neutral words.
     * @param  BillingProvider  $provider  The rail making the claim.
     * @param  CarbonInterface  $eventAt  The SOURCE event's own timestamp, not
     *                                    the moment of delivery and not `now()`.
     *                                    This is what makes an out-of-order
     *                                    delivery detectable at all, so a feeder
     *                                    passing the receipt time instead of the
     *                                    event time disarms the ordering rule
     *                                    while looking correct.
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
     */
    public function __construct(
        public Model $billable,
        public ?string $plan,
        public PlanStatus $status,
        public BillingProvider $provider,
        public CarbonInterface $eventAt,
        /**
         * Whether this claim comes from READING the rail, or from projecting
         * local state that the rail wrote earlier.
         *
         * Required, with no default, for the same reason the provider `match`
         * has no `default` arm: a new feeder has to decide which of the two it
         * is rather than inheriting a quiet answer, and the two are not
         * interchangeable at the point where a record changes hands. Only an
         * authoritative claim may move a billable from one rail to another.
         *
         * TRUE for a webhook payload and for a re-read of the rail's own API:
         * the rail is telling us, now, what it believes.
         *
         * FALSE for a claim assembled from something the rail wrote into the
         * consumer's database earlier. An invoice re-affirmation reading a local
         * subscription row, and a reconciler reading that same row, are both a
         * projection, and both can be a whole period behind while looking
         * exactly like a fresh claim.
         *
         * The distinction earns its place by having been reconstructed from
         * proxies twice and got wrong both times. A projection sharing a Stripe
         * second with the event that superseded it needed a tie-break rule; a
         * projection taking `plan_provider` from a rail that was still billing
         * needed another. Both were this field, worn as a rule.
         */
        public bool $authoritative,
        public ?string $providerStatus = null,
        public ?string $productId = null,
        public ?CarbonInterface $currentPeriodEnd = null,
        public ?bool $renews = null,
        public ?CarbonInterface $gracePeriodEndsAt = null,
        public ?string $manageUrl = null,
    ) {}
}
