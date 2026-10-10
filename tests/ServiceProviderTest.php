<?php

namespace FlutterSdk\MagicStarter\Tests;

use FlutterSdk\MagicStarter\Actions\AdministerBilling;
use FlutterSdk\MagicStarter\Console\ExpireBillingGrantsCommand;
use FlutterSdk\MagicStarter\Console\PruneBillingRecordsCommand;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Tests\Console\BillingManifestCommandTest;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;

class ServiceProviderTest extends TestCase
{
    public function test_provider_is_loaded(): void
    {
        $this->assertTrue(
            $this->app->providerIsLoaded(MagicStarterServiceProvider::class),
        );
    }

    public function test_config_is_merged(): void
    {
        $this->assertNotNull(config('magic-starter'));
        $this->assertIsArray(config('magic-starter.features'));
        $this->assertIsArray(config('magic-starter.models'));
    }

    public function test_config_has_expected_keys(): void
    {
        $this->assertArrayHasKey('features', config('magic-starter'));
        $this->assertArrayHasKey('models', config('magic-starter'));
        $this->assertArrayHasKey('route_prefix', config('magic-starter'));
    }

    public function test_config_models_has_user_and_team(): void
    {
        $models = config('magic-starter.models');
        $this->assertArrayHasKey('user', $models);
        $this->assertArrayHasKey('team', $models);
    }

    public function test_config_has_frontend_url_key(): void
    {
        $this->assertArrayHasKey('frontend_url', config('magic-starter'));
    }

    public function test_notification_gate_listener_registered_when_feature_enabled(): void
    {
        config([
            'magic-starter.features' => [
                Features::notifications(),
            ],
        ]);

        // Re-boot the provider to pick up the feature config.
        (new MagicStarterServiceProvider($this->app))->boot();

        $this->assertTrue(
            Event::hasListeners(NotificationSending::class),
        );
    }

    public function test_notification_gate_listener_not_registered_when_feature_disabled(): void
    {
        config(['magic-starter.features' => []]);

        // Fresh app instance — no listeners from previous tests.
        $dispatcher = new \Illuminate\Events\Dispatcher($this->app);
        $this->app->instance('events', $dispatcher);

        (new MagicStarterServiceProvider($this->app))->boot();

        $this->assertFalse(
            $dispatcher->hasListeners(NotificationSending::class),
        );
    }

    public function test_the_billing_prune_is_scheduled_when_billing_is_on(): void
    {
        config([
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing' => BillingManifestCommandTest::billing(),
        ]);

        (new MagicStarterServiceProvider($this->app))->boot();

        $this->assertTrue($this->scheduleHasBillingPrune());
    }

    public function test_the_billing_prune_is_not_scheduled_when_billing_is_off(): void
    {
        config(['magic-starter.features' => []]);

        (new MagicStarterServiceProvider($this->app))->boot();

        $this->assertFalse($this->scheduleHasBillingPrune());
    }

    public function test_the_billing_administration_contract_is_bound_to_the_package_action(): void
    {
        $this->assertInstanceOf(AdministerBilling::class, $this->app->make(AdministersBilling::class));
    }

    public function test_the_grant_expiry_is_scheduled_hourly_on_one_server_when_billing_is_on(): void
    {
        config([
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing' => BillingManifestCommandTest::billing(),
        ]);

        (new MagicStarterServiceProvider($this->app))->boot();

        $events = $this->scheduledEventsFor(ExpireBillingGrantsCommand::NAME);

        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertTrue($events[0]->onOneServer);
    }

    public function test_the_grant_expiry_is_not_scheduled_when_billing_is_off(): void
    {
        config(['magic-starter.features' => []]);

        (new MagicStarterServiceProvider($this->app))->boot();

        $this->assertSame([], $this->scheduledEventsFor(ExpireBillingGrantsCommand::NAME));
    }

    /**
     * @return list<ScheduledEvent>
     */
    private function scheduledEventsFor(string $command): array
    {
        return collect($this->app->make(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, $command))
            ->values()
            ->all();
    }

    private function scheduleHasBillingPrune(): bool
    {
        return collect($this->app->make(Schedule::class)->events())
            ->contains(fn ($event): bool => str_contains((string) $event->command, PruneBillingRecordsCommand::NAME));
    }
}
