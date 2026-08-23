<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Support\EntitlementWrite;

/**
 * Contract for the single code path that writes a billable's entitlement.
 *
 * A billable's tier is one column, and more than one payment rail can feed it.
 * Two feeders writing the same column unconditionally is not two features, it is
 * a race: whichever event is delivered last wins, and delivery order is a
 * property of the internet rather than of the truth. Every feeder therefore
 * goes through an implementation of this contract, which owns the ordering
 * decision so no feeder has to.
 *
 * The subject is a plain `Model`, and it is named for the ROLE it plays rather
 * than for any one kind of record, because which kind an application bills is
 * the application's own decision: a team on one deployment, a user on another.
 * Nothing here reads a team relation, a membership or an owner, so nothing here
 * has to know which it was handed.
 *
 * The tier arrives as a STRING plan id, never an enum, because the tier
 * vocabulary belongs to the consuming application: this package has no opinion
 * about what `business` means or how many tiers exist. `$status` and
 * `$provider` are enums for the opposite reason: they ARE this package's
 * vocabulary, so an unrecognised word must not be able to reach the column
 * that every reader downstream gates on. A rail's own word is mapped by the
 * feeder that speaks it and survives verbatim in `$providerStatus`.
 *
 * @see \FlutterSdk\MagicStarter\Actions\WriteEntitlement the default
 *      implementation and the four ordering rules it enforces
 */
interface WritesEntitlement
{
    /**
     * Apply one rail's claim to the given billable's entitlement columns.
     *
     * The claim arrives as ONE value object rather than as twelve parameters,
     * and {@see EntitlementWrite} carries the reasoning for every field it
     * holds. Twelve positional arguments of which seven are nullable is a call
     * site nobody can read, and two of them can be silently transposed; and the
     * ordering rules an implementation enforces need the rail and the source
     * timestamp to travel WITH the tier they justify, which a parameter list
     * cannot guarantee and a constructor can.
     *
     * @param  EntitlementWrite  $write  One rail's complete claim: the subject,
     *                                   the tier, where it stands, which rail
     *                                   said so, when that rail's own event
     *                                   happened, and whether the rail is
     *                                   speaking for itself.
     * @return bool True when the columns were written, false when an ordering
     *              rule dropped the write. Every false return has logged why.
     */
    public function write(EntitlementWrite $write): bool;
}
