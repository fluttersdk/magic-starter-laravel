<?php

namespace FlutterSdk\MagicStarter\Console;

use FlutterSdk\MagicStarter\Enums\AiRunStatus;
use FlutterSdk\MagicStarter\Models\AiRun;
use FlutterSdk\MagicStarter\Models\AiRunAttempt;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 * has lost its caller and becomes `abandoned`, with its provider, model, usage
 * and cost folded from its attempts exactly as `finish()` would have, so its
 * spend still counts in every total.
 *
 * The body pass writes in chunks of {@see self::CHUNK_SIZE} keys, so a log that
 * has grown for months is not rewritten by one statement that holds a table
 * lock for as long as it takes. The abandon pass reads the stale runs in chunks
 * of the same size and closes each with one UPDATE that still requires
 * `running`, so a run its caller finished meanwhile keeps its own outcome. A
 * table that does not exist is skipped, since the command can run before the
 * feature's migrations did.
 *
 * Both thresholds are read with code defaults, so a published `ai_runs` block
 * that leaves one out still prunes at 30 days and abandons after 30 minutes.
 *
 * Registered and scheduled hourly by the provider only while the ai-runs feature is on.
 */
class PruneAiRunsCommand extends Command
{
    public const NAME = 'magic-starter:ai-runs:prune';

    public const CHUNK_SIZE = 1000;

    public const DEFAULT_RETENTION_DAYS = 30;

    public const DEFAULT_ABANDON_AFTER_MINUTES = 30;

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
        $days = max(1, (int) ($this->option('days')
            ?? config('magic-starter.ai_runs.retention_days', self::DEFAULT_RETENTION_DAYS)));
        $now = now();
        $cutoff = $now->copy()->subDays($days);

        $runs = new AiRun;
        $attempts = new AiRunAttempt;

        // 2. The attempts table is created after the runs table, so each is checked on its own.
        $hasRuns = $this->tableExists($runs);
        $hasAttempts = $this->tableExists($attempts);

        if ($hasRuns) {
            $this->blankBodies($runs, self::RUN_BODIES, $cutoff, $now, 'AI run', $days);
        }

        if ($hasAttempts) {
            $this->blankBodies($attempts, self::ATTEMPT_BODIES, $cutoff, $now, 'AI run attempt', $days);
        }

        // 3. A run whose caller died stays `running` forever unless it is closed here.
        if ($hasRuns) {
            $this->abandonStaleRuns($now, $hasAttempts);
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
     * Close the runs still `running` that started over the configured minutes
     * ago, folding each one's attempts the way `finish()` does.
     *
     * @param  bool  $hasAttempts  Whether the attempts table exists to fold from.
     */
    private function abandonStaleRuns(Carbon $now, bool $hasAttempts): void
    {
        // At least one minute, so an unset or zero value cannot close a run mid-call.
        $minutes = max(1, (int) config(
            'magic-starter.ai_runs.abandon_after_minutes',
            self::DEFAULT_ABANDON_AFTER_MINUTES,
        ));
        $abandoned = 0;

        AiRun::query()
            ->where('status', AiRunStatus::RUNNING->value)
            ->where('started_at', '<', $now->copy()->subMinutes($minutes))
            ->when($hasAttempts, static fn (Builder $query): Builder => $query->with('attempts'))
            ->chunkById(
                self::CHUNK_SIZE,
                static function (Collection $runs) use ($now, $hasAttempts, &$abandoned): void {
                    foreach ($runs as $run) {
                        $abandoned += AiRun::query()
                            ->whereKey($run->getKey())
                            ->where('status', AiRunStatus::RUNNING->value)
                            ->update([
                                ...AiRun::foldAttempts($hasAttempts ? $run->attempts : new Collection),
                                'status' => AiRunStatus::ABANDONED->value,
                                'finished_at' => $now,
                            ]);
                    }
                },
            );

        $this->components->info(sprintf(
            'Abandoned %d running %s that started over %d minutes ago.',
            $abandoned,
            Str::plural('AI run', $abandoned),
            $minutes,
        ));
    }
}
