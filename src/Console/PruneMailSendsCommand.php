<?php

namespace FlutterSdk\MagicStarter\Console;

use FlutterSdk\MagicStarter\Models\MailSend;
use Illuminate\Console\Command;

/**
 * Deletes the mail send records older than `magic-starter.mail.onesignal.retention_days`.
 *
 * Age is the only criterion. OneSignal keeps the message data of an API send for
 * 30 days, so a record older than that points at counters nobody can read, which
 * is why 30 is the default. The delete runs in chunks of {@see self::CHUNK_SIZE}
 * keys, so a table that has grown for months is not removed by one statement that
 * holds a lock for as long as it takes. A table that does not exist is skipped,
 * since the command is scheduled whether or not the host published the migration.
 *
 * Registered and scheduled daily by the provider.
 */
class PruneMailSendsCommand extends Command
{
    public const NAME = 'magic-starter:mail:prune-sends';

    public const CHUNK_SIZE = 1000;

    public const DEFAULT_RETENTION_DAYS = 30;

    /**
     * @var string
     */
    protected $signature = self::NAME;

    /**
     * @var string
     */
    protected $description = 'Delete the mail send records older than the configured retention';

    /**
     * Delete every record past the retention and print how many went.
     */
    public function handle(): int
    {
        $model = new MailSend;

        // 1. The command is scheduled for every host, published migration or not.
        if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            $this->components->info("Skipped pruning: {$model->getTable()} does not exist.");

            return self::SUCCESS;
        }

        // 2. At least one day, so an unset or zero value cannot wipe every record.
        $retentionDays = max(1, (int) config(
            'magic-starter.mail.onesignal.retention_days',
            self::DEFAULT_RETENTION_DAYS,
        ));
        $cutoff = now()->subDays($retentionDays);

        // 3. Keys first, then delete by key: a delete with a limit is not portable across drivers.
        $deleted = 0;

        do {
            $keys = MailSend::query()
                ->where('created_at', '<', $cutoff)
                ->limit(self::CHUNK_SIZE)
                ->pluck($model->getKeyName());

            if ($keys->isNotEmpty()) {
                $deleted += MailSend::query()->whereKey($keys->all())->delete();
            }
        } while ($keys->count() === self::CHUNK_SIZE);

        $this->components->info(sprintf(
            'Pruned %d mail send %s older than %d days.',
            $deleted,
            $deleted === 1 ? 'row' : 'rows',
            $retentionDays,
        ));

        return self::SUCCESS;
    }
}
