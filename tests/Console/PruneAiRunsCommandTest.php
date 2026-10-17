<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use FlutterSdk\MagicStarter\Console\PruneAiRunsCommand;
use FlutterSdk\MagicStarter\Enums\AiRunStatus;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\AiRun;
use FlutterSdk\MagicStarter\Models\AiRunAttempt;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * `magic-starter:ai-runs:prune` blanks the bodies of runs and attempts past the
 * retention, closes runs that never finished, never deletes a row, and is only
 * registered and scheduled while the ai-runs feature is on.
 */
#[DefineEnvironment('withAiRunsEnabled')]
final class PruneAiRunsCommandTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function withAiRunsEnabled($app): void
    {
        $app['config']->set('magic-starter.features', [
            Features::aiRuns(),
        ]);
    }

    /**
     * @param  Application  $app
     */
    protected function withAiRunsDisabled($app): void
    {
        $app['config']->set('magic-starter.features', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('magic-starter.use_uuids', false);

        (require __DIR__ . '/../../database/migrations/create_ai_runs_table.php')->up();
        (require __DIR__ . '/../../database/migrations/create_ai_run_attempts_table.php')->up();
    }

    public function test_it_blanks_the_bodies_of_an_old_run_and_its_attempts_and_keeps_the_metadata(): void
    {
        $old = $this->seedRun(daysOld: 31);
        $this->seedAttempt($old, daysOld: 31);

        $this->artisan(PruneAiRunsCommand::NAME)->assertSuccessful();

        $run = AiRun::query()->findOrFail($old->getKey());

        $this->assertNull($run->instructions);
        $this->assertNull($run->input);
        $this->assertNull($run->context);
        $this->assertNull($run->tools);
        $this->assertNull($run->output);
        $this->assertNull($run->structured);
        $this->assertNull($run->error_message);
        $this->assertNotNull($run->bodies_pruned_at);

        $this->assertSame('incident.analysis', $run->feature);
        $this->assertSame(AiRunStatus::FAILED, $run->status);
        $this->assertSame(120, $run->input_tokens);
        $this->assertSame(45, $run->output_tokens);
        $this->assertSame('0.01234500', $run->cost_usd);
        $this->assertSame('RuntimeException', $run->error_class);

        $attempt = AiRunAttempt::query()->firstOrFail();

        $this->assertNull($attempt->tool_calls);
        $this->assertNull($attempt->error_message);
        $this->assertNotNull($attempt->bodies_pruned_at);
        $this->assertSame('openrouter', $attempt->provider);
        $this->assertSame(120, $attempt->input_tokens);
        $this->assertSame('0.01234500', $attempt->cost_usd);
    }

    public function test_it_never_deletes_a_row(): void
    {
        $old = $this->seedRun(daysOld: 90);
        $this->seedAttempt($old, daysOld: 90);

        $this->artisan(PruneAiRunsCommand::NAME)->assertSuccessful();

        $this->assertSame(1, AiRun::query()->count());
        $this->assertSame(1, AiRunAttempt::query()->count());
    }

    public function test_it_leaves_a_run_inside_the_retention_untouched(): void
    {
        $recent = $this->seedRun(daysOld: 29);
        $this->seedAttempt($recent, daysOld: 29);

        $this->artisan(PruneAiRunsCommand::NAME)->assertSuccessful();

        $run = AiRun::query()->findOrFail($recent->getKey());

        $this->assertSame('system prompt', $run->instructions);
        $this->assertSame('user input', $run->input);
        $this->assertSame(['locale' => 'en'], $run->context);
        $this->assertSame('model answer', $run->output);
        $this->assertNull($run->bodies_pruned_at);

        $attempt = AiRunAttempt::query()->firstOrFail();

        $this->assertSame([['name' => 'lookup']], $attempt->tool_calls);
        $this->assertNull($attempt->bodies_pruned_at);
    }

    public function test_it_does_not_stamp_a_pruned_run_a_second_time(): void
    {
        $stamp = now()->subDays(3)->startOfSecond();
        $this->seedRun(daysOld: 60, attributes: ['bodies_pruned_at' => $stamp]);

        $this->artisan(PruneAiRunsCommand::NAME)->assertSuccessful();

        $this->assertTrue(AiRun::query()->firstOrFail()->bodies_pruned_at?->equalTo($stamp));
    }

    public function test_it_honours_the_days_option_and_never_goes_below_one_day(): void
    {
        $this->seedRun(daysOld: 6);
        $this->seedRun(daysOld: 4);

        $this->artisan(PruneAiRunsCommand::NAME, ['--days' => 5])->assertSuccessful();

        $this->assertSame(1, AiRun::query()->whereNotNull('bodies_pruned_at')->count());

        $this->artisan(PruneAiRunsCommand::NAME, ['--days' => 0])->assertSuccessful();

        $this->assertSame(2, AiRun::query()->whereNotNull('bodies_pruned_at')->count());

        $fresh = $this->seedRun(daysOld: 0);

        $this->artisan(PruneAiRunsCommand::NAME, ['--days' => 0])->assertSuccessful();

        $this->assertNull(AiRun::query()->findOrFail($fresh->getKey())->bodies_pruned_at);
    }

    public function test_it_prunes_every_old_run_across_chunks(): void
    {
        foreach (range(1, PruneAiRunsCommand::CHUNK_SIZE + 1) as $ignored) {
            $this->seedRun(daysOld: 60);
        }

        $this->artisan(PruneAiRunsCommand::NAME)->assertSuccessful();

        $this->assertSame(PruneAiRunsCommand::CHUNK_SIZE + 1, AiRun::query()->count());
        $this->assertSame(
            PruneAiRunsCommand::CHUNK_SIZE + 1,
            AiRun::query()->whereNotNull('bodies_pruned_at')->count(),
        );
    }

    public function test_it_abandons_a_run_that_stayed_running_past_the_threshold(): void
    {
        $stale = $this->seedRun(attributes: [
            'status' => AiRunStatus::RUNNING,
            'started_at' => now()->subMinutes(45),
        ]);
        $live = $this->seedRun(attributes: [
            'status' => AiRunStatus::RUNNING,
            'started_at' => now()->subMinutes(10),
        ]);

        $this->artisan(PruneAiRunsCommand::NAME)
            ->expectsOutputToContain('Abandoned 1 running AI run')
            ->assertSuccessful();

        $stale = AiRun::query()->findOrFail($stale->getKey());

        $this->assertSame(AiRunStatus::ABANDONED, $stale->status);
        $this->assertNotNull($stale->finished_at);

        $live = AiRun::query()->findOrFail($live->getKey());

        $this->assertSame(AiRunStatus::RUNNING, $live->status);
        $this->assertNull($live->finished_at);
    }

    public function test_it_honours_a_configured_abandon_threshold(): void
    {
        config()->set('magic-starter.ai_runs.abandon_after_minutes', 5);
        $run = $this->seedRun(attributes: [
            'status' => AiRunStatus::RUNNING,
            'started_at' => now()->subMinutes(10),
        ]);

        $this->artisan(PruneAiRunsCommand::NAME)->assertSuccessful();

        $this->assertSame(AiRunStatus::ABANDONED, AiRun::query()->findOrFail($run->getKey())->status);
    }

    public function test_it_skips_a_table_that_does_not_exist(): void
    {
        Schema::drop('ai_run_attempts');
        Schema::drop('ai_runs');

        $this->artisan(PruneAiRunsCommand::NAME)
            ->expectsOutputToContain('ai_runs does not exist')
            ->assertSuccessful();
    }

    public function test_it_is_scheduled_daily_without_overlapping_while_the_feature_is_on(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString(PruneAiRunsCommand::NAME, Artisan::output());

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, PruneAiRunsCommand::NAME));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    #[DefineEnvironment('withAiRunsDisabled')]
    public function test_it_is_neither_scheduled_nor_registered_while_the_feature_is_off(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringNotContainsString(PruneAiRunsCommand::NAME, Artisan::output());
        $this->assertArrayNotHasKey(PruneAiRunsCommand::NAME, Artisan::all());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedRun(int $daysOld = 0, array $attributes = []): AiRun
    {
        $run = new AiRun;
        $run->forceFill([
            'feature' => 'incident.analysis',
            'source' => 'code',
            'status' => AiRunStatus::FAILED,
            'provider' => 'openrouter',
            'model' => 'deepseek-chat',
            'attempts_count' => 1,
            'input_tokens' => 120,
            'output_tokens' => 45,
            'cost_usd' => '0.012345',
            'cost_source' => 'computed',
            'duration_ms' => 800,
            'error_class' => 'RuntimeException',
            'instructions' => 'system prompt',
            'input' => 'user input',
            'context' => ['locale' => 'en'],
            'tools' => [['name' => 'lookup']],
            'output' => 'model answer',
            'structured' => ['ok' => true],
            'error_message' => 'boom',
            'created_at' => now()->subDays($daysOld),
            ...$attributes,
        ])->save();

        return $run;
    }

    private function seedAttempt(AiRun $run, int $daysOld): AiRunAttempt
    {
        $attempt = new AiRunAttempt;
        $attempt->forceFill([
            'ai_run_id' => $run->getKey(),
            'attempt' => 1,
            'provider' => 'openrouter',
            'model' => 'deepseek-chat',
            'status' => 'failed',
            'input_tokens' => 120,
            'output_tokens' => 45,
            'cost_usd' => '0.012345',
            'tool_calls' => [['name' => 'lookup']],
            'error_message' => 'boom',
            'created_at' => now()->subDays($daysOld),
        ])->save();

        return $attempt;
    }
}
