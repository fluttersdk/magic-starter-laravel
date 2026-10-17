<?php

namespace FlutterSdk\MagicStarter\Support\AiRuns;

use Closure;
use FlutterSdk\MagicStarter\Contracts\RecordsAiRuns;
use FlutterSdk\MagicStarter\Enums\AiRunStatus;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\AiRun;
use FlutterSdk\MagicStarter\Models\AiRunAttempt;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The default {@see RecordsAiRuns}: writes `ai_runs` and `ai_run_attempts`
 * synchronously, with the same failure contract as the billing recorder.
 *
 * - Every write builds its model and runs in its own `DB::transaction()`, a
 *   SAVEPOINT whenever the host already opened one. On PostgreSQL a failed
 *   statement aborts the whole transaction and turns its COMMIT into a
 *   ROLLBACK, so a failed log row would otherwise take the host's own writes
 *   with it. A {@see QueryException}, a {@see JsonEncodingException} and a
 *   {@see DeadlockException} are caught and logged at error level; anything
 *   else is a bug and propagates.
 * - A missing table (an application that upgraded without migrating) skips the
 *   write with one warning per process and per table, so a missing attempts
 *   table still leaves the runs. Only a table that EXISTS is remembered: a
 *   worker started before the migration starts writing once it has run.
 * - Every value is made storable first: invalid UTF-8 is scrubbed and NUL bytes
 *   (which PostgreSQL refuses) are stripped from every string, a NAN or INF
 *   becomes null, an object becomes the array its JSON form decodes to, and
 *   each metadata string is cut to its column's width.
 * - Bodies are cut to `max_body_bytes` plus {@see self::MASK_LOOKAHEAD_BYTES}
 *   so masking a huge body stays cheap, masked by {@see SecretMasker}, and then
 *   capped at `magic-starter.ai_runs.max_body_bytes` on a character boundary
 *   with a `[truncated N bytes]` marker. Masking comes before the cap, and the
 *   lookahead keeps a secret that crosses the cap whole while it is masked. In
 *   an array body (context, structured, tool calls) this applies to each string.
 *
 * Bound as a singleton, so each table check costs one query per worker.
 */
class AiRunRecorder implements RecordsAiRuns
{
    /**
     * The cap when the published config leaves `max_body_bytes` out.
     */
    public const DEFAULT_MAX_BODY_BYTES = 262144;

    /**
     * Text kept past the cap while masking, so a secret that starts before the
     * cap is still whole when the patterns read it.
     */
    private const MASK_LOOKAHEAD_BYTES = 65536;

    private const TRUNCATED = '[truncated %d bytes]';

    /**
     * The pre-cut's marker sits on a line of its own, so a secret cut mid-value
     * (an unclosed quote, an unquoted value) ends before it and the masker
     * neither swallows the count nor stops short of the secret.
     */
    private const PRECUT_TRUNCATED = "\n" . self::TRUNCATED;

    private const TRUNCATED_TAIL = '/\n?\[truncated (\d+) bytes\]\z/';

    /**
     * Column widths, in characters, of the metadata strings a host hands over.
     *
     * @var array<string, int>
     */
    private const WIDTHS = [
        'team_id' => 255,
        'subject_type' => 255,
        'subject_id' => 255,
        'feature' => 100,
        'prompt_hash' => 64,
        'provider' => 64,
        'model' => 128,
        'finish_reason' => 64,
        'error_class' => 255,
    ];

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

        $attributes = [
            'team_id' => $this->meta('team_id', $start->teamId),
            'subject_type' => $this->meta('subject_type', $start->subjectType),
            'subject_id' => $this->meta('subject_id', $start->subjectId),
            'feature' => (string) $this->meta('feature', $start->feature),
            'source' => $start->source,
            'prompt_hash' => $this->meta('prompt_hash', $start->promptHash),
            'status' => AiRunStatus::RUNNING,
            'model' => $this->meta('model', $start->model),
            'instructions' => $this->body($masker, $start->instructions),
            'input' => $this->body($masker, $start->input),
            'context' => $this->body($masker, $start->context),
            'tools' => $this->storable($start->tools, PHP_INT_MAX),
            'started_at' => now(),
        ];

        $run = new AiRun;

        $written = $this->write(self::RUNS, static fn (): bool => $run->fill($attributes)->save(), [
            'feature' => $attributes['feature'],
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

        $attributes = [
            'ai_run_id' => $run,
            'attempt' => $attempt->attempt,
            'provider' => (string) $this->meta('provider', $attempt->provider),
            'model' => $this->meta('model', $attempt->model),
            'status' => $attempt->status,
            'http_status' => $attempt->httpStatus,
            'finish_reason' => $this->meta('finish_reason', $attempt->finishReason),
            'error_class' => $this->meta('error_class', $attempt->errorClass),
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
        ];

        $this->write(self::ATTEMPTS, static fn (): bool => (new AiRunAttempt)->fill($attributes)->save(), [
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
            'error_class' => $this->meta('error_class', $outcome->errorClass),
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
                ...AiRun::foldAttempts($attempts),
            ])->save();
        }, [
            'run' => $run,
        ]);
    }

    /**
     * @param  list<array{name: string, arguments: mixed, result: mixed}>  $calls
     * @return list<array{name: string, arguments: mixed, result: mixed}>
     */
    private function toolCalls(SecretMasker $masker, array $calls): array
    {
        return array_map(fn (array $call): array => [
            'name' => $this->scrub($call['name']),
            'arguments' => $this->body($masker, $call['arguments']),
            'result' => $this->body($masker, $call['result']),
        ], $calls);
    }

    /**
     * A metadata value as its column can hold it, or null.
     */
    private function meta(string $column, int|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($this->scrub((string) $value), 0, self::WIDTHS[$column], 'UTF-8');
    }

    /**
     * Make a body storable, mask it, then cap each string in it.
     */
    private function body(SecretMasker $masker, mixed $body): mixed
    {
        $max = $this->maxBodyBytes();

        $masked = $masker->mask($this->storable($body, $max + self::MASK_LOOKAHEAD_BYTES));

        if (is_string($masked)) {
            return $this->cap($masked, $max);
        }

        if (is_array($masked)) {
            array_walk_recursive($masked, function (mixed &$value) use ($max): void {
                if (is_string($value)) {
                    $value = $this->cap($value, $max);
                }
            });
        }

        return $masked;
    }

    /**
     * A value the JSON casts and every driver accept, each string at most `$bytes` long.
     *
     * An object goes through its JSON form, so a `JsonSerializable` keeps the
     * shape it chose; a value even that cannot encode becomes null.
     */
    private function storable(mixed $value, int $bytes): mixed
    {
        if (is_string($value)) {
            return $this->scrub($this->precut($value, $bytes));
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        if (is_object($value)) {
            $json = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

            $value = $json === false ? null : json_decode($json, true);
        }

        if (! is_array($value)) {
            return $value;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            $clean[is_string($key) ? $this->scrub($key) : $key] = $this->storable($item, $bytes);
        }

        return $clean;
    }

    /**
     * Valid UTF-8 with no NUL byte, which PostgreSQL refuses in text and JSON.
     */
    private function scrub(string $text): string
    {
        return str_replace("\0", '', mb_scrub($text, 'UTF-8'));
    }

    /**
     * Cut a string too long to mask cheaply, saying how much went.
     */
    private function precut(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }

        $kept = mb_strcut($text, 0, $bytes, 'UTF-8');

        return $kept . sprintf(self::PRECUT_TRUNCATED, strlen($text) - strlen($kept));
    }

    /**
     * Cut a masked string to the cap on a character boundary. A string the
     * pre-cut already shortened carries its marker at the end, and that count
     * is added to this one, so the marker always says how much of the original
     * is missing.
     */
    private function cap(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }

        $carried = 0;

        if (preg_match(self::TRUNCATED_TAIL, $text, $tail) === 1) {
            $carried = (int) $tail[1];
            $text = substr($text, 0, -strlen($tail[0]));
        }

        $kept = mb_strcut($text, 0, $max, 'UTF-8');

        return $kept . sprintf(self::TRUNCATED, strlen($text) - strlen($kept) + $carried);
    }

    /**
     * Read with a code default, so a published `ai_runs` block that leaves the
     * key out does not cut every body down to a marker.
     */
    private function maxBodyBytes(): int
    {
        return (int) config('magic-starter.ai_runs.max_body_bytes', self::DEFAULT_MAX_BODY_BYTES);
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
        } catch (QueryException|JsonEncodingException|DeadlockException $exception) {
            Log::error('An AI run row could not be written; the call continues.', [
                ...$context,
                'table' => $table,
                'exception' => $this->rootMessage($exception),
            ]);

            return false;
        }

        return true;
    }

    /**
     * The innermost message: the PDO one rather than a {@see QueryException}'s,
     * which repeats the SQL with every bound body.
     */
    private function rootMessage(Throwable $exception): string
    {
        while ($exception->getPrevious() !== null) {
            $exception = $exception->getPrevious();
        }

        return $exception->getMessage();
    }

    private function tableExists(string $table): bool
    {
        if (isset($this->seenTables[$table])) {
            return true;
        }

        // The first check on a fresh worker is a query too: a dead connection answers "not
        // recorded", never an exception into the host's call. Nothing is remembered, so the
        // next call asks again.
        try {
            $exists = Schema::hasTable($table);
        } catch (QueryException $exception) {
            Log::error('The AI run tables could not be checked; the call continues.', [
                'table' => $table,
                'exception' => $this->rootMessage($exception),
            ]);

            return false;
        }

        if ($exists) {
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
