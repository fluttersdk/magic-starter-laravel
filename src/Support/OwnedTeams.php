<?php

namespace FlutterSdk\MagicStarter\Support;

use FlutterSdk\MagicStarter\Actions\SubscriptionGuardedDeleteTeam;
use FlutterSdk\MagicStarter\Enums\AccountDeletionBilling;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The account-deletion pipeline's definitions of "shared" and "billing".
 *
 * The scheduler refuses on them, the purge un-schedules or holds on them, and
 * the default deleter refuses on them, so they live outside every one of those
 * classes: a consumer who rebinds `DeletesUsers` or `SchedulesUserDeletion`
 * keeps the same answers the purge acts on. The two policy switches they share
 * are read here too: whether a shared team is handed on instead of refused, and
 * whether a subscription billing the user is ended instead of refused.
 */
final class OwnedTeams
{
    use ReadsBillableAttributes;

    private function __construct() {}

    /**
     * Whether a shared team is handed to a member instead of refusing the deletion.
     *
     * Read from `account_deletion.hand_on_shared_teams`. The literal default is
     * load-bearing: `mergeConfigFrom` is a shallow merge, so a config published
     * before the key existed replaces the whole `account_deletion` array and
     * finds no such path.
     */
    public static function handsOnShared(): bool
    {
        return (bool) config('magic-starter.account_deletion.hand_on_shared_teams', false);
    }

    /**
     * Whether a subscription that bills the user is ended for them instead of
     * refusing the deletion.
     *
     * Read from `account_deletion.billing` through {@see AccountDeletionBilling},
     * which refuses a value that is not a mode. Only the user's own subscription
     * is affected: a team a store or Stripe bills still refuses and holds.
     *
     * @throws RuntimeException When the configured value is not a mode.
     */
    public static function releasesBilling(): bool
    {
        return AccountDeletionBilling::configured() === AccountDeletionBilling::RELEASE;
    }

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
