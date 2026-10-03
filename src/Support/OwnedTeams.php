<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Actions\SubscriptionGuardedDeleteTeam;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The account-deletion pipeline's definitions of "shared" and "billing".
 *
 * The scheduler refuses on them, the purge un-schedules or holds on them, and
 * the default deleter refuses on them, so they live outside every one of those
 * classes: a consumer who rebinds `DeletesUsers` or `SchedulesUserDeletion`
 * keeps the same answers the purge acts on.
 */
final class OwnedTeams
{
    use ReadsBillableAttributes;

    private function __construct() {}

    /**
     * The teams this user owns that at least one other person belongs to.
     *
     * Membership is the `team_user` pivot; a pending invitation is not a member.
     *
     * @return Collection<int, Model> empty for a user model without teams
     */
    public static function shared(Authenticatable $user): Collection
    {
        if (! method_exists($user, 'ownedTeams')) {
            return new Collection;
        }

        $userKey = $user->getAuthIdentifier();

        return $user->ownedTeams()
            ->whereHas(
                'users',
                fn ($members) => $members->where('team_user.user_id', '!=', $userKey),
            )
            ->get();
    }

    /**
     * The teams this user owns that a store or a valid Cashier subscription is
     * still billing; always empty under user billing.
     *
     * @return Collection<int, Model> empty for a user model without teams
     */
    public static function billing(Authenticatable $user): Collection
    {
        if (! method_exists($user, 'ownedTeams')) {
            return new Collection;
        }

        return $user->ownedTeams()->get()->filter(
            fn (Model $team): bool => self::isBilling($team),
        )->values();
    }

    /**
     * Whether a store or a valid Cashier subscription is billing this subject.
     *
     * Applied to a team by {@see self::billing()} and to the user row itself by
     * the pipeline, since under `magic-starter.billing.billable = 'user'` the
     * money is on the user. A subject that is not the configured billable
     * answers false on both rails.
     */
    public static function isBilling(Model $billable): bool
    {
        // The card-rail predicate is an instance method on the shared trait, so
        // a throwaway instance reads through it rather than carrying a copy.
        return SubscriptionGuardedDeleteTeam::storeIsBilling($billable)
            || (new self)->stripeIsBilling($billable);
    }
}
