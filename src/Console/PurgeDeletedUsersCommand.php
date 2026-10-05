<?php

namespace FlutterSdk\MagicStarter\Console;

use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Contracts\CancelsUserDeletion;
use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\Contracts\TransfersTeamOwnership;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeamMemberRoles;
use FlutterSdk\MagicStarter\Enums\Role;
use FlutterSdk\MagicStarter\Jobs\PurgeUserNow;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\OwnedTeams;
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
 * - an account whose schedule was cleared since the walk loaded it (a sign-in)
 *   is left alone, read again under a row lock;
 * - a user who asked, and whose team has since gained a member, is un-scheduled
 *   and reported, since deleting them would take that member's team with them;
 * - an account owning a team a store or Cashier is still billing, or billed
 *   itself under user billing, is HELD and reported, since deleting it strands
 *   the charge and this package never cancels a subscription for anyone;
 * - an orphan's shared teams are handed on, to the earliest-joined admin and
 *   else the earliest-joined member, who becomes owner;
 * - the rest are deleted through {@see DeletesUsers}.
 *
 * The package registers the command and does not schedule it; the consuming
 * application decides when it runs. Held and un-scheduled accounts are logged
 * as well as printed, because a scheduled run's output is usually discarded.
 *
 * The per-account decision is {@see self::purge()}, which {@see PurgeUserNow}
 * shares with a cutoff of now for an immediate deletion.
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

    public function __construct(
        protected TransfersTeamOwnership $ownership,
        protected CancelsUserDeletion $cancellations,
    ) {
        parent::__construct();
    }

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

        $graceDays = max(0, (int) config('magic-starter.account_deletion.grace_days', 30));
        $cutoff = now()->subDays($graceDays);

        $tally = [
            'deleted' => 0,
            'held' => 0,
            'unscheduled' => 0,
            'cancelled' => 0,
            'failed' => 0,
        ];

        // 2. By key, so rows deleted or un-scheduled mid-walk cannot shift a page.
        $due = $prototype->newQuery()
            ->whereNotNull('deletion_scheduled_at')
            ->where('deletion_scheduled_at', '<=', $cutoff)
            ->lazyById();

        foreach ($due as $user) {
            try {
                $tally[$this->purge($user, $cutoff, $deleter, $roles)]++;
            } catch (Throwable $failure) {
                report($failure);
                $this->components->error(sprintf(
                    'User [%s] could not be purged: %s',
                    $user->getKey(),
                    $failure->getMessage(),
                ));
                $tally['failed']++;
            }
        }

        // 3. One line a person reading the run can act on.
        $this->components->info(sprintf(
            'Purge finished: %d deleted, %d held, %d un-scheduled, %d cancelled, %d failed.',
            $tally['deleted'],
            $tally['held'],
            $tally['unscheduled'],
            $tally['cancelled'],
            $tally['failed'],
        ));

        return $tally['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Decide one account and carry the decision out.
     *
     * The caller hands over a row loaded before any of this ran, so the
     * decision is made on the row read again under a lock: a sign-in that
     * cleared the schedule in between is honoured, and one landing after waits
     * for this transaction rather than racing the deletion.
     *
     * Public for {@see PurgeUserNow}, which runs it outside a console run; a
     * skipped account is then logged and not printed.
     *
     * @param  Model  $loaded  the row as the caller loaded it, possibly stale
     * @param  CarbonInterface  $cutoff  the latest schedule date still due: the grace
     *                                   cutoff for the walk, now for an immediate deletion
     * @return 'deleted'|'held'|'unscheduled'|'cancelled'
     *
     * @throws RuntimeException When the user model is not Authenticatable.
     */
    public function purge(
        Model $loaded,
        CarbonInterface $cutoff,
        DeletesUsers $deleter,
        UpdatesTeamMemberRoles $roles,
    ): string {
        if (! $loaded instanceof Authenticatable) {
            throw new RuntimeException(sprintf(
                'The configured user model [%s] must implement %s to be deleted.',
                $loaded::class,
                Authenticatable::class,
            ));
        }

        return DB::transaction(function () use ($loaded, $cutoff, $deleter): string {
            // 1. Only a row that is still due; a cleared schedule means the user
            //    took the request back after the walk loaded them.
            $user = $loaded->newQuery()
                ->whereKey($loaded->getKey())
                ->whereNotNull('deletion_scheduled_at')
                ->where('deletion_scheduled_at', '<=', $cutoff)
                ->lockForUpdate()
                ->first();

            if (! $user instanceof Authenticatable) {
                return 'cancelled';
            }

            $orphan = $user->getAttribute('orphaned_at') !== null;
            $shared = OwnedTeams::shared($user);

            // 2. The user can still resolve a shared team themselves, and deleting
            //    it would take its other members' data with it.
            if (! $orphan && $shared->isNotEmpty()) {
                $this->cancellations->cancel($user);

                $this->reportSkipped(
                    $user,
                    'un-scheduled: owns a team that gained a member during the grace period',
                    $shared,
                );

                return 'unscheduled';
            }

            // 3. Before any hand-on, so a held account is left exactly as it was.
            //    An orphan is held too: nobody may delete a paying subscriber.
            $billing = OwnedTeams::billing($user);

            if ($billing->isNotEmpty()) {
                $this->reportSkipped($user, 'held: owns a team a subscription is still billing', $billing);

                return 'held';
            }

            if (OwnedTeams::isBilling($user)) {
                $this->reportSkipped($user, 'held: a subscription is still billing the account', new Collection);

                return 'held';
            }

            // 4. Hand on and delete together, so a failed deletion does not leave
            //    an orphan's teams transferred to someone else.
            foreach ($shared as $team) {
                $this->handOn($team, $user);
            }

            $deleter->delete($user);

            return 'deleted';
        });
    }

    /**
     * Make the earliest-joined admin, else the earliest-joined member, the
     * team's owner; the membership key breaks a tie on join time, so the heir
     * never depends on the database's scan order.
     *
     * The transfer goes through {@see TransfersTeamOwnership}, which moves
     * `teams.user_id` and the pivot roles together; {@see UpdatesTeamMemberRoles}
     * refuses to hand out the `owner` role.
     */
    protected function handOn(Model $team, Authenticatable&Model $owner): void
    {
        $membershipModel = MagicStarter::membershipModel();

        $memberships = $membershipModel::query()
            ->where('team_id', $team->getKey())
            ->where('user_id', '!=', $owner->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $heirship = $memberships->first(
            fn (Model $membership): bool => $membership->getAttribute('role') === Role::ADMIN->value,
        ) ?? $memberships->firstOrFail();

        $heir = $owner->newQuery()->findOrFail($heirship->getAttribute('user_id'));

        $this->ownership->transfer($owner, $team, $heir);
    }

    /**
     * Print and log an account the run did not delete, with the teams behind it.
     *
     * @param  Collection<int, Model>  $teams  empty when the account itself is the reason
     */
    protected function reportSkipped(Model $user, string $outcome, Collection $teams): void
    {
        $teamIds = $teams->map(fn (Model $team): string => (string) $team->getKey())->all();

        // Absent when the job runs the decision: there is no console to print to.
        if ($this->components !== null) {
            $this->components->warn(sprintf(
                'User [%s] %s%s.',
                $user->getKey(),
                $outcome,
                $teamIds === [] ? '' : ' (teams: ' . implode(', ', $teamIds) . ')',
            ));
        }

        Log::warning('An account due for deletion was not purged.', [
            'user_id' => $user->getKey(),
            'outcome' => $outcome,
            'team_ids' => $teamIds,
        ]);
    }
}
