<?php

namespace FlutterSdk\MagicStarter\Console;

use FlutterSdk\MagicStarter\Enums\AiRunStatus;
use FlutterSdk\MagicStarter\Models\AiRun;
use FlutterSdk\MagicStarter\Models\AiRunAttempt;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Blanks the bodies of the AI runs and attempts older than
 * `magic-starter.ai_runs.retention_days` and closes the runs that never finished.
 *
 * A row is never deleted and its metadata (feature, status, usage, cost,
 * durations, timestamps) is never touched: the pass only nulls the body columns
 * and stamps `bodies_pruned_at`, so spend and failure history outlive the
 * prompts. A run still `running` after `magic-starter.ai_runs.abandon_after_minutes`
 * has lost its caller and becomes `abandoned`.
 *
 * Both passes write in chunks of {@see self::CHUNK_SIZE} keys, so a log that
 * has grown for months is not rewritten by one statement that holds a table
 * lock for as long as it takes. A table that does not exist is skipped, since
 * the command can run before the feature's migrations did.
 *
 * Registered and scheduled daily by the provider only while the ai-runs feature is on.
 */
class PruneAiRunsCommand extends Command
{
    public const NAME = 'magic-starter:ai-runs:prune';

    public const CHUNK_SIZE = 1000;

    private const RUN_BODIES = [
        'instructions',
        'input',
        'context',
        'tools',
        'output',
        'structured',
        'error_message',
    ];

    private const ATTEMPT_BODIES = [
        'tool_calls',
        'error_message',
    ];

    /**
     * @var string
     */
    protected $signature = self::NAME . ' {--days= : Days to keep bodies, at least 1, default is the retention}';

    /**
     * @var string
     */
    protected $description = 'Blank the bodies of old AI runs and close the runs that never finished';

    /**
     * Blank the old bodies, close the abandoned runs and print how many of each.
     */
    public function handle(): int
    {
        // 1. At least one day, so an unset or zero value cannot blank every body at once.
        $days = max(1, (int) ($this->option('days') ?? config('magic-starter.ai_runs.retention_days')));
        $now = now();
        $cutoff = $now->copy()->subDays($days);

        $runs = new AiRun;
        $attempts = new AiRunAttempt;

        // 2. The attempts table is created after the runs table, so each is checked on its own.
        $hasRuns = $this->tableExists($runs);

        if ($hasRuns) {
            $this->blankBodies($runs, self::RUN_BODIES, $cutoff, $now, 'AI run', $days);
        }

        if ($this->tableExists($attempts)) {
            $this->blankBodies($attempts, self::ATTEMPT_BODIES, $cutoff, $now, 'AI run attempt', $days);
        }

        // 3. A run whose caller died stays `running` forever unless it is closed here.
        if ($hasRuns) {
            $this->abandonStaleRuns($now);
        }

        return self::SUCCESS;
    }

    private function tableExists(Model $model): bool
    {
        $table = $model->getTable();

        if ($model->getConnection()->getSchemaBuilder()->hasTable($table)) {
            return true;
        }

        $this->components->info("Skipped pruning: {$table} does not exist.");

        return false;
    }

    /**
     * Null `$columns` on every row of `$model` created before `$cutoff` that is not blanked yet.
     *
     * Keys first, then update by key: an update with a limit is not portable across drivers.
     * Through the base query builder, so `updated_at` stays what the recorder wrote.
     *
     * @param  list<string>  $columns
     */
    private function blankBodies(
        Model $model,
        array $columns,
        Carbon $cutoff,
        Carbon $now,
        string $label,
        int $days,
    ): void {
        $blank = array_fill_keys($columns, null) + ['bodies_pruned_at' => $now];
        $blanked = 0;

        do {
            $keys = $model->newQuery()
                ->where('created_at', '<', $cutoff)
                ->whereNull('bodies_pruned_at')
                ->limit(self::CHUNK_SIZE)
                ->pluck($model->getKeyName());

            if ($keys->isNotEmpty()) {
                $blanked += $model->newQuery()->toBase()->whereIn($model->getKeyName(), $keys->all())->update($blank);
            }
        } while ($keys->count() === self::CHUNK_SIZE);

        $this->components->info(sprintf(
            'Blanked the bodies of %d %s older than %d days.',
            $blanked,
            Str::plural($label, $blanked),
            $days,
        ));
    }

    /**
     * Close the runs still `running` that started over the configured minutes ago.
     */
    private function abandonStaleRuns(Carbon $now): void
    {
        $minutes = (int) config('magic-starter.ai_runs.abandon_after_minutes');

        $abandoned = AiRun::query()
            ->where('status', AiRunStatus::RUNNING->value)
            ->where('started_at', '<', $now->copy()->subMinutes($minutes))
            ->update([
                'status' => AiRunStatus::ABANDONED->value,
                'finished_at' => $now,
            ]);

        $this->components->info(sprintf(
            'Abandoned %d running %s that started over %d minutes ago.',
            $abandoned,
            Str::plural('AI run', $abandoned),
            $minutes,
        ));
    }
}
