<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use FlutterSdk\MagicStarter\Console\ExpireBillingGrantsCommand;
use FlutterSdk\MagicStarter\Console\PruneAiRunsCommand;
use FlutterSdk\MagicStarter\Console\PruneAuditsCommand;
use FlutterSdk\MagicStarter\Console\PruneBillingRecordsCommand;
use FlutterSdk\MagicStarter\Console\PruneMailSendsCommand;
use FlutterSdk\MagicStarter\Console\PruneSupportArticleImagesCommand;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The six package-scheduled maintenance tasks take their time of day from `magic-starter.schedule.<key>`, one cron
 * expression each, and fall back to the shipped default when the key is missing: `mergeConfigFrom` is a shallow
 * merge, so a host that published the config before the block existed has no `schedule` key at all.
 */
final class ScheduledTaskTimesTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'magic-starter.features' => [
                Features::audit(),
                Features::aiRuns(),
                Features::billing(),
                Features::support(),
            ],
            'magic-starter.billing' => BillingManifestCommandTest::billing(),
            'magic-starter.support.articles.enabled' => true,
        ]);
    }

    /**
     * @return array<string, array{string, string, string, string, bool}>
     */
    public static function tasks(): array
    {
        return [
            'audit prune' => [PruneAuditsCommand::NAME, 'audit_prune', '0 0 * * *', '20 5 * * *', false],
            'mail prune sends' => [PruneMailSendsCommand::NAME, 'mail_prune_sends', '0 0 * * *', '20 5 * * *', false],
            'billing prune' => [PruneBillingRecordsCommand::NAME, 'billing_prune', '0 0 * * *', '20 5 * * *', true],
            'support article images prune' => [
                PruneSupportArticleImagesCommand::NAME,
                'support_article_images_prune',
                '0 0 * * *',
                '20 5 * * *',
                true,
            ],
            'ai runs prune' => [PruneAiRunsCommand::NAME, 'ai_runs_prune', '0 * * * *', '25 * * * *', false],
            'billing expire grants' => [
                ExpireBillingGrantsCommand::NAME,
                'billing_expire_grants',
                '0 * * * *',
                '25 * * * *',
                true,
            ],
        ];
    }

    #[DataProvider('tasks')]
    public function test_the_default_expression_is_unchanged(
        string $command,
        string $key,
        string $default,
    ): void {
        $this->assertSame($default, config("magic-starter.schedule.{$key}"));
        $this->assertSame($default, $this->scheduled($command)->expression);
    }

    #[DataProvider('tasks')]
    public function test_a_configured_expression_is_used(
        string $command,
        string $key,
        string $default,
        string $configured,
    ): void {
        config(["magic-starter.schedule.{$key}" => $configured]);

        $this->assertSame($configured, $this->scheduled($command)->expression);
    }

    #[DataProvider('tasks')]
    public function test_a_host_config_without_the_schedule_block_falls_back_to_the_default(
        string $command,
        string $key,
        string $default,
    ): void {
        config(['magic-starter.schedule' => null]);

        $this->assertSame($default, $this->scheduled($command)->expression);
    }

    #[DataProvider('tasks')]
    public function test_an_unusable_value_falls_back_to_the_default(
        string $command,
        string $key,
        string $default,
    ): void {
        foreach (['', '   ', null, 15, ['5 * * * *']] as $unusable) {
            config(["magic-starter.schedule.{$key}" => $unusable]);

            $this->assertSame($default, $this->scheduled($command)->expression);
        }
    }

    #[DataProvider('tasks')]
    public function test_an_invalid_expression_fails_loudly_instead_of_being_replaced(
        string $command,
        string $key,
    ): void {
        config(["magic-starter.schedule.{$key}" => 'not a cron expression']);

        $event = $this->scheduled($command);

        $this->assertSame('not a cron expression', $event->expression);

        $this->expectException(InvalidArgumentException::class);

        $event->isDue($this->app);
    }

    #[DataProvider('tasks')]
    public function test_the_overlap_and_one_server_guards_are_kept(
        string $command,
        string $key,
        string $default,
        string $configured,
        bool $onOneServer,
    ): void {
        config(["magic-starter.schedule.{$key}" => $configured]);

        $event = $this->scheduled($command);

        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame($onOneServer, $event->onOneServer);
    }

    /**
     * The scheduler is rebuilt so the entry is registered against the config this test set.
     */
    private function scheduled(string $command): ScheduledEvent
    {
        $this->app->forgetInstance(Schedule::class);

        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event): bool => $event->description === $command)
            ->values();

        $this->assertCount(1, $events, "Expected one scheduled entry for [{$command}].");

        return $events->first();
    }
}
