<?php

namespace FlutterSdk\MagicStarter\Tests\Support\AiRuns;

use FlutterSdk\MagicStarter\Contracts\RecordsAiRuns;
use FlutterSdk\MagicStarter\Enums\AiRunStatus;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\AiRun;
use FlutterSdk\MagicStarter\Models\AiRunAttempt;
use FlutterSdk\MagicStarter\Support\AiRuns\AiRunAttemptData;
use FlutterSdk\MagicStarter\Support\AiRuns\AiRunOutcome;
use FlutterSdk\MagicStarter\Support\AiRuns\AiRunRecorder;
use FlutterSdk\MagicStarter\Support\AiRuns\AiRunStart;
use FlutterSdk\MagicStarter\Support\AiRuns\AiUsage;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;

/**
 * Locks the recorder's promise to the host's AI call path: one run per call,
 * one attempt per provider leg, totals folded on finish, bodies masked and
 * capped at write time, and never a failure that reaches the caller.
 *
 * No `RefreshDatabase`, like BillingEventRecorderTest: each test gets a fresh
 * database, so a caller's `DB::transaction()` is the real outermost one and the
 * recorder's own transaction is a SAVEPOINT below it. SQLite does not poison a
 * transaction after a failed statement, so the dropped-table test proves the
 * savepoint only under `DB_CONNECTION=pgsql`; on SQLite it proves the catch.
 */
class AiRunRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The missing-table warning is once per PROCESS and per table.
        (new ReflectionProperty(AiRunRecorder::class, 'warnedMissingTables'))->setValue(null, []);

        config(['magic-starter.features' => [Features::aiRuns()]]);
    }

    public function test_the_contract_resolves_to_one_recorder_singleton(): void
    {
        $recorder = $this->app->make(RecordsAiRuns::class);

        $this->assertInstanceOf(AiRunRecorder::class, $recorder);
        $this->assertSame($recorder, $this->app->make(RecordsAiRuns::class));
    }

    public function test_with_the_feature_off_start_returns_null_without_a_query(): void
    {
        $this->migrateAiRuns();
        config(['magic-starter.features' => []]);
        DB::enableQueryLog();

        $run = $this->recorder()->start(new AiRunStart(feature: 'incident.analysis'));

        $this->assertNull($run);
        $this->assertSame([], DB::getQueryLog());
        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_a_null_run_key_makes_attempt_and_finish_no_ops(): void
    {
        $this->migrateAiRuns();
        DB::enableQueryLog();

        $this->recorder()->attempt(null, $this->failedOpenRouterLeg());
        $this->recorder()->finish(null, new AiRunOutcome(status: AiRunStatus::FAILED));

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_start_writes_a_running_row_and_returns_its_key(): void
    {
        $this->migrateAiRuns();
        Carbon::setTestNow('2026-10-17 10:00:00');

        $run = $this->recorder()->start(new AiRunStart(
            feature: 'incident.analysis',
            teamId: 42,
            subjectType: 'incident',
            subjectId: 7,
            promptHash: str_repeat('a', 64),
            model: 'claude-sonnet-4-5',
            instructions: 'You triage incidents.',
            input: 'Why is the API down?',
            context: ['monitor' => 'api.example.com'],
            tools: ['lookup_monitor'],
        ));

        $this->assertNotNull($run);

        $fresh = AiRun::query()->findOrFail($run);
        $this->assertSame(AiRunStatus::RUNNING, $fresh->status);
        $this->assertSame('incident.analysis', $fresh->feature);
        $this->assertSame('code', $fresh->source);
        $this->assertSame('42', $fresh->team_id);
        $this->assertSame('incident', $fresh->subject_type);
        $this->assertSame('7', $fresh->subject_id);
        $this->assertSame(str_repeat('a', 64), $fresh->prompt_hash);
        $this->assertSame('claude-sonnet-4-5', $fresh->model);
        $this->assertSame('You triage incidents.', $fresh->instructions);
        $this->assertSame('Why is the API down?', $fresh->input);
        $this->assertSame(['monitor' => 'api.example.com'], $fresh->context);
        $this->assertSame(['lookup_monitor'], $fresh->tools);
        $this->assertSame('2026-10-17 10:00:00', $fresh->started_at?->toDateTimeString());
        $this->assertNull($fresh->finished_at);
    }

    public function test_a_failed_then_succeeded_run_folds_its_attempts_on_finish(): void
    {
        $this->migrateAiRuns();
        $recorder = $this->recorder();

        $run = $recorder->start(new AiRunStart(feature: 'incident.analysis', model: 'openrouter/auto'));
        $recorder->attempt($run, $this->failedOpenRouterLeg());
        $recorder->attempt($run, $this->succeededAnthropicLeg());
        $recorder->finish($run, new AiRunOutcome(
            status: AiRunStatus::SUCCEEDED,
            output: 'The API is down because the database is full.',
            structured: ['severity' => 'high'],
            durationMs: 2400,
        ));

        $this->assertSame(1, AiRun::query()->count());

        $fresh = AiRun::query()->findOrFail($run);
        $this->assertSame(AiRunStatus::SUCCEEDED, $fresh->status);
        $this->assertSame(2, $fresh->attempts_count);
        $this->assertSame('anthropic', $fresh->provider);
        $this->assertSame('claude-sonnet-4-5', $fresh->model);
        $this->assertSame(1120, $fresh->input_tokens);
        $this->assertSame(250, $fresh->output_tokens);
        $this->assertSame(400, $fresh->cache_read_tokens);
        $this->assertSame(100, $fresh->cache_write_tokens);
        $this->assertNull($fresh->reasoning_tokens);
        $this->assertSame('0.00430000', $fresh->cost_usd);
        $this->assertSame('mixed', $fresh->cost_source);
        $this->assertSame(2400, $fresh->duration_ms);
        $this->assertSame('The API is down because the database is full.', $fresh->output);
        $this->assertSame(['severity' => 'high'], $fresh->structured);
        $this->assertNotNull($fresh->finished_at);

        $attempts = $fresh->attempts;
        $this->assertCount(2, $attempts);
        $this->assertSame([1, 2], $attempts->pluck('attempt')->all());
        $this->assertSame(['failed', 'succeeded'], $attempts->pluck('status')->all());
        $this->assertSame(429, $attempts[0]->http_status);
        $this->assertSame('openrouter', $attempts[0]->provider);
        $this->assertSame('0.00010000', $attempts[0]->cost_usd);
        $this->assertSame(1000, $attempts[1]->input_tokens);
        $this->assertSame('end_turn', $attempts[1]->finish_reason);
    }

    public function test_a_shared_cost_source_is_kept_and_unreported_totals_stay_null(): void
    {
        $this->migrateAiRuns();
        $recorder = $this->recorder();

        $run = $recorder->start(new AiRunStart(feature: 'incident.analysis'));
        $recorder->attempt($run, new AiRunAttemptData(
            attempt: 1,
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            status: AiRunStatus::FAILED,
            costUsd: '0.00000100',
            costSource: 'provider',
        ));
        $recorder->attempt($run, new AiRunAttemptData(
            attempt: 2,
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            status: AiRunStatus::FAILED,
            costUsd: '1.5',
            costSource: 'provider',
        ));
        $recorder->finish($run, new AiRunOutcome(status: AiRunStatus::FAILED, errorClass: 'RuntimeException'));

        $fresh = AiRun::query()->findOrFail($run);
        $this->assertSame(2, $fresh->attempts_count);
        $this->assertSame('provider', $fresh->cost_source);
        $this->assertSame('1.50000100', $fresh->cost_usd);
        $this->assertNull($fresh->input_tokens);
        $this->assertNull($fresh->output_tokens);
        // No succeeded leg: the provider stays unknown and the requested model stays.
        $this->assertNull($fresh->provider);
        $this->assertNull($fresh->model);
        $this->assertSame('RuntimeException', $fresh->error_class);
    }

    public function test_every_body_is_masked_before_it_is_written(): void
    {
        $this->migrateAiRuns();
        $recorder = $this->recorder();

        $run = $recorder->start(new AiRunStart(
            feature: 'incident.analysis',
            instructions: 'Call with Bearer abcdefgh12345678',
            input: 'Fetch https://user:hunter22@example.com/x',
            context: [
                'token' => 'plain-context-token',
                'note' => '?api_key=AbC123xyz',
            ],
        ));
        $recorder->attempt($run, new AiRunAttemptData(
            attempt: 1,
            provider: 'openrouter',
            model: null,
            status: AiRunStatus::FAILED,
            errorMessage: 'Rejected key sk-ant-api03-AAAAAAAAAAAAAAAAAAAAAA',
            toolCalls: [
                [
                    'name' => 'login',
                    'arguments' => ['user' => 'ops', 'password' => 'p4ssw0rd!'],
                    'result' => 'issued Bearer zyxwvuts98765432',
                ],
            ],
        ));
        $recorder->finish($run, new AiRunOutcome(
            status: AiRunStatus::FAILED,
            output: 'echo sk-ant-api03-BBBBBBBBBBBBBBBBBBBBBB',
            structured: ['authorization' => 'Basic dXNlcjpwYXNz'],
            errorMessage: 'upstream said Bearer abcdefgh12345678',
        ));

        $fresh = AiRun::query()->findOrFail($run);
        $attempt = AiRunAttempt::query()->sole();

        $this->assertSame('Call with Bearer [redacted]', $fresh->instructions);
        $this->assertSame('Fetch https://[redacted]@example.com/x', $fresh->input);
        $this->assertSame(['token' => '[redacted]', 'note' => '?api_key=[redacted]'], $fresh->context);
        $this->assertSame('echo [redacted]', $fresh->output);
        $this->assertSame(['authorization' => '[redacted]'], $fresh->structured);
        $this->assertSame('upstream said Bearer [redacted]', $fresh->error_message);
        $this->assertSame('Rejected key [redacted]', $attempt->error_message);
        $this->assertSame([
            [
                'name' => 'login',
                'arguments' => ['user' => 'ops', 'password' => '[redacted]'],
                'result' => 'issued Bearer [redacted]',
            ],
        ], $attempt->tool_calls);
    }

    public function test_a_body_over_the_byte_cap_is_cut_on_a_character_boundary_with_a_marker(): void
    {
        $this->migrateAiRuns();
        config(['magic-starter.ai_runs.max_body_bytes' => 65]);

        // 200 bytes of two-byte characters; byte 65 falls inside one.
        $run = $this->recorder()->start(new AiRunStart(
            feature: 'incident.analysis',
            input: str_repeat('é', 100),
            context: ['log' => str_repeat('é', 100)],
        ));

        $fresh = AiRun::query()->findOrFail($run);
        $expected = str_repeat('é', 32) . '[truncated 136 bytes]';

        $this->assertSame($expected, $fresh->input);
        $this->assertSame(['log' => $expected], $fresh->context);
        $this->assertTrue(mb_check_encoding((string) $fresh->input, 'UTF-8'));
    }

    public function test_the_writes_run_one_transaction_level_below_the_caller(): void
    {
        $this->migrateAiRuns();
        $levels = [];
        AiRun::creating(static function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });

        DB::transaction(function () use (&$levels): void {
            $levels[] = DB::transactionLevel();

            $this->recorder()->start(new AiRunStart(feature: 'incident.analysis'));
        });

        $this->assertSame([1, 2], $levels);
    }

    public function test_a_missing_attempts_table_skips_only_attempts_with_one_warning(): void
    {
        $this->migrate('create_ai_runs_table.php');
        Log::spy();
        $recorder = $this->recorder();

        $run = $recorder->start(new AiRunStart(feature: 'incident.analysis'));
        $recorder->attempt($run, $this->failedOpenRouterLeg());
        $recorder->attempt($run, $this->succeededAnthropicLeg());
        $recorder->finish($run, new AiRunOutcome(status: AiRunStatus::SUCCEEDED));

        $fresh = AiRun::query()->findOrFail($run);
        $this->assertSame(AiRunStatus::SUCCEEDED, $fresh->status);
        $this->assertSame(0, $fresh->attempts_count);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $context['table'] === 'ai_run_attempts');
        Log::shouldNotHaveReceived('error');
    }

    public function test_a_missing_runs_table_makes_start_return_null_with_one_warning(): void
    {
        Log::spy();
        $recorder = $this->recorder();

        $this->assertNull($recorder->start(new AiRunStart(feature: 'incident.analysis')));
        $this->assertNull($recorder->start(new AiRunStart(feature: 'incident.analysis')));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $context['table'] === 'ai_runs');
    }

    public function test_an_attempts_table_dropped_after_it_was_seen_is_logged_and_the_caller_still_commits(): void
    {
        $this->migrateAiRuns();
        Schema::create('caller_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $recorder = $this->recorder();
        $run = $recorder->start(new AiRunStart(feature: 'incident.analysis'));
        $recorder->attempt($run, $this->failedOpenRouterLeg());

        Schema::drop('ai_run_attempts');
        Log::spy();

        DB::transaction(function () use ($recorder, $run): void {
            $recorder->attempt($run, $this->succeededAnthropicLeg());

            // On PostgreSQL this insert only works because the failure stayed in its savepoint.
            DB::table('caller_rows')->insert(['name' => 'triage']);
        });

        $this->assertSame(1, DB::table('caller_rows')->count());
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $context['table'] === 'ai_run_attempts'
                && $context['run'] === $run);
    }

    private function failedOpenRouterLeg(): AiRunAttemptData
    {
        return new AiRunAttemptData(
            attempt: 1,
            provider: 'openrouter',
            model: 'anthropic/claude-sonnet-4.5',
            status: AiRunStatus::FAILED,
            httpStatus: 429,
            errorClass: 'RateLimitedException',
            errorMessage: 'Too many requests',
            usage: new AiUsage(inputTokens: 120, outputTokens: 0),
            costUsd: '0.0001',
            costSource: 'provider',
            durationMs: 300,
            startedAt: Carbon::now(),
        );
    }

    private function succeededAnthropicLeg(): AiRunAttemptData
    {
        return new AiRunAttemptData(
            attempt: 2,
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            status: AiRunStatus::SUCCEEDED,
            httpStatus: 200,
            finishReason: 'end_turn',
            usage: new AiUsage(
                inputTokens: 1000,
                outputTokens: 250,
                cacheReadTokens: 400,
                cacheWriteTokens: 100,
            ),
            costUsd: '0.0042',
            costSource: 'estimated',
            durationMs: 2000,
            startedAt: Carbon::now(),
        );
    }

    private function recorder(): RecordsAiRuns
    {
        return $this->app->make(RecordsAiRuns::class);
    }

    private function migrateAiRuns(): void
    {
        $this->migrate('create_ai_runs_table.php');
        $this->migrate('create_ai_run_attempts_table.php');
    }

    private function migrate(string $file): void
    {
        (require __DIR__ . '/../../../database/migrations/' . $file)->up();
    }
}
