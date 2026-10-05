<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\Models\SocialAccount;
use FlutterSdk\MagicStarter\Social\AppleProviderFactory;
use FlutterSdk\MagicStarter\Support\OwnedTeams;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Default user deletion: the irreversible end of the scheduled account-deletion
 * pipeline, run by `magic-starter:purge-deleted-users` once the grace period is over.
 *
 * Owned teams are deleted explicitly through {@see DeletesTeams} rather than
 * left to the `teams.user_id` cascade, because the cascade bypasses every guard
 * on that contract: a store or card subscription would keep charging a team that
 * no longer exists. A team somebody else still belongs to is never deleted with
 * its owner; the purge hands it on or un-schedules the account first, and this
 * action refuses if neither happened.
 *
 * Everything in the database runs in one transaction, so a team refused half
 * way (its billing guard) rolls back the teams already deleted and leaves the
 * account whole. The Apple revocations and the profile photo cannot be rolled
 * back, so they run only after that transaction commits.
 */
class DeleteUser implements DeletesUsers
{
    public function __construct(
        protected DeletesTeams $teams,
        protected AppleProviderFactory $apple,
    ) {}

    /**
     * Delete the given user, their solo teams and their linked identities.
     *
     * @param  Authenticatable  $user  The user to delete.
     *
     * @throws ValidationException When the user still owns a team another member
     *                             belongs to, a subscription bills the user, or
     *                             `DeletesTeams` refuses a solo team.
     */
    public function delete(Authenticatable $user): void
    {
        [$appleAccounts, $photoPath] = DB::transaction(function () use ($user): array {
            // 1. A shared team would take other people's data with it, and a
            //    subscription on the user's own row would keep charging nobody.
            $this->refuseUnlessDeletable($user);

            // 2. Through the contract, so the billing guards run on every team.
            if (method_exists($user, 'ownedTeams')) {
                foreach ($user->ownedTeams()->get() as $team) {
                    $this->teams->delete($team);
                }
            }

            // 3. Memberships of other people's teams; those teams stay.
            if (method_exists($user, 'teams')) {
                $user->teams()->detach();
            }

            // 4. The linked identities, keeping the Apple rows to revoke later.
            $appleAccounts = $this->deleteSocialAccounts($user);

            // 5. The account itself.
            $user->tokens()->delete();

            $photoPath = empty($user->profile_photo_path) ? null : (string) $user->profile_photo_path;

            $user->delete();

            return [
                $appleAccounts,
                $photoPath,
            ];
        });

        // 6. Neither can be rolled back, so both wait for the OUTERMOST commit:
        //    the purge runs this inside its own transaction, and a grant revoked
        //    for an account that then survives cannot be restored. Apple review
        //    requires the revocation; the factory reports a failure instead of
        //    throwing, so it cannot undo the deletion.
        DB::afterCommit(function () use ($appleAccounts, $photoPath): void {
            foreach ($appleAccounts as $account) {
                $this->apple->revoke($account);
            }

            if ($photoPath !== null) {
                Storage::disk(config('magic-starter.profile_photo_disk', 'public'))->delete($photoPath);
            }
        });
    }

    /**
     * Refuse a user who still owns a shared team or whom a subscription bills.
     *
     * @throws ValidationException
     */
    protected function refuseUnlessDeletable(Authenticatable $user): void
    {
        if (OwnedTeams::shared($user)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'user' => __('magic-starter::social.owns_shared_teams'),
            ]);
        }

        if ($user instanceof Model && OwnedTeams::isBilling($user)) {
            throw ValidationException::withMessages([
                'user' => __('magic-starter::social.subscription_active'),
            ]);
        }
    }

    /**
     * Delete every linked identity row and return the Apple ones, whose grants
     * are revoked once the deletion has committed.
     *
     * Read only when the table exists: an application that never installed
     * social login has no `social_accounts` table, and querying it would fail.
     *
     * @return list<SocialAccount>
     */
    protected function deleteSocialAccounts(Authenticatable $user): array
    {
        if (! method_exists($user, 'socialAccounts')) {
            return [];
        }

        $relation = $user->socialAccounts();

        if (! Schema::hasTable($relation->getRelated()->getTable())) {
            return [];
        }

        $appleAccounts = [];

        foreach ($relation->get() as $account) {
            if ($account instanceof SocialAccount && $account->provider === 'apple') {
                $appleAccounts[] = $account;
            }
        }

        $relation->delete();

        return $appleAccounts;
    }
}
