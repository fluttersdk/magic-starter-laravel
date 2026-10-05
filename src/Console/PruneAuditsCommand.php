<?php

namespace FlutterSdk\MagicStarter\Console;

use FlutterSdk\MagicStarter\Audit\Audit;
use Illuminate\Console\Command;

/**
 * Deletes the audit rows older than `magic-starter.audit.retention_days`.
 *
 * Age is the only criterion: a row is never kept or dropped for what it says.
 * The delete runs in chunks of {@see self::CHUNK_SIZE} keys, so a trail that
 * has grown for years is not removed by one statement that holds a table lock
 * for as long as it takes.
 *
 * Registered and scheduled daily by the provider only while the audit feature
 * is on, because the table exists only once that feature's migration did.
 */
class PruneAuditsCommand extends Command
{
    public const NAME = 'magic-starter:audit:prune';

    public const CHUNK_SIZE = 1000;

    /**
     * @var string
     */
    protected $signature = self::NAME;

    /**
     * @var string
     */
    protected $description = 'Delete the audit rows older than the configured retention';

    /**
     * Delete every row past the retention and print how many went.
     */
    public function handle(): int
    {
        // 1. At least one day, so an unset or zero value cannot wipe the whole trail.
        $retentionDays = max(1, (int) config('magic-starter.audit.retention_days', 365));
        $cutoff = now()->subDays($retentionDays);

        // 2. Keys first, then delete by key: a delete with a limit is not portable across drivers.
        $deleted = 0;

        do {
            $keys = Audit::query()
                ->where('created_at', '<', $cutoff)
                ->limit(self::CHUNK_SIZE)
                ->pluck((new Audit)->getKeyName());

            if ($keys->isNotEmpty()) {
                $deleted += Audit::query()->whereKey($keys->all())->delete();
            }
        } while ($keys->count() === self::CHUNK_SIZE);

        $this->components->info(sprintf(
            'Pruned %d audit %s older than %d days.',
            $deleted,
            $deleted === 1 ? 'row' : 'rows',
            $retentionDays,
        ));

        return self::SUCCESS;
    }
}
