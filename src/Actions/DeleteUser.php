<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Jobs\DeleteOneSignalUser;
use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use FlutterSdk\MagicStarter\Models\NotificationSetting;
use FlutterSdk\MagicStarter\Models\SocialAccount;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessageAttachment;
use FlutterSdk\MagicStarter\Social\AppleProviderFactory;
use FlutterSdk\MagicStarter\Support\OneSignalSubscriptions;
use FlutterSdk\MagicStarter\Support\OwnedTeams;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
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
 * account whole. The Apple revocations, the profile photo and the files of the
 * user's support conversations cannot be rolled back, so they run only after
 * that transaction commits, and so does the deletion of the user from OneSignal,
 * which holds the push and SMS subscriptions and erases them with the user (a
 * queued job, run after the outermost commit like the Apple revocation). The
 * conversations and their attachment rows go by database cascade, which fires
 * no model event, so the file paths are read before the account row is deleted.
 */
class DeleteUser implements DeletesUsers
{
    /**
     * Rows erased per query from each user-scoped table.
     */
    private const ERASE_CHUNK = 500;

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
        [$appleAccounts, $photoPath, $supportFiles, $oneSignalId] = DB::transaction(function () use ($user): array {
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

            // 5. What the package stored about the person outside the user row.
            $this->eraseNotificationData($user);

            // 6. The account itself, its support files located before the cascade takes their rows.
            $user->tokens()->delete();

            $photoPath = empty($user->profile_photo_path) ? null : (string) $user->profile_photo_path;
            $supportFiles = $this->supportAttachmentFiles($user);

            // The alias is read while the row still exists; the job carries the
            // id because the model is gone by the time it runs.
            $oneSignalId = OneSignalSubscriptions::provisioned() && $user instanceof Model
                ? OneSignalSubscriptions::externalIdFor($user)
                : null;

            $user->delete();

            return [
                $appleAccounts,
                $photoPath,
                $supportFiles,
                $oneSignalId,
            ];
        });

        // 7. None of these can be rolled back, so all wait for the OUTERMOST commit:
        //    the purge runs this inside its own transaction, and a grant revoked
        //    for an account that then survives cannot be restored. Apple review
        //    requires the revocation; the factory reports a failure instead of
        //    throwing, so it cannot undo the deletion. OneSignal holds the push
        //    and SMS subscriptions, so deleting its user erases them; on a `sync`
        //    queue the job runs right here, so a failure is reported and never
        //    stops the file cleanup below.
        DB::afterCommit(function () use ($appleAccounts, $photoPath, $supportFiles, $oneSignalId): void {
            foreach ($appleAccounts as $account) {
                $this->apple->revoke($account);
            }

            if ($oneSignalId !== null) {
                rescue(function () use ($oneSignalId): void {
                    DeleteOneSignalUser::dispatch($oneSignalId);
                }, report: true);
            }

            if ($photoPath !== null) {
                Storage::disk(config('magic-starter.profile_photo_disk', 'public'))->delete($photoPath);
            }

            foreach ($supportFiles as $disk => $paths) {
                Storage::disk($disk)->delete($paths);
            }
        });
    }

    /**
     * The disk and path of every file on the user's support conversations, grouped by disk.
     *
     * Read only when the table exists: an application that never ran the
     * attachment migration has none.
     *
     * @return array<string, list<string>>
     */
    protected function supportAttachmentFiles(Authenticatable $user): array
    {
        $attachment = new SupportMessageAttachment;

        if (! Schema::hasTable($attachment->getTable())) {
            return [];
        }

        $files = [];

        SupportMessageAttachment::query()
            ->whereIn(
                'conversation_id',
                SupportConversation::query()->select('id')->where('user_id', $user->getAuthIdentifier()),
            )
            ->toBase()
            ->get([
                'disk',
                'path',
            ])
            ->each(function (object $row) use (&$files): void {
                $files[(string) $row->disk][] = (string) $row->path;
            });

        return $files;
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

    /**
     * Delete the user's database notifications, notification settings and
     * newsletter subscription, with the audit rows that describe them.
     *
     * The audit trail forgets the user's own rows after commit, but it finds
     * related rows through a `user_id` column, which none of these tables has:
     * notifications and settings are addressed by the notifiable morph, and a
     * subscription by email. Without this their audit rows would keep the
     * notification payloads and the address after the person is gone.
     *
     * Each table is read only when it exists, because an application that never
     * installed the feature has none, and a feature switched off since still left
     * its rows behind. Deleted through the query builder so the erasure itself
     * fires no model event and writes no new audit row.
     */
    protected function eraseNotificationData(Authenticatable $user): void
    {
        $notifiable = [
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getAuthIdentifier(),
        ];

        $this->eraseRows('notifications', new DatabaseNotification, $notifiable);
        $this->eraseRows((new NotificationSetting)->getTable(), new NotificationSetting, $notifiable);

        if (! empty($user->email)) {
            $this->eraseRows((new NewsletterSubscriber)->getTable(), new NewsletterSubscriber, [
                'email' => $user->email,
            ]);
        }
    }

    /**
     * Delete the rows matching the given columns, and their audit rows first.
     *
     * Chunked by id so a user with thousands of notifications never binds more
     * keys into one query than the driver accepts.
     *
     * @param  Model  $auditable  An instance of the model the trail records these rows as.
     * @param  array<string, mixed>  $match  Column to value, all of which must hold.
     */
    private function eraseRows(string $table, Model $auditable, array $match): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $auditing = Features::hasAuditFeatures();

        DB::table($table)
            ->where($match)
            ->chunkById(self::ERASE_CHUNK, function (Collection $rows) use ($table, $auditable, $auditing): void {
                $ids = $rows->pluck('id')->all();

                if ($auditing) {
                    Audit::query()
                        ->where('auditable_type', $auditable->getMorphClass())
                        ->whereIn('auditable_id', array_map('strval', $ids))
                        ->delete();
                }

                DB::table($table)->whereIn('id', $ids)->delete();
            });
    }
}
