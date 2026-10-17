<?php

namespace FlutterSdk\MagicStarter\Support\AiRuns;

use Closure;
use FlutterSdk\MagicStarter\Contracts\RecordsAiRuns;
use FlutterSdk\MagicStarter\Enums\AiRunStatus;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\AiRun;
use FlutterSdk\MagicStarter\Models\AiRunAttempt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The default {@see RecordsAiRuns}: writes `ai_runs` and `ai_run_attempts`
 * synchronously, with the same failure contract as the billing recorder.
 *
 * - Every write runs in its own `DB::transaction()`, a SAVEPOINT whenever the
 *   host already opened one. On PostgreSQL a failed statement aborts the whole
 *   transaction and turns its COMMIT into a ROLLBACK, so a failed log row would
 *   otherwise take the host's own writes with it. Only a {@see QueryException}
 *   is caught, and it is logged at error level; anything else is a bug and
 *   propagates.
 * - A missing table (an application that upgraded without migrating) skips the
 *   write with one warning per process and per table, so a missing attempts
 *   table still leaves the runs. Only a table that EXISTS is remembered: a
 *   worker started before the migration starts writing once it has run.
 * - Bodies are masked by {@see SecretMasker} and then capped at
 *   `magic-starter.ai_runs.max_body_bytes` on a character boundary, with a
 *   `[truncated N bytes]` marker. Masking comes first, so a cut never leaves a
 *   shortened secret the patterns no longer recognise. In an array body
 *   (context, structured, tool calls) the cap applies to each string in it.
 *
 * Bound as a singleton, so each table check costs one query per worker.
 */
class AiRunRecorder implements RecordsAiRuns
{
    private const RUNS = 'ai_runs';

    private const ATTEMPTS = 'ai_run_attempts';

    /**
     * Tables whose missing-table warning this process already wrote.
     *
     * @var array<string, true>
     */
    private static array $warnedMissingTables = [];

    /**
     * Tables seen to exist; never forgotten, see the class docblock.
     *
     * @var array<string, true>
     */
    private array $seenTables = [];

    public function start(AiRunStart $start): int|string|null
    {
        if (! Features::hasAiRunFeatures()) {
            return null;
        }

        $masker = SecretMasker::fromConfig();

        $run = new AiRun([
            'team_id' => $start->teamId === null ? null : (string) $start->teamId,
            'subject_type' => $start->subjectType,
            'subject_id' => $start->subjectId === null ? null : (string) $start->subjectId,
            'feature' => $start->feature,
            'source' => $start->source,
            'prompt_hash' => $start->promptHash,
            'status' => AiRunStatus::RUNNING,
            'model' => $start->model,
            'instructions' => $this->body($masker, $start->instructions),
            'input' => $this->body($masker, $start->input),
            'context' => $this->body($masker, $start->context),
            'tools' => $start->tools,
            'started_at' => now(),
        ]);

        $written = $this->write(self::RUNS, static fn (): bool => $run->save(), [
            'feature' => $start->feature,
        ]);

        if (! $written) {
            return null;
        }

        /** @var int|string $key */
        $key = $run->getKey();

        return $key;
    }

    public function attempt(int|string|null $run, AiRunAttemptData $attempt): void
    {
        if ($run === null) {
            return;
        }

        $masker = SecretMasker::fromConfig();

        $row = new AiRunAttempt([
            'ai_run_id' => $run,
            'attempt' => $attempt->attempt,
            'provider' => $attempt->provider,
            'model' => $attempt->model,
            'status' => $attempt->status->value,
            'http_status' => $attempt->httpStatus,
            'finish_reason' => $attempt->finishReason,
            'error_class' => $attempt->errorClass,
            'input_tokens' => $attempt->usage?->inputTokens,
            'output_tokens' => $attempt->usage?->outputTokens,
            'cache_read_tokens' => $attempt->usage?->cacheReadTokens,
            'cache_write_tokens' => $attempt->usage?->cacheWriteTokens,
            'reasoning_tokens' => $attempt->usage?->reasoningTokens,
            'cost_usd' => $attempt->costUsd,
            'cost_source' => $attempt->costSource,
            'duration_ms' => $attempt->durationMs,
            'tool_calls' => $this->toolCalls($masker, $attempt->toolCalls),
            'error_message' => $this->body($masker, $attempt->errorMessage),
            'started_at' => $attempt->startedAt,
            'finished_at' => now(),
        ]);

        $this->write(self::ATTEMPTS, static fn (): bool => $row->save(), [
            'run' => $run,
            'attempt' => $attempt->attempt,
        ]);
    }

    public function finish(int|string|null $run, AiRunOutcome $outcome): void
    {
        if ($run === null) {
            return;
        }

        $masker = SecretMasker::fromConfig();

        $closing = [
            'status' => $outcome->status,
            'duration_ms' => $outcome->durationMs,
            'error_class' => $outcome->errorClass,
            'output' => $this->body($masker, $outcome->output),
            'structured' => $this->body($masker, $outcome->structured),
            'error_message' => $this->body($masker, $outcome->errorMessage),
            'finished_at' => now(),
        ];

        $attemptsReadable = $this->tableExists(self::ATTEMPTS);

        $this->write(self::RUNS, function () use ($run, $closing, $attemptsReadable): void {
            $row = AiRun::query()->find($run);

            if ($row === null) {
                return;
            }

            $attempts = $attemptsReadable ? $row->attempts()->get() : new Collection;

            $row->fill([
                ...$closing,
                ...$this->totals($attempts),
            ])->save();
        }, [
            'run' => $run,
        ]);
    }

    /**
     * Provider, model, usage and cost of a run, folded from its attempts.
     *
     * Provider and model come from the last leg that succeeded and are left
     * alone when none did. A total stays null when no attempt reported it.
     *
     * @param  Collection<int, AiRunAttempt>  $attempts  First attempt first.
     * @return array<string, mixed>
     */
    private function totals(Collection $attempts): array
    {
        $totals = [
            'attempts_count' => $attempts->count(),
            'input_tokens' => $this->sum($attempts, 'input_tokens'),
            'output_tokens' => $this->sum($attempts, 'output_tokens'),
            'cache_read_tokens' => $this->sum($attempts, 'cache_read_tokens'),
            'cache_write_tokens' => $this->sum($attempts, 'cache_write_tokens'),
            'reasoning_tokens' => $this->sum($attempts, 'reasoning_tokens'),
            'cost_usd' => $this->cost($attempts),
            'cost_source' => $this->costSource($attempts),
        ];

        $succeeded = $attempts->last(
            static fn (AiRunAttempt $attempt): bool => $attempt->status === AiRunStatus::SUCCEEDED->value,
        );

        if ($succeeded !== null) {
            $totals['provider'] = $succeeded->provider;
            $totals['model'] = $succeeded->model;
        }

        return $totals;
    }

    /**
     * @param  Collection<int, AiRunAttempt>  $attempts
     */
    private function sum(Collection $attempts, string $column): ?int
    {
        $reported = $attempts->pluck($column)->filter(static fn (mixed $value): bool => $value !== null);

        return $reported->isEmpty() ? null : (int) $reported->sum();
    }

    /**
     * The summed cost as a decimal string, added in whole 1e-8 dollar units so
     * no float ever rounds it. The `decimal:8` cast reads every value back with
     * exactly eight places.
     *
     * @param  Collection<int, AiRunAttempt>  $attempts
     */
    private function cost(Collection $attempts): ?string
    {
        $reported = $attempts->pluck('cost_usd')->filter(static fn (mixed $value): bool => $value !== null);

        if ($reported->isEmpty()) {
            return null;
        }

        $units = $reported->sum(static fn (string $usd): int => (int) str_replace('.', '', $usd));

        return intdiv($units, 100_000_000) . '.' . str_pad((string) ($units % 100_000_000), 8, '0', STR_PAD_LEFT);
    }

    /**
     * The one source every costed attempt shares, or `mixed` when they differ.
     *
     * @param  Collection<int, AiRunAttempt>  $attempts
     */
    private function costSource(Collection $attempts): ?string
    {
        $sources = $attempts->pluck('cost_source')
            ->filter(static fn (mixed $value): bool => $value !== null)
            ->unique()
            ->values();

        return match ($sources->count()) {
            0 => null,
            1 => (string) $sources->first(),
            default => 'mixed',
        };
    }

    /**
     * @param  list<array{name: string, arguments: mixed, result: mixed}>  $calls
     * @return list<array{name: string, arguments: mixed, result: mixed}>
     */
    private function toolCalls(SecretMasker $masker, array $calls): array
    {
        return array_map(fn (array $call): array => [
            'name' => $call['name'],
            'arguments' => $this->body($masker, $call['arguments']),
            'result' => $this->body($masker, $call['result']),
        ], $calls);
    }

    /**
     * Mask a body, then cap each string in it.
     *
     * @template TBody
     *
     * @param  TBody  $body
     * @return TBody
     */
    private function body(SecretMasker $masker, mixed $body): mixed
    {
        $masked = $masker->mask($body);

        if (is_string($masked)) {
            return $this->cap($masked);
        }

        if (is_array($masked)) {
            array_walk_recursive($masked, function (mixed &$value): void {
                if (is_string($value)) {
                    $value = $this->cap($value);
                }
            });
        }

        return $masked;
    }

    private function cap(string $text): string
    {
        $max = (int) config('magic-starter.ai_runs.max_body_bytes');
        $bytes = strlen($text);

        if ($bytes <= $max) {
            return $text;
        }

        $kept = mb_strcut($text, 0, $max, 'UTF-8');
        $dropped = $bytes - strlen($kept);

        return $kept . "[truncated {$dropped} bytes]";
    }

    /**
     * Run one write inside its own savepoint, never past it.
     *
     * @param  Closure(): mixed  $write
     * @param  array<string, mixed>  $context  What identifies the row in the error line.
     * @return bool Whether the write ran to completion.
     */
    private function write(string $table, Closure $write, array $context): bool
    {
        if (! $this->tableExists($table)) {
            return false;
        }

        try {
            DB::transaction($write);
        } catch (QueryException $exception) {
            // The PDO message, not the QueryException's: that one repeats the SQL with every bound body.
            Log::error('An AI run row could not be written; the call continues.', [
                ...$context,
                'table' => $table,
                'exception' => ($exception->getPrevious() ?? $exception)->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    private function tableExists(string $table): bool
    {
        if (isset($this->seenTables[$table])) {
            return true;
        }

        if (Schema::hasTable($table)) {
            $this->seenTables[$table] = true;

            return true;
        }

        if (! isset(self::$warnedMissingTables[$table])) {
            self::$warnedMissingTables[$table] = true;

            Log::warning("The {$table} table is missing; AI runs are not fully recorded.", [
                'reason' => 'ai_runs_table_missing',
                'table' => $table,
            ]);
        }

        return false;
    }
}
