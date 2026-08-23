<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Http\Resources\SubscriptionResource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\ReadsBillableAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Wraps the default {@see DeleteTeam} with a refusal while a STORE is still
 * billing the team.
 *
 * Bound over the {@see DeletesTeams} contract in the package's own
 * `MagicStarterServiceProvider::register()` (the same contract-action override
 * pattern every other action here uses), because team deletion is the
 * package's own endpoint and a consuming application owns no team route to put
 * a guard on.
 *
 * WHY DELETION IS DIFFERENT ON THIS RAIL
 *
 * On the card rail, deleting the team is the end of the story: the consuming
 * application is the merchant, so nothing keeps charging once the row is
 * gone. A store subscription lives in the customer's App Store or Play
 * account, which the application cannot cancel and cannot even see: a webhook
 * is the only thing that tells it anything about it. So deleting the team
 * removes the entitlement while the store keeps taking money every month, and
 * the only person who can stop it is the owner, in the store's own account
 * surface. Refusing the deletion until they have been there is the
 * difference between an ex-customer and a customer being charged for
 * nothing.
 *
 * The refusal is thrown BEFORE `parent::delete()` runs, which is
 * load-bearing: the parent action detaches every member and deletes every
 * invitation before it deletes the team, so a guard placed after it would
 * leave a team that still exists and that nobody belongs to.
 *
 * A {@see ValidationException} rather than a 409, matching the refusal the
 * package's own team endpoint already raises for a personal team: one
 * endpoint answering two refusals in two shapes would make the client's
 * error handling depend on which one it hit.
 */
class StoreSubscriptionGuardedDeleteTeam extends DeleteTeam
{
    use ReadsBillableAttributes;

    /**
     * Delete the team, unless a store is still billing it.
     *
     * @param  Model  $team  The team to delete.
     *
     * @throws ValidationException When a live store subscription funds the team.
     */
    public function delete(Model $team): void
    {
        if (self::storeIsBilling($team)) {
            throw ValidationException::withMessages([
                'team' => __('magic-starter::billing.refusals.store_subscription_active'),
            ])->errorBag('deleteTeam');
        }

        parent::delete($team);
    }

    /**
     * Whether a store is billing this team RIGHT NOW.
     *
     * Three conditions, and every one of them matters:
     *
     * - the rail is a store, read through {@see BillingProvider::fromWire()}
     *   because `plan_provider` is an uncast column by design and a value
     *   this build has never heard of has to land on `NONE` instead of
     *   raising;
     * - the tier is not absent, and
     * - {@see PlanStatus::grants()} says the plan is still owed to them,
     *   which keeps a dunning subscription (`past_due`, `grace`) inside the
     *   guard: the rail is still trying to take the money, which is exactly
     *   the state where deleting the team strands a charge.
     *
     * `plan_provider` is PROVENANCE and it survives the subscription ending,
     * so a guard on the rail alone would refuse to delete every team that
     * ever bought in a store, forever.
     *
     * The tier half is a NULL CHECK and nothing more, the same one
     * {@see SubscriptionResource::subscribed()} makes. The application this
     * action was ported from read a typed tier ACCESSOR here instead, one
     * that answered its own free tier for both a stored `'free'` value and a
     * revoked NULL, so that call site never had to re-decide what NULL meant.
     * This package has no tier vocabulary to build such an accessor from, and
     * comparing the raw column against a literal `'free'` would get the
     * revoked case wrong: NULL is not `'free'`, so that comparison would
     * answer true, and a team the store stopped billing would never be
     * deletable again.
     *
     * What the null check does NOT buy is the accessor's other half. A
     * consuming application that writes its own free-tier WORD into the
     * column on a downgrade, rather than the NULL this package's own writer
     * stores, reads as store-billed here and cannot delete the team. That is
     * the same price {@see SubscriptionResource::subscribed()} already pays
     * for the same reason, and it is a heavier one here, because a badge that
     * reads wrong is not a deletion that is refused with a sentence naming a
     * subscription the customer no longer has. The adopter's own
     * `magic-starter.billing.tier_order` names their floor tier and would
     * close it; wiring that in belongs with the read endpoints that share
     * this predicate, so both answers move together rather than the package
     * growing two definitions of "holds a paid tier".
     *
     * It is `public static` so it can be shared with the read endpoints'
     * equivalent check on a caller's OTHER teams, which is deliberate rather
     * than convenient: two copies of "a store is billing this billable"
     * would be two definitions of a rule that decides whether money keeps
     * moving.
     *
     * The billable-type check below generalises the port rather than
     * assuming a team. This action only ever fires for a team (it exists
     * behind {@see DeletesTeams}), but that team's own row carries
     * entitlement columns only when `magic-starter.billing.billable` is
     * `'team'`. Under `'user'` billing a team's row has no rail on it at
     * all, so the check reads false and the deletion proceeds unguarded,
     * correctly: the money is on the user's row, not the team's.
     *
     * @param  Model  $team  Any team-shaped model; one that is not the
     *                       configured billable subject answers false.
     */
    public static function storeIsBilling(Model $team): bool
    {
        $billableClass = MagicStarter::billableModel();

        if (! $team instanceof $billableClass) {
            return false;
        }

        // A trait method is instance-scoped, and this predicate is
        // deliberately static, so a throwaway instance is what lets it read
        // through the same decoder rather than carrying a private copy.
        $reader = new self;

        if (! BillingProvider::fromWire($reader->stringAttribute($team, 'plan_provider'))->isStore()) {
            return false;
        }

        return $reader->stringAttribute($team, 'plan') !== null
            && PlanStatus::fromWire($reader->stringAttribute($team, 'plan_status'))->grants();
    }
}
