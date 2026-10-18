<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Filament\Panel;
use FlutterSdk\MagicStarter\Enums\AiRunStatus;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Resources\AiRuns\AiRunResource;
use FlutterSdk\MagicStarter\Filament\Resources\AiRuns\Pages\ListAiRuns;
use FlutterSdk\MagicStarter\Filament\Resources\AiRuns\Pages\ViewAiRun;
use FlutterSdk\MagicStarter\Filament\Widgets\AiRunStatsWidget;
use FlutterSdk\MagicStarter\Models\AiRun;
use FlutterSdk\MagicStarter\Models\AiRunAttempt;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use ReflectionMethod;

/**
 * The read-only AI run log and its stats widget.
 *
 * The ai-runs and teams features are on while the application boots, because a
 * resource's routes are registered at that moment; the "feature off" case
 * builds its own panel the way PluginTest does.
 */
class AiRunResourceTest extends FilamentTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::aiRuns(),
            Features::teams(),
        ]);
    }

    public function test_the_resource_and_the_widget_are_mounted_only_with_the_ai_runs_feature(): void
    {
        config()->set('magic-starter.features', []);
        $off = Panel::make()->id('ai-runs-off')->plugin(MagicStarterPlugin::make());

        config()->set('magic-starter.features', [Features::aiRuns()]);
        $on = Panel::make()->id('ai-runs-on')->plugin(MagicStarterPlugin::make());
        $dropped = Panel::make()->id('ai-runs-dropped')->plugin(
            MagicStarterPlugin::make()->withoutResources(['ai_runs']),
        );

        $this->assertNotContains(AiRunResource::class, $off->getResources());
        $this->assertNotContains(AiRunStatsWidget::class, $off->getWidgets());
        $this->assertContains(AiRunResource::class, $on->getResources());
        $this->assertContains(AiRunStatsWidget::class, $on->getWidgets());
        $this->assertNotContains(AiRunResource::class, $dropped->getResources());
    }

    public function test_the_resource_has_only_an_index_and_a_view_page_and_no_create_route(): void
    {
        $this->assertSame(['index', 'view'], array_keys(AiRunResource::getPages()));
        $this->assertFalse(AiRunResource::canCreate());

        // `/create` would match the view route's `{record}`, so the route names are what proves it is gone.
        $base = AiRunResource::getRouteBaseName();

        $this->assertTrue(Route::has("{$base}.index"));
        $this->assertTrue(Route::has("{$base}.view"));
        $this->assertFalse(Route::has("{$base}.create"));
    }

    public function test_the_list_shows_seeded_runs_and_offers_no_write_action(): void
    {
        $succeeded = $this->aiRun(['feature' => 'incident.analysis']);
        $failed = $this->aiRun(['feature' => 'status.summary', 'status' => AiRunStatus::FAILED]);

        Livewire::actingAs($this->staff())
            ->test(ListAiRuns::class)
            ->assertCanSeeTableRecords([$succeeded, $failed])
            ->assertSee('incident.analysis')
            ->assertSee('status.summary')
            ->assertActionDoesNotExist('create')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');
    }

    public function test_the_list_is_newest_first_by_default(): void
    {
        $old = $this->aiRun(['started_at' => now()->subDays(3)]);
        $new = $this->aiRun(['started_at' => now()->subMinute()]);

        Livewire::actingAs($this->staff())
            ->test(ListAiRuns::class)
            ->assertCanSeeTableRecords([$new, $old], inOrder: true);
    }

    public function test_the_list_filters_by_status(): void
    {
        $succeeded = $this->aiRun();
        $failed = $this->aiRun(['status' => AiRunStatus::FAILED]);

        Livewire::actingAs($this->staff())
            ->test(ListAiRuns::class)
            ->filterTable('status', AiRunStatus::FAILED->value)
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$succeeded]);
    }

    public function test_the_list_filters_by_feature_and_team_and_started_at_range(): void
    {
        $team = $this->team('Acme');
        $analysis = $this->aiRun(['feature' => 'incident.analysis', 'team_id' => (string) $team->getKey()]);
        $summary = $this->aiRun(['feature' => 'status.summary']);
        $old = $this->aiRun(['started_at' => now()->subDays(10)]);

        $list = Livewire::actingAs($this->staff())->test(ListAiRuns::class);

        $list->filterTable('feature', 'incident.analysis')
            ->assertCanSeeTableRecords([$analysis])
            ->assertCanNotSeeTableRecords([$summary]);

        $list->removeTableFilters()
            ->filterTable('team_id', (string) $team->getKey())
            ->assertCanSeeTableRecords([$analysis])
            ->assertCanNotSeeTableRecords([$summary]);

        $list->removeTableFilters()
            ->filterTable('started_at', ['started_from' => now()->subDays(3)->toDateString()])
            ->assertCanSeeTableRecords([$analysis, $summary])
            ->assertCanNotSeeTableRecords([$old])
            ->removeTableFilters()
            ->filterTable('started_at', ['started_until' => now()->subDays(3)->toDateString()])
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$analysis, $summary]);
    }

    public function test_the_list_names_the_team_and_shows_the_cost_and_tokens(): void
    {
        $team = $this->team('Acme');
        $run = $this->aiRun([
            'team_id' => (string) $team->getKey(),
            'input_tokens' => 1200,
            'output_tokens' => 340,
            'cache_read_tokens' => 800,
            'cost_usd' => '0.01234500',
            'duration_ms' => 2150,
        ]);

        Livewire::actingAs($this->staff())
            ->test(ListAiRuns::class)
            ->assertTableColumnStateSet('team', 'Acme', $run)
            ->assertTableColumnStateSet('input_tokens', 1200, $run)
            ->assertTableColumnStateSet('cache_read_tokens', 800, $run)
            ->assertSee('0.012345');
    }

    public function test_a_run_of_a_deleted_team_shows_its_team_id(): void
    {
        $team = $this->team('Gone');
        $run = $this->aiRun(['team_id' => (string) $team->getKey()]);
        $team->delete();

        Livewire::actingAs($this->staff())
            ->test(ListAiRuns::class)
            ->assertTableColumnStateSet('team', (string) $team->getKey(), $run);
    }

    public function test_the_team_filter_searches_and_names_teams_by_name(): void
    {
        $acme = $this->team('Acme');
        $this->team('Globex');

        $this->assertSame(
            [(string) $acme->getKey() => 'Acme'],
            $this->callResource('searchTeams', 'cm'),
        );
        $this->assertSame('Acme', $this->callResource('teamName', (string) $acme->getKey()));
    }

    public function test_without_the_teams_feature_the_team_is_the_stored_id(): void
    {
        $run = $this->aiRun(['team_id' => 'team-123']);
        $this->aiRun(['team_id' => 'team-456']);
        $this->aiRun(['team_id' => 'other-9']);

        config()->set('magic-starter.features', [Features::aiRuns()]);

        $this->assertSame('team-123', AiRunResource::teamLabel($run));
        $this->assertSame(
            ['team-123' => 'team-123', 'team-456' => 'team-456'],
            $this->callResource('searchTeams', 'team-'),
        );
        $this->assertSame('team-456', $this->callResource('teamName', 'team-456'));
    }

    public function test_the_resource_names_its_model(): void
    {
        $this->assertSame(__('magic-starter::admin_ai.model_label'), AiRunResource::getModelLabel());
    }

    public function test_the_view_names_the_subject_by_its_class_and_key(): void
    {
        $run = $this->aiRun(['subject_type' => 'App\\Models\\Incident', 'subject_id' => '42']);

        Livewire::actingAs($this->staff())
            ->test(ViewAiRun::class, ['record' => $run->getKey()])
            ->assertSee('Incident 42');
    }

    public function test_the_view_renders_a_run_with_no_subject_and_no_cost(): void
    {
        $run = $this->aiRun();
        $this->attempt($run, 1);

        Livewire::actingAs($this->staff())
            ->test(ViewAiRun::class, ['record' => $run->getKey()])
            ->assertOk()
            ->assertSee(AiRunResource::ABSENT);
    }

    public function test_the_view_shows_the_metadata_the_bodies_and_the_attempts(): void
    {
        $run = $this->aiRun([
            'feature' => 'incident.analysis',
            'status' => AiRunStatus::FAILED,
            'attempts_count' => 2,
            'instructions' => 'Be terse.',
            'input' => 'Why is checkout slow?',
            'context' => ['locale' => 'tr'],
            'tools' => [['name' => 'lookup_monitor']],
            'output' => 'Partial answer',
            'structured' => ['cause' => 'database'],
            'error_message' => 'Both providers refused',
        ]);
        $this->attempt($run, 1, [
            'provider' => 'anthropic',
            'status' => 'failed',
            'http_status' => 529,
            'error_message' => 'overloaded_error',
        ]);
        $this->attempt($run, 2, [
            'provider' => 'openrouter',
            'model' => 'deepseek-chat',
            'status' => 'succeeded',
            'tool_calls' => [
                ['name' => 'lookup_monitor', 'arguments' => ['id' => 7], 'result' => 'up'],
            ],
        ]);

        Livewire::actingAs($this->staff())
            ->test(ViewAiRun::class, ['record' => $run->getKey()])
            ->assertSee('incident.analysis')
            ->assertSee('Be terse.')
            ->assertSee('Why is checkout slow?')
            ->assertSee('"locale": "tr"')
            ->assertSee('lookup_monitor')
            ->assertSee('Partial answer')
            ->assertSee('"cause": "database"')
            ->assertSee('Both providers refused')
            ->assertSee('anthropic')
            ->assertSee('529')
            ->assertSee('overloaded_error')
            ->assertSee('openrouter')
            ->assertSee('deepseek-chat')
            ->assertDontSee('Bodies pruned on');
    }

    public function test_the_view_wraps_long_values_without_pre_wrapping_the_template_whitespace(): void
    {
        $run = $this->aiRun([
            'error_class' => 'App\\Exceptions\\ProviderRefusedTheRequestException',
            'prompt_hash' => str_repeat('ab', 32),
            'input' => "First line\nSecond line",
            'context' => ['locale' => 'tr'],
            'error_message' => "Both providers refused\n  indented detail",
        ]);
        $this->attempt($run, 1, [
            'status' => 'failed',
            'error_message' => "overloaded\nretry later",
            'tool_calls' => [['name' => 'lookup_monitor']],
        ]);

        $html = Livewire::actingAs($this->staff())
            ->test(ViewAiRun::class, ['record' => $run->getKey()])
            ->html();

        preg_match_all('/<div\b[^>]*\bfi-in-text\b[^>]*>/s', $html, $entries);

        // The wrapper holds the template's own indentation and newlines, so it must not preserve them.
        $this->assertNotEmpty($entries[0]);
        $this->assertStringNotContainsString('white-space: pre-wrap', implode("\n", $entries[0]));
        $this->assertStringContainsString('overflow-wrap: anywhere', implode("\n", $entries[0]));

        // A body keeps its own line breaks, and only its own.
        $this->assertStringContainsString(
            '<span style="white-space: pre-wrap;">First line' . "\n" . 'Second line</span>',
            $html,
        );
        $this->assertStringContainsString('&quot;locale&quot;: &quot;tr&quot;', $html);
        $this->assertStringContainsString('overloaded' . "\n" . 'retry later', $html);
    }

    public function test_a_body_is_escaped_before_it_keeps_its_line_breaks(): void
    {
        $run = $this->aiRun(['input' => "<script>alert(1)</script>\nnext"]);

        Livewire::actingAs($this->staff())
            ->test(ViewAiRun::class, ['record' => $run->getKey()])
            ->assertDontSee('<script>alert(1)</script>', escape: false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false);
    }

    public function test_the_view_renders_a_pruned_run_with_the_pruned_note(): void
    {
        $run = $this->aiRun([
            'status' => AiRunStatus::SUCCEEDED,
            'bodies_pruned_at' => Carbon::parse('2026-10-01 12:00:00'),
        ]);
        $this->attempt($run, 1, ['provider' => 'anthropic', 'status' => 'succeeded']);

        Livewire::actingAs($this->staff())
            ->test(ViewAiRun::class, ['record' => $run->getKey()])
            ->assertSuccessful()
            ->assertSee('Bodies pruned on')
            ->assertSee('Oct 1, 2026')
            ->assertSee('anthropic');
    }

    public function test_the_status_badge_and_the_status_filter_show_translated_labels(): void
    {
        $failed = $this->aiRun(['status' => AiRunStatus::FAILED]);
        $cached = $this->aiRun(['status' => AiRunStatus::CACHE_HIT]);

        Livewire::actingAs($this->staff())
            ->test(ListAiRuns::class)
            ->assertTableColumnFormattedStateSet('status', 'Failed', $failed)
            ->assertTableColumnFormattedStateSet('status', 'Cache hit', $cached);

        $options = (new ReflectionMethod(AiRunResource::class, 'statusOptions'))->invoke(null);

        $this->assertSame([
            'running' => 'Running',
            'succeeded' => 'Succeeded',
            'failed' => 'Failed',
            'cache_hit' => 'Cache hit',
            'abandoned' => 'Abandoned',
        ], $options);

        app()->setLocale('tr');

        $this->assertSame('Başarısız', AiRunResource::statusLabel(AiRunStatus::FAILED));

        foreach (AiRunStatus::cases() as $status) {
            $this->assertNotSame(
                "magic-starter::admin_ai.statuses.{$status->value}",
                AiRunResource::statusLabel($status),
            );
        }
    }

    public function test_the_view_shows_translated_statuses_and_the_source_and_cost_source_values(): void
    {
        $run = $this->aiRun([
            'status' => AiRunStatus::FAILED,
            'source' => 'override',
            'cost_source' => 'computed',
        ]);
        $this->attempt($run, 1, ['status' => 'succeeded', 'cost_source' => 'provider']);

        Livewire::actingAs($this->staff())
            ->test(ViewAiRun::class, ['record' => $run->getKey()])
            ->assertSuccessful()
            ->assertSee('Failed')
            ->assertSee('Succeeded')
            ->assertSee('override')
            ->assertSee('computed');
    }

    public function test_the_stats_widget_does_not_poll(): void
    {
        $widget = new AiRunStatsWidget;

        $this->assertNull((new ReflectionMethod($widget, 'getPollingInterval'))->invoke($widget));
    }

    public function test_a_user_outside_the_panel_gate_cannot_reach_the_list(): void
    {
        $this->actingAs($this->staff())->get('/admin/ai-runs')->assertOk();
        $this->actingAs($this->user('member@example.com'))->get('/admin/ai-runs')->assertForbidden();
    }

    public function test_the_cache_hit_rate_is_the_cache_read_share_of_the_input_tokens(): void
    {
        $this->aiRun(['input_tokens' => 100, 'cache_read_tokens' => 40]);
        $this->aiRun(['input_tokens' => 100, 'cache_read_tokens' => 60]);

        $this->assertSame('50%', $this->stats()[$this->label('cache_hit_rate', 7)]);
        $this->assertSame('50%', $this->stats()[$this->label('cache_hit_rate', 30)]);
    }

    public function test_the_cache_hit_rate_ignores_runs_that_did_not_report_both_figures(): void
    {
        $this->aiRun(['input_tokens' => 100, 'cache_read_tokens' => 50]);
        $this->aiRun(['input_tokens' => 900, 'cache_read_tokens' => null]);
        $this->aiRun(['input_tokens' => null, 'cache_read_tokens' => 500]);

        $this->assertSame('50%', $this->stats()[$this->label('cache_hit_rate', 7)]);
    }

    public function test_the_cache_hit_rate_shows_a_dash_when_no_run_qualifies(): void
    {
        $this->assertSame('-', $this->stats()[$this->label('cache_hit_rate', 7)]);

        $this->aiRun(['input_tokens' => 0, 'cache_read_tokens' => 0]);
        $this->aiRun(['input_tokens' => 100, 'cache_read_tokens' => null]);

        $this->assertSame('-', $this->stats()[$this->label('cache_hit_rate', 7)]);
    }

    public function test_the_widget_counts_runs_failures_and_cost_per_window(): void
    {
        $this->aiRun(['status' => AiRunStatus::FAILED, 'cost_usd' => '0.50000000', 'started_at' => now()->subDay()]);
        $this->aiRun(['cost_usd' => '1.25000000', 'started_at' => now()->subDays(2)]);
        $this->aiRun(['cost_usd' => null, 'started_at' => now()->subDays(3)]);
        $this->aiRun(['status' => AiRunStatus::FAILED, 'cost_usd' => '2.00000000', 'started_at' => now()->subDays(20)]);
        $this->aiRun(['cost_usd' => '9.00000000', 'started_at' => now()->subDays(45)]);

        $stats = $this->stats();

        $this->assertSame(3, $stats[$this->label('runs', 7)]);
        $this->assertSame('33.3%', $stats[$this->label('failed', 7)]);
        $this->assertSame('$1.7500', $stats[$this->label('cost', 7)]);

        $this->assertSame(4, $stats[$this->label('runs', 30)]);
        $this->assertSame('50%', $stats[$this->label('failed', 30)]);
        $this->assertSame('$3.7500', $stats[$this->label('cost', 30)]);
    }

    public function test_the_widget_shows_zero_runs_and_a_dash_for_the_failed_share_on_an_empty_log(): void
    {
        $stats = $this->stats();

        $this->assertSame(0, $stats[$this->label('runs', 7)]);
        $this->assertSame('-', $stats[$this->label('failed', 7)]);
        $this->assertSame('$0.0000', $stats[$this->label('cost', 7)]);
    }

    /**
     * The widget's figures by label.
     *
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        $widget = new AiRunStatsWidget;
        $figures = [];

        foreach ((new ReflectionMethod($widget, 'getStats'))->invoke($widget) as $stat) {
            $figures[(string) $stat->getLabel()] = $stat->getValue();
        }

        return $figures;
    }

    private function label(string $key, int $days): string
    {
        return (string) __("magic-starter::admin_ai.widget.{$key}", ['days' => $days]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function aiRun(array $attributes = []): AiRun
    {
        return AiRun::query()->create([
            'feature' => 'incident.analysis',
            'status' => AiRunStatus::SUCCEEDED,
            'attempts_count' => 1,
            'started_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function attempt(AiRun $run, int $number, array $attributes = []): AiRunAttempt
    {
        return AiRunAttempt::query()->create([
            'ai_run_id' => $run->getKey(),
            'attempt' => $number,
            'provider' => 'anthropic',
            'status' => 'succeeded',
            ...$attributes,
        ]);
    }

    /**
     * One of the resource's protected static helpers, reached the way Filament reaches it.
     */
    private function callResource(string $method, string $argument): mixed
    {
        return (new ReflectionMethod(AiRunResource::class, $method))->invoke(null, $argument);
    }

    private function team(string $name): ConcreteTeam
    {
        return ConcreteTeam::query()->create([
            'name' => $name,
            'personal_team' => false,
            'user_id' => $this->staff()->getKey(),
        ]);
    }

    private function staff(): ConcreteAdminUser
    {
        config()->set('magic-starter.admin.emails', ['staff@example.com']);

        return ConcreteAdminUser::query()->firstOrCreate(['email' => 'staff@example.com'], [
            'name' => 'Staff',
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
        ]);
    }

    private function user(string $email): ConcreteAdminUser
    {
        return ConcreteAdminUser::query()->create([
            'name' => strstr($email, '@', true),
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => Carbon::now(),
        ]);
    }
}
