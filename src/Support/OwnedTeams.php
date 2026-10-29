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
     * still billing, trashed ones included; always empty under user billing.
     *
     * A trashed team is hidden, not gone, and is deleted through the same
     * guard when its window closes or its owner's account is purged, so a
     * subscription that still bills it holds the account back like any other.
     *
     * @return Collection<int, Model> empty for a user model without teams
     */
    public static function billing(Authenticatable $user): Collection
    {
        return self::includingTrashed($user)->filter(
            fn (Model $team): bool => self::isBilling($team),
        )->values();
    }

    /**
     * Every team this user owns, trashed ones included when the team model can be trashed.
     *
     * The account purge deletes all of them, so it reads this where the
     * lists a person sees read `ownedTeams()`. A team model that does not use
     * SoftDeletes has no trashed rows and no `withTrashed()` to ask for.
     *
     * @return Collection<int, Model> empty for a user model without teams
     */
    public static function includingTrashed(Authenticatable $user): Collection
    {
        if (! method_exists($user, 'ownedTeams')) {
            return new Collection;
        }

        $teams = $user->ownedTeams();

        if (SoftDeletingTeams::usedBy($teams->getRelated())) {
            $teams->withTrashed();
        }

        return $teams->get();
    }

    /**
     * Whether a subscription still bills this USER in a way that holds their
     * deletion back at the purge and in the deleter.
     *
     * Any rail does, in the default refuse mode. With billing released, only a
     * valid card subscription does, and that is deliberate rather than a
     * leftover of the refusal. The scheduler ends the card rail's subscriptions
     * when the deletion is asked for, so one still valid when the purge runs is
     * one it never saw: an orphan scheduled while the host still refused (orphans
     * skip every refusal), an account locked by a custom `SchedulesUserDeletion`
     * that does not release, a checkout completed after the lock. Neither the
     * purge nor the deleter releases anything itself (the purge holds a row lock
     * and the deleter a transaction, and neither belongs across a remote call),
     * so deleting that user would leave Stripe charging a customer who no longer
     * exists. A store's subscription never holds a released deletion: only the
     * customer can cancel it there, and the deletion goes ahead either way.
     */
    public static function blocksUserDeletion(Model $user): bool
    {
        if (! self::releasesBilling()) {
            return self::isBilling($user);
        }

        return (new self)->stripeIsBilling($user);
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
