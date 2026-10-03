<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\Models\SocialAccount;
use FlutterSdk\MagicStarter\Social\AppleProviderFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
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
 * Everything runs in one transaction, so a team refused half way (its billing
 * guard) rolls back the teams already deleted and leaves the account whole.
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
     *                             belongs to, or `DeletesTeams` refuses a solo team.
     */
    public function delete(Authenticatable $user): void
    {
        DB::transaction(function () use ($user): void {
            // 1. A shared team would take other people's data with it.
            if (self::sharedOwnedTeams($user)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'user' => __('magic-starter::social.owns_shared_teams'),
                ]);
            }

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

            // 4. Apple review requires the grant revoked; the factory reports a
            //    failure instead of throwing, so it cannot block the deletion.
            $this->deleteSocialAccounts($user);

            // 5. The account itself.
            $user->tokens()->delete();

            if (! empty($user->profile_photo_path)) {
                Storage::disk(config('magic-starter.profile_photo_disk', 'public'))
                    ->delete((string) $user->profile_photo_path);
            }

            $user->delete();
        });
    }

    /**
     * The teams this user owns that at least one other person belongs to.
     *
     * Shared by the scheduler (which refuses), the purge (which un-schedules or
     * hands the team on) and this action (which refuses), so the three cannot
     * disagree about what "shared" means. Membership is the `team_user` pivot;
     * a pending invitation is not a member.
     *
     * @return Collection<int, Model> empty for a user model without teams
     */
    public static function sharedOwnedTeams(Authenticatable $user): Collection
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
     * Revoke each Apple grant, then delete every linked identity row.
     *
     * Read only when the table exists: an application that never installed
     * social login has no `social_accounts` table, and querying it would fail.
     */
    protected function deleteSocialAccounts(Authenticatable $user): void
    {
        if (! method_exists($user, 'socialAccounts')) {
            return;
        }

        $relation = $user->socialAccounts();

        if (! Schema::hasTable($relation->getRelated()->getTable())) {
            return;
        }

        foreach ($relation->get() as $account) {
            if ($account instanceof SocialAccount && $account->provider === 'apple') {
                $this->apple->revoke($account);
            }
        }

        $relation->delete();
    }
}
