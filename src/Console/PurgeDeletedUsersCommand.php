<?php

namespace FlutterSdk\MagicStarter\Console;

use FlutterSdk\MagicStarter\Actions\DeleteUser;
use FlutterSdk\MagicStarter\Actions\ScheduleUserDeletion;
use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeamMemberRoles;
use FlutterSdk\MagicStarter\Enums\Role;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Deletes the accounts whose deletion grace period is over: the only place in
 * the pipeline where an account is actually removed.
 *
 * Everything the scheduler checked is checked again here, because the grace
 * period is long enough for it to change:
 *
 * - a user who asked, and whose team has since gained a member, is un-scheduled
 *   and reported, since deleting them would take that member's team with them;
 * - an account owning a team a store or Cashier is still billing is HELD and
 *   reported, since deleting the team strands the charge and this package never
 *   cancels a subscription for anyone;
 * - an orphan's shared teams are handed on, to the earliest-joined admin and
 *   else the earliest-joined member, who becomes owner;
 * - the rest are deleted through {@see DeletesUsers}.
 *
 * The package registers the command and does not schedule it; the consuming
 * application decides when it runs. Held and un-scheduled accounts are logged
 * as well as printed, because a scheduled run's output is usually discarded.
 */
class PurgeDeletedUsersCommand extends Command
{
    public const NAME = 'magic-starter:purge-deleted-users';

    /**
     * @var string
     */
    protected $signature = self::NAME;

    /**
     * @var string
     */
    protected $description = 'Delete the accounts whose deletion grace period has ended';

    /**
     * Purge every account scheduled before the grace period's cutoff.
     *
     * One account failing is reported and the run moves on to the next, so a
     * single bad row cannot keep every other account alive; the exit code says
     * a failure happened.
     */
    public function handle(DeletesUsers $deleter, UpdatesTeamMemberRoles $roles): int
    {
        $userModel = MagicStarter::userModel();

        /** @var Model $prototype */
        $prototype = new $userModel;

        // 1. A users table without the column has nothing scheduled on it.
        if (! Schema::hasColumn($prototype->getTable(), 'deletion_scheduled_at')) {
            $this->components->info('The users table has no deletion columns; nothing to purge.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays(max(0, (int) config('magic-starter.account_deletion.grace_days', 30)));

        $tally = [
            'deleted' => 0,
            'held' => 0,
            'unscheduled' => 0,
            'failed' => 0,
        ];

        // 2. By key, so rows deleted or un-scheduled mid-walk cannot shift a page.
        $due = $prototype->newQuery()
            ->whereNotNull('deletion_scheduled_at')
            ->where('deletion_scheduled_at', '<=', $cutoff)
            ->lazyById();

        foreach ($due as $user) {
            try {
                $tally[$this->purge($user, $deleter, $roles)]++;
            } catch (Throwable $failure) {
                report($failure);
                $this->components->error(sprintf('User [%s] could not be purged: %s', $user->getKey(), $failure->getMessage()));
                $tally['failed']++;
            }
        }

        // 3. One line a person reading the run can act on.
        $this->components->info(sprintf(
            'Purge finished: %d deleted, %d held, %d un-scheduled, %d failed.',
            $tally['deleted'],
            $tally['held'],
            $tally['unscheduled'],
            $tally['failed'],
        ));

        return $tally['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Decide one account and carry the decision out.
     *
     * @return 'deleted'|'held'|'unscheduled'
     */
    protected function purge(Model $user, DeletesUsers $deleter, UpdatesTeamMemberRoles $roles): string
    {
        if (! $user instanceof Authenticatable) {
            throw new RuntimeException(sprintf(
                'The configured user model [%s] must implement %s to be deleted.',
                $user::class,
                Authenticatable::class,
            ));
        }

        $orphan = $user->getAttribute('orphaned_at') !== null;
        $shared = DeleteUser::sharedOwnedTeams($user);

        // 1. The user can still resolve a shared team themselves, and deleting
        //    it would take its other members' data with it.
        if (! $orphan && $shared->isNotEmpty()) {
            $user->forceFill([
                'deletion_scheduled_at' => null,
            ])->save();

            $this->reportSkipped($user, 'un-scheduled: owns a team that gained a member during the grace period', $shared);

            return 'unscheduled';
        }

        // 2. Before any hand-on, so a held account is left exactly as it was.
        $billing = ScheduleUserDeletion::billingOwnedTeams($user);

        if ($billing->isNotEmpty()) {
            $this->reportSkipped($user, 'held: owns a team a subscription is still billing', $billing);

            return 'held';
        }

        // 3. Hand on and delete together, so a failed deletion does not leave an
        //    orphan's teams transferred to someone else.
        DB::transaction(function () use ($user, $shared, $deleter, $roles): void {
            foreach ($shared as $team) {
                $this->handOn($team, $user, $roles);
            }

            $deleter->delete($user);
        });

        return 'deleted';
    }

    /**
     * Make the earliest-joined admin, else the earliest-joined member, the
     * team's owner.
     *
     * Ownership is `teams.user_id` plus the `owner` role on the pivot, the pair
     * the package writes when it creates a team; the role goes through
     * {@see UpdatesTeamMemberRoles} so an application's own role rules see it. A
     * personal team stops being personal, since it is nobody's own any more.
     */
    protected function handOn(Model $team, Authenticatable&Model $owner, UpdatesTeamMemberRoles $roles): void
    {
        $membershipModel = MagicStarter::membershipModel();

        $memberships = $membershipModel::query()
            ->where('team_id', $team->getKey())
            ->where('user_id', '!=', $owner->getKey())
            ->orderBy('created_at')
            ->get();

        $heirship = $memberships->first(
            fn (Model $membership): bool => $membership->getAttribute('role') === Role::ADMIN->value,
        ) ?? $memberships->firstOrFail();

        $heir = $owner->newQuery()->findOrFail($heirship->getAttribute('user_id'));

        $team->forceFill([
            'user_id' => $heir->getKey(),
            'personal_team' => false,
        ])->save();

        $roles->update($owner, $team, $heir, Role::OWNER->value);
    }

    /**
     * Print and log an account the run did not delete, with the teams behind it.
     *
     * @param  Collection<int, Model>  $teams
     */
    protected function reportSkipped(Model $user, string $outcome, Collection $teams): void
    {
        $teamIds = $teams->map(fn (Model $team): string => (string) $team->getKey())->all();

        $this->components->warn(sprintf('User [%s] %s (teams: %s).', $user->getKey(), $outcome, implode(', ', $teamIds)));

        Log::warning('An account due for deletion was not purged.', [
            'user_id' => $user->getKey(),
            'outcome' => $outcome,
            'team_ids' => $teamIds,
        ]);
    }
}
