<?php

namespace FlutterSdk\MagicStarter\Console;

use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Contracts\TrashesTeams;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Support\SoftDeletingTeams;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Deletes for good the trashed teams whose restore window has closed: the only
 * place a team trashed through {@see TrashesTeams} is actually removed.
 *
 * A team is due when it is trashed and its `restore_until` is not in the
 * future, which is exactly when {@see TrashesTeams::restore()} starts refusing
 * it. Each one is read again under a lock before it is deleted, because a
 * restore may have landed between the walk and the purge, and deleting a team
 * its owner has just brought back would be unrecoverable. A team trashed by
 * other means has no `restore_until` and is never due.
 *
 * Deletion goes through {@see DeletesTeams}, so its billing guard and photo
 * cleanup run exactly as they do for any other team. A team the guard refuses
 * is HELD: logged and printed, left trashed, and tried again on the next run.
 * One team failing is reported and the run moves on, and the exit code is
 * non-zero when any failed.
 *
 * The package registers the command and does not schedule it; the consuming
 * application decides when it runs. Without a schedule line a trashed team is
 * never deleted.
 */
class PurgeTrashedTeamsCommand extends Command
{
    public const NAME = 'magic-starter:purge-trashed-teams';

    /**
     * @var string
     */
    protected $signature = self::NAME;

    /**
     * @var string
     */
    protected $description = 'Delete the trashed teams whose restore window has closed';

    /**
     * Purge every trashed team whose window has closed.
     */
    public function handle(DeletesTeams $deleter): int
    {
        $teamModel = MagicStarter::teamModel();

        /** @var Model $prototype */
        $prototype = new $teamModel;

        // 1. A team model that cannot be trashed, or a table without the
        //    window column, has nothing trashed on it.
        if (! SoftDeletingTeams::usedBy($prototype) || ! Schema::hasColumn($prototype->getTable(), 'restore_until')) {
            $this->components->info('The team model is not trashable or has no restore_until column; nothing to purge.');

            return self::SUCCESS;
        }

        $tally = [
            'deleted' => 0,
            'held' => 0,
            'restored' => 0,
            'failed' => 0,
        ];

        // 2. By key, so rows deleted mid-walk cannot shift a page.
        $due = $teamModel::onlyTrashed()
            ->where('restore_until', '<=', now())
            ->lazyById();

        foreach ($due as $team) {
            try {
                $tally[$this->purge($team, $deleter)]++;
            } catch (ValidationException $refusal) {
                $this->hold($team, $refusal);
                $tally['held']++;
            } catch (Throwable $failure) {
                report($failure);
                $this->components->error(sprintf(
                    'Team [%s] could not be purged: %s',
                    $team->getKey(),
                    $failure->getMessage(),
                ));
                $tally['failed']++;
            }
        }

        // 3. One line a person reading the run can act on.
        $this->components->info(sprintf(
            'Purge finished: %d deleted, %d held, %d restored, %d failed.',
            $tally['deleted'],
            $tally['held'],
            $tally['restored'],
            $tally['failed'],
        ));

        return $tally['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Delete one team, unless a restore got to it first.
     *
     * The team is read again under a lock and must still be trashed and due:
     * a restore that committed after the walk loaded it is honoured, and one
     * landing during the purge waits for it instead of racing the deletion.
     * A refusal from the contract rolls the transaction back and propagates.
     *
     * @param  Model  $loaded  the row as the walk loaded it, possibly stale
     * @return 'deleted'|'restored'
     *
     * @throws ValidationException When {@see DeletesTeams} refuses the team.
     */
    private function purge(Model $loaded, DeletesTeams $deleter): string
    {
        return DB::transaction(function () use ($loaded, $deleter): string {
            // 1. Only a row that is still trashed and still due.
            $teamModel = MagicStarter::teamModel();
            $team = $teamModel::onlyTrashed()
                ->whereKey($loaded->getKey())
                ->where('restore_until', '<=', now())
                ->lockForUpdate()
                ->first();

            if ($team === null) {
                return 'restored';
            }

            // 2. Through the contract, so the billing guard and the photo cleanup run.
            $deleter->delete($team);

            return 'deleted';
        });
    }

    /**
     * Print and log a team the billing guard refused to delete.
     *
     * Logged as well as printed, because the output of a scheduled run is usually discarded.
     */
    private function hold(Model $team, ValidationException $refusal): void
    {
        $reason = implode(' ', Arr::flatten($refusal->errors()));

        $this->components->warn(sprintf('Team [%s] held: %s', $team->getKey(), $reason));

        Log::warning('A trashed team past its restore window was not purged.', [
            'team_id' => $team->getKey(),
            'reason' => $reason,
        ]);
    }
}
