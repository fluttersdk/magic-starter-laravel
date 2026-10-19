<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use FlutterSdk\MagicStarter\Console\PruneSupportArticleImagesCommand;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;

/**
 * The knowledge base's migration is published, not loaded: a support host that upgrades with the articles off may
 * have no `support_articles` table, so the daily prune must not exist for it to fail on.
 */
final class PruneSupportArticleImagesScheduleTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'magic-starter.features' => [Features::support()],
            'magic-starter.support.articles.enabled' => false,
        ]);
    }

    public function test_it_is_neither_registered_nor_scheduled_while_the_articles_are_off(): void
    {
        $this->assertArrayNotHasKey(PruneSupportArticleImagesCommand::NAME, Artisan::all());

        $scheduled = collect($this->app->make(Schedule::class)->events())->contains(
            fn ($event): bool => str_contains((string) $event->command, PruneSupportArticleImagesCommand::NAME),
        );

        $this->assertFalse($scheduled);
    }
}
