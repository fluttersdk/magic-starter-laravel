<?php

namespace FlutterSdk\MagicStarter\Policies;

use FlutterSdk\MagicStarter\Traits\HasTeams;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Who may spend the billable's money.
 *
 * This policy answers ONE question, and the reason it is a single question is
 * that the billing surface splits cleanly in two. The five read endpoints
 * (plan, catalog, usage, invoices, card) are open to any member on purpose: a
 * member is who the upgrade nudges are shown to, and a member who cannot see
 * the usage a nudge is derived from cannot act on it. The four write endpoints
 * (`checkout`, `swap`, `cancel`, `portal`) move money, end a paid period, or
 * open a session that can do both, and those belong to the one person who
 * agreed to be charged: the OWNER. So there is deliberately no read ability
 * beside `manage()`; a read is scoped by membership at the controller and never
 * routes through a gate at all, and a permissive read ability here would be a
 * second place for that rule to disagree with itself.
 *
 * WHO the owner is depends on the billable subject, and the two arms are not
 * two spellings of one rule. Under `'team'` ownership is
 * {@see HasTeams::ownsTeam()}, which is `users.id === teams.user_id`. Under
 * `'user'` the record being billed IS a user, so the question collapses to
 * identity: a user owns their own billing and nobody else's. Teams may still
 * exist in such an application, but they hold no entitlement, so no team role
 * could answer for them. Identity is asked through Eloquent's own `is()`, which
 * requires the key, the table AND the connection to agree, rather than a bare
 * key comparison that would authorize user 7 over account 7.
 *
 * None of the trait's three membership predicates is interchangeable with
 * ownership on the team arm. {@see HasTeams::belongsToTeam()} reads `ownedTeams`
 * MERGED with `teams`, so it is true for owners and members alike and would
 * authorize exactly the caller this policy exists to refuse;
 * {@see HasTeams::hasTeamRole()} is the opposite trap, since it reads the pivot
 * only and its own comment records that owners are deliberately not checked
 * there, so an owner with no pivot row would be refused their own billing.
 *
 * There is no `before()` admin escape hatch. A support operator acting on a
 * customer's card is a decision with a paper trail attached, and it belongs to
 * the admin panel where that trail exists, not to a silent bypass on the
 * customer-facing API.
 *
 * NOT registered as the billable model's policy, and that is not an oversight:
 * `MagicStarterServiceProvider` already binds the configured team model to
 * {@see TeamPolicy} through `Gate::policy()` for the
 * team-management abilities, the policy map is keyed by model class, and
 * `Gate::policy()` is a plain assignment. So with `billing.billable` set to
 * `'team'` a second registration on the same model would silently REPLACE that
 * entry rather than add to it, and the two outcomes are equally bad: either team
 * member management, invitations and deletion go unguarded, or every billing
 * write is refused. Auto-discovery is no help either, since it looks for a
 * policy named after the model and this one is named after the surface it
 * guards. The ability is therefore defined by name in the provider's `boot()`,
 * gated on the billing feature, and the billing controller is its only caller.
 */
class BillingPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user may make a billing change for the billable.
     *
     * @param  mixed  $user  The acting user.
     * @param  mixed  $billable  The record whose billing is being changed.
     */
    public function manage(mixed $user, mixed $billable): bool
    {
        // The token is read here rather than the model type-hinted, for the same
        // reason MagicStarter::billableModel() reads it: the billable's CLASS is
        // the consumer's and cannot say what kind of subject it is. The literal
        // default mirrors that method's, because mergeConfigFrom is a shallow
        // merge and a config published before the key existed carries no key at
        // all; the two must answer alike or the gate and the subject disagree.
        if (config('magic-starter.billing.billable', 'user') === 'team') {
            return $user->ownsTeam($billable);
        }

        return (bool) $billable->is($user);
    }
}
