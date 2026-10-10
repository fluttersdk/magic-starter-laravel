<?php

namespace FlutterSdk\MagicStarter\Console;

use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Models\ProcessedWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Deletes the billing records that age out: the webhook dedup claims, and the
 * billing history when the adopter asked for a retention on it.
 *
 * Age is the only criterion, as in {@see PruneAuditsCommand}, and the delete
 * runs in chunks of {@see self::CHUNK_SIZE} keys for the same reason.
 *
 * WEBHOOK CLAIMS NEVER GO BELOW 31 DAYS. A claim is the only thing that turns a
 * re-delivered event into a no-op, and Stripe's CLI can resend an event up to 30
 * days old, so a claim pruned inside that window would let the resent event run
 * its side effects a second time. `webhook_retention_days` is clamped up to
 * {@see self::MINIMUM_WEBHOOK_RETENTION_DAYS} rather than trusted.
 *
 * THE BILLING HISTORY IS PRUNED ONLY ON REQUEST. `events_retention_days` is
 * null by default because the table is financial history; a null, blank or
 * non-numeric value leaves it alone. Both deletes go through the query builder: `billing_events` refuses
 * a model `deleting` event, and a query-builder delete fires none, which is how
 * this command is the one place a row is ever removed.
 *
 * Scheduled daily by the provider while billing is on. A table the adopter has
 * not migrated yet is skipped with a line rather than failing the sweep.
 */
class PruneBillingRecordsCommand extends Command
{
    public const NAME = 'magic-starter:billing:prune';

    public const CHUNK_SIZE = 1000;

    public const MINIMUM_WEBHOOK_RETENTION_DAYS = 31;

    /**
     * @var string
     */
    protected $signature = self::NAME;

    /**
     * @var string
     */
    protected $description = 'Delete the webhook claims, and optionally the billing events, older than the retention';

    /**
     * Prune each table past its retention and print how many rows went.
     */
    public function handle(): int
    {
        // 1. Never below the resend window, so a misconfigured value cannot
        //    reopen a delivery that was already handled.
        $webhookDays = max(
            self::MINIMUM_WEBHOOK_RETENTION_DAYS,
            (int) config('magic-starter.billing.webhook_retention_days', 90),
        );

        $this->prune(new ProcessedWebhookEvent, 'processed_at', $webhookDays, 'webhook claim', 'webhook claims');

        // 2. The history is kept unless the adopter set an age for it. The env
        //    value arrives as a string: a blank one (`...RETENTION_DAYS=`) is ''
        //    rather than null, and cast to int it would prune all but a day, so
        //    only a number prunes. At least one day keeps a zero from wiping it.
        $eventsRetention = config('magic-starter.billing.events_retention_days');

        if ($eventsRetention === null) {
            return self::SUCCESS;
        }

        if (! is_numeric($eventsRetention)) {
            $this->components->info('Kept every billing event: events_retention_days is not a number of days.');

            return self::SUCCESS;
        }

        $this->prune(new BillingEvent, 'created_at', max(1, (int) $eventsRetention), 'billing event', 'billing events');

        return self::SUCCESS;
    }

    /**
     * Delete the rows of one table older than `$days` and report the count.
     */
    private function prune(Model $model, string $column, int $days, string $singular, string $plural): void
    {
        $table = $model->getTable();

        if (! $model->getConnection()->getSchemaBuilder()->hasTable($table)) {
            $this->components->info("Skipped {$singular} pruning: {$table} does not exist.");

            return;
        }

        $deleted = $this->deleteOlderThan($model, $column, now()->subDays($days));

        $this->components->info(sprintf(
            'Pruned %d %s older than %d days.',
            $deleted,
            $deleted === 1 ? $singular : $plural,
            $days,
        ));
    }

    /**
     * Keys first, then delete by key: a delete with a limit is not portable across drivers.
     */
    private function deleteOlderThan(Model $model, string $column, Carbon $cutoff): int
    {
        $deleted = 0;

        do {
            $keys = $model->newQuery()
                ->where($column, '<', $cutoff)
                ->limit(self::CHUNK_SIZE)
                ->pluck($model->getKeyName());

            if ($keys->isNotEmpty()) {
                $deleted += $model->newQuery()->whereKey($keys->all())->delete();
            }
        } while ($keys->count() === self::CHUNK_SIZE);

        return $deleted;
    }
}
