<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Console\PruneAuditsCommand;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * `magic-starter:audit:prune` deletes by age and nothing else, and is only
 * registered and scheduled while the audit feature is on.
 */
#[DefineEnvironment('withAuditEnabled')]
final class PruneAuditsCommandTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function withAuditEnabled($app): void
    {
        $app['config']->set('magic-starter.features', [
            Features::audit(),
        ]);
    }

    /**
     * @param  Application  $app
     */
    protected function withAuditDisabled($app): void
    {
        $app['config']->set('magic-starter.features', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('magic-starter.use_uuids', false);

        (require __DIR__ . '/../../database/migrations/create_magic_starter_audits_table.php')->up();
    }

    public function test_it_deletes_rows_older_than_the_retention_and_keeps_the_rest(): void
    {
        config()->set('magic-starter.audit.retention_days', 365);
        $this->seedAudit('old', 400);
        $this->seedAudit('recent', 10);

        $this->artisan(PruneAuditsCommand::NAME)
            ->expectsOutputToContain('Pruned 1 audit row')
            ->assertSuccessful();

        $this->assertSame(['recent'], Audit::query()->pluck('event')->all());
    }

    public function test_it_honours_a_configured_retention(): void
    {
        config()->set('magic-starter.audit.retention_days', 5);
        $this->seedAudit('old', 6);
        $this->seedAudit('recent', 4);

        $this->artisan(PruneAuditsCommand::NAME)->assertSuccessful();

        $this->assertSame(['recent'], Audit::query()->pluck('event')->all());
    }

    public function test_it_deletes_every_old_row_across_chunks(): void
    {
        config()->set('magic-starter.audit.retention_days', 30);

        foreach (range(1, PruneAuditsCommand::CHUNK_SIZE + 5) as $position) {
            $this->seedAudit("old-{$position}", 60);
        }

        $this->seedAudit('recent', 1);

        $this->artisan(PruneAuditsCommand::NAME)
            ->expectsOutputToContain('Pruned ' . (PruneAuditsCommand::CHUNK_SIZE + 5) . ' audit rows')
            ->assertSuccessful();

        $this->assertSame(['recent'], Audit::query()->pluck('event')->all());
    }

    public function test_it_is_scheduled_daily_without_overlapping_while_the_feature_is_on(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString(PruneAuditsCommand::NAME, Artisan::output());

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, PruneAuditsCommand::NAME));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    #[DefineEnvironment('withAuditDisabled')]
    public function test_it_is_neither_scheduled_nor_registered_while_the_feature_is_off(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringNotContainsString(PruneAuditsCommand::NAME, Artisan::output());
        $this->assertArrayNotHasKey(PruneAuditsCommand::NAME, Artisan::all());
    }

    private function seedAudit(string $event, int $daysOld): void
    {
        $audit = new Audit;
        $audit->forceFill([
            'event' => $event,
            'created_at' => now()->subDays($daysOld),
        ])->save();
    }
}
