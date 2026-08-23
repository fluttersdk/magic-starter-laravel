<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract for reporting a billable's consumption against its plan limits.
 *
 * DELIBERATELY SHIPPED WITH NO DEFAULT IMPLEMENTATION AND NO BINDING. Usage is
 * consumer-domain: this package has no vocabulary for what is being counted
 * (monitors, checks, seats, whatever a plan happens to cap) and no source for
 * a tier's limits, since the tier catalogue itself lives entirely in the
 * consuming application (see the `billing.tier_order` docblock in
 * `config/magic-starter.php`). So there is nothing correct to bind by default,
 * only a shape a consumer can implement once it has both.
 *
 * The refusal to bind ANYTHING, not even an implementation that returns an
 * empty array, is load-bearing rather than cautious. A consumer that gates a
 * cap on this answer reads an absent key as zero used (a `null ?? 0` at the
 * call site), and a gate of that shape typically reads
 * `$limit === null || $used < $limit`. An empty map therefore does not read as
 * "unknown", it reads as "you have used nothing", which opens EVERY cap it
 * guards rather than refusing to answer. This is not a hypothetical: a gate
 * that matched on the wrong field once found nothing, every cap below it read
 * zero usage, and a team already at its limit kept creating resources until
 * the backend eventually refused for an unrelated reason. Binding a "safe"
 * default here would silently reproduce that exact shape, and reproduce it in
 * every application that installs this package rather than in the one that
 * made the original mistake.
 *
 * The endpoint this contract feeds is therefore registered only when a
 * consumer has bound an implementation: an unbound usage endpoint answering
 * 404 is an honest "not wired yet", while a bound one answering an empty map
 * is a gate believing a lie. See the `billing` block in
 * `config/magic-starter.php` for where the binding is made.
 */
interface ReportsUsage
{
    /**
     * Report how much a billable has used against each metric it is capped on.
     *
     * The subject is a plain `Model` and is named for the ROLE it plays, not
     * for any one kind of record, matching {@see WritesEntitlement}: nothing
     * here reads a team relation, a membership or an owner.
     *
     * Both `used` and `limit` travel together per metric rather than as two
     * parallel maps, because a count with no limit to compare it against is
     * not an answer a gate can act on; the package refuses to ship a shape
     * that invites returning counts alone. A `null` limit means the metric is
     * uncapped for this billable, never "unknown": an implementation that does
     * not know a limit must not claim uncapped, it must omit the metric.
     *
     * @param  Model  $billable  The subject to report usage for.
     * @return array<string, array{used: int, limit: int|null}> One entry per
     *                                                          metric this
     *                                                          billable is
     *                                                          capped on. A
     *                                                          metric absent
     *                                                          from the
     *                                                          result is
     *                                                          unknown to the
     *                                                          caller, not
     *                                                          zero and not
     *                                                          uncapped.
     */
    public function forBillable(Model $billable): array;
}
