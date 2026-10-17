<?php

namespace FlutterSdk\MagicStarter\Tests\Models;

use FlutterSdk\MagicStarter\Enums\AiRunStatus;
use FlutterSdk\MagicStarter\Models\AiRun;
use FlutterSdk\MagicStarter\Models\AiRunAttempt;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Locks `ai_runs` and `ai_run_attempts`: both tables build under either key
 * kind, a run survives the team and the subject it names, its attempts follow
 * it into deletion, and the models read the columns back as typed values.
 */
class AiRunTest extends TestCase
{
    /**
     * @return iterable<string, array{0: bool}>
     */
    public static function keyModes(): iterable
    {
        yield 'uuid keys' => [true];
        yield 'integer keys' => [false];
    }

    #[DataProvider('keyModes')]
    public function test_it_creates_both_tables_with_their_columns_and_indexes(bool $useUuids): void
    {
        $this->prepare($useUuids);

        $this->assertTrue(Schema::hasColumns('ai_runs', [
            'id',
            'team_id',
            'subject_type',
            'subject_id',
            'feature',
            'source',
            'prompt_hash',
            'status',
            'source_run_id',
            'provider',
            'model',
            'attempts_count',
            'input_tokens',
            'output_tokens',
            'cache_read_tokens',
            'cache_write_tokens',
            'reasoning_tokens',
            'cost_usd',
            'cost_source',
            'duration_ms',
            'error_class',
            'instructions',
            'input',
            'context',
            'tools',
            'output',
            'structured',
            'error_message',
            'bodies_pruned_at',
            'started_at',
            'finished_at',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('ai_run_attempts', [
            'id',
            'ai_run_id',
            'attempt',
            'provider',
            'model',
            'status',
            'http_status',
            'finish_reason',
            'error_class',
            'input_tokens',
            'output_tokens',
            'cache_read_tokens',
            'cache_write_tokens',
            'reasoning_tokens',
            'cost_usd',
            'cost_source',
            'duration_ms',
            'tool_calls',
            'error_message',
            'bodies_pruned_at',
            'started_at',
            'finished_at',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasIndex('ai_runs', ['created_at']));
        $this->assertTrue(Schema::hasIndex('ai_runs', ['team_id', 'created_at']));
        $this->assertTrue(Schema::hasIndex('ai_runs', ['feature', 'created_at']));
        $this->assertTrue(Schema::hasIndex('ai_runs', ['status', 'started_at']));
        $this->assertTrue(Schema::hasIndex('ai_runs', ['prompt_hash']));
        $this->assertTrue(Schema::hasIndex('ai_runs', ['subject_type', 'subject_id']));
        $this->assertTrue(Schema::hasIndex('ai_run_attempts', ['ai_run_id', 'attempt'], 'unique'));
    }

    public function test_running_the_migrations_twice_is_a_no_op(): void
    {
        $this->prepare(true);

        $this->migrate('create_ai_runs_table.php');
        $this->migrate('create_ai_run_attempts_table.php');

        $this->assertTrue(Schema::hasTable('ai_runs'));
        $this->assertTrue(Schema::hasTable('ai_run_attempts'));
    }

    #[DataProvider('keyModes')]
    public function test_a_run_with_no_subject_and_no_team_saves_with_its_defaults(bool $useUuids): void
    {
        $this->prepare($useUuids);

        $run = AiRun::query()->create([
            'feature' => 'incident.analysis',
            'status' => AiRunStatus::RUNNING,
        ]);

        $fresh = AiRun::query()->findOrFail($run->getKey());

        $this->assertSame($useUuids, is_string($fresh->getKey()));
        $this->assertSame(AiRunStatus::RUNNING, $fresh->status);
        $this->assertSame('code', $fresh->source);
        $this->assertSame(0, $fresh->attempts_count);
        $this->assertNull($fresh->team_id);
        $this->assertNull($fresh->subject_type);
        $this->assertNull($fresh->subject_id);
        $this->assertNull($fresh->team);
        $this->assertNull($fresh->subject);
        $this->assertNull($fresh->sourceRun);
        $this->assertNull($fresh->cost_usd);
        $this->assertNull($fresh->structured);
        $this->assertCount(0, $fresh->attempts);
    }

    #[DataProvider('keyModes')]
    public function test_a_row_reads_back_with_its_casts(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $team = $this->makeTeam();
        $user = $this->makeUser();

        $run = AiRun::query()->create([
            'team_id' => $team->getKey(),
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->getKey(),
            'feature' => 'incident.analysis',
            'status' => AiRunStatus::SUCCEEDED,
            'attempts_count' => 2,
            'input_tokens' => 1200,
            'output_tokens' => 340,
            'cost_usd' => '0.01234567',
            'cost_source' => 'computed',
            'context' => ['monitor' => 7],
            'tools' => [['name' => 'lookup']],
            'structured' => ['verdict' => 'down'],
            'started_at' => '2026-10-17 10:00:00',
            'finished_at' => '2026-10-17 10:00:03',
        ]);

        $fresh = AiRun::query()->findOrFail($run->getKey());

        $this->assertSame(AiRunStatus::SUCCEEDED, $fresh->status);
        $this->assertSame(2, $fresh->attempts_count);
        $this->assertSame(1200, $fresh->input_tokens);
        $this->assertSame('0.01234567', $fresh->cost_usd);
        $this->assertSame(['monitor' => 7], $fresh->context);
        $this->assertSame([['name' => 'lookup']], $fresh->tools);
        $this->assertSame(['verdict' => 'down'], $fresh->structured);
        $this->assertInstanceOf(Carbon::class, $fresh->started_at);
        $this->assertInstanceOf(Carbon::class, $fresh->finished_at);
        $this->assertInstanceOf(Carbon::class, $fresh->created_at);
        $this->assertSame((string) $team->getKey(), $fresh->team_id);
        $this->assertTrue($fresh->team->is($team));
        $this->assertInstanceOf(MorphTo::class, $fresh->subject());
        $this->assertTrue($fresh->subject->is($user));
    }

    #[DataProvider('keyModes')]
    public function test_attempts_come_back_ordered_by_attempt(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $run = $this->makeRun();

        foreach ([3, 1, 2] as $number) {
            $run->attempts()->create([
                'attempt' => $number,
                'provider' => 'openrouter',
                'status' => 'failed',
            ]);
        }

        $this->assertSame(
            [1, 2, 3],
            $run->fresh()->attempts->map(static fn (AiRunAttempt $attempt): int => $attempt->attempt)->all(),
        );
    }

    #[DataProvider('keyModes')]
    public function test_an_attempt_reads_back_with_its_casts(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $run = $this->makeRun();

        $attempt = $run->attempts()->create([
            'attempt' => 1,
            'provider' => 'openrouter',
            'model' => 'deepseek-chat',
            'status' => 'succeeded',
            'http_status' => 200,
            'cost_usd' => '0.00100000',
            'tool_calls' => [['name' => 'lookup']],
            'started_at' => '2026-10-17 10:00:00',
        ]);

        $fresh = AiRunAttempt::query()->findOrFail($attempt->getKey());

        $this->assertSame(1, $fresh->attempt);
        $this->assertSame(200, $fresh->http_status);
        $this->assertSame('0.00100000', $fresh->cost_usd);
        $this->assertSame([['name' => 'lookup']], $fresh->tool_calls);
        $this->assertInstanceOf(Carbon::class, $fresh->started_at);
    }

    #[DataProvider('keyModes')]
    public function test_an_attempt_number_is_unique_within_its_run(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $run = $this->makeRun();
        $attributes = ['attempt' => 1, 'provider' => 'openrouter', 'status' => 'failed'];

        $run->attempts()->create($attributes);

        $this->expectException(QueryException::class);

        $run->attempts()->create($attributes);
    }

    #[DataProvider('keyModes')]
    public function test_a_deleted_team_leaves_the_run_and_the_team_reads_null(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $team = $this->makeTeam();
        $run = $this->makeRun(['team_id' => $team->getKey()]);

        $team->delete();

        $fresh = AiRun::query()->findOrFail($run->getKey());

        $this->assertSame((string) $team->getKey(), $fresh->team_id);
        $this->assertNull($fresh->team);
    }

    #[DataProvider('keyModes')]
    public function test_deleting_a_run_cascades_its_attempts(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $run = $this->makeRun();
        $run->attempts()->create(['attempt' => 1, 'provider' => 'openrouter', 'status' => 'failed']);
        $run->attempts()->create(['attempt' => 2, 'provider' => 'openrouter', 'status' => 'succeeded']);

        $run->delete();

        $this->assertSame(0, AiRunAttempt::query()->count());
    }

    #[DataProvider('keyModes')]
    public function test_a_deleted_source_run_nulls_the_pointer_and_keeps_the_override(bool $useUuids): void
    {
        $this->prepare($useUuids);
        $source = $this->makeRun();
        $override = $this->makeRun(['source' => 'override', 'source_run_id' => $source->getKey()]);

        $this->assertTrue($override->fresh()->sourceRun->is($source));

        $source->delete();

        $fresh = AiRun::query()->findOrFail($override->getKey());
        $this->assertNull($fresh->source_run_id);
        $this->assertNull($fresh->sourceRun);
    }

    public function test_the_status_enum_carries_the_documented_wire_values(): void
    {
        $this->assertSame(
            ['running', 'succeeded', 'failed', 'cache_hit', 'abandoned'],
            array_map(static fn (AiRunStatus $status): string => $status->value, AiRunStatus::cases()),
        );
    }

    public function test_the_config_block_ships_its_defaults(): void
    {
        $this->assertSame(30, config('magic-starter.ai_runs.retention_days'));
        $this->assertSame(30, config('magic-starter.ai_runs.abandon_after_minutes'));
        $this->assertSame(262144, config('magic-starter.ai_runs.max_body_bytes'));
        $this->assertNotEmpty(config('magic-starter.ai_runs.mask.patterns'));
        $this->assertSame([], config('magic-starter.ai_runs.mask.extra_patterns'));
        $this->assertContains('api_key', config('magic-starter.ai_runs.mask.keys'));
    }

    /**
     * Pick the key mode, then build the tables the models read and write.
     */
    private function prepare(bool $useUuids): void
    {
        config()->set('magic-starter.use_uuids', $useUuids);

        // SQLite ignores foreign keys unless asked, and the cascade and the
        // nulling are the behaviour under test. PostgreSQL always enforces them.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        $this->migrate('create_users_table.php');
        $this->migrate('create_teams_table.php');
        $this->migrate('create_ai_runs_table.php');
        $this->migrate('create_ai_run_attempts_table.php');
    }

    private function makeUser(): ConcreteUser
    {
        return ConcreteUser::query()->create([
            'name' => 'Run Person',
            'email' => 'run-' . uniqid() . '@example.com',
            'password' => 'secret',
        ]);
    }

    private function makeTeam(): ConcreteTeam
    {
        return ConcreteTeam::query()->create([
            'user_id' => $this->makeUser()->getKey(),
            'name' => 'Run Team',
            'personal_team' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeRun(array $overrides = []): AiRun
    {
        return AiRun::query()->create(array_merge([
            'feature' => 'incident.analysis',
            'status' => AiRunStatus::RUNNING,
        ], $overrides));
    }

    private function migrate(string $file): void
    {
        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../database/migrations/' . $file,
            '--realpath' => true,
        ]);
    }
}
