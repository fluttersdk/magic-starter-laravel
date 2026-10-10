<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use FlutterSdk\MagicStarter\Console\PruneBillingRecordsCommand;
use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Models\ProcessedWebhookEvent;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * `magic-starter:billing:prune` deletes webhook dedup rows by age, never below
 * the 31 days Stripe's resend window needs, and prunes the billing history only
 * when the adopter set a retention for it. Scheduled while billing is on.
 */
#[DefineEnvironment('withBillingEnabled')]
final class PruneBillingRecordsCommandTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function withBillingEnabled($app): void
    {
        $app['config']->set([
            'magic-starter.features' => [Features::billing()],
            'magic-starter.billing' => BillingManifestCommandTest::billing(),
        ]);
    }

    /**
     * @param  Application  $app
     */
    protected function withBillingDisabled($app): void
    {
        $app['config']->set('magic-starter.features', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('magic-starter.use_uuids', false);

        (require __DIR__ . '/../../database/migrations/create_processed_webhook_events_table.php')->up();
        (require __DIR__ . '/../../database/migrations/create_billing_events_table.php')->up();
    }

    public function test_it_deletes_claims_older_than_the_retention_and_keeps_the_rest(): void
    {
        config()->set('magic-starter.billing.webhook_retention_days', 90);
        $this->seedClaim('old', 100);
        $this->seedClaim('recent', 10);

        $this->artisan(PruneBillingRecordsCommand::NAME)
            ->expectsOutputToContain('Pruned 1 webhook claim')
            ->assertSuccessful();

        $this->assertSame(['recent'], ProcessedWebhookEvent::query()->pluck('event_id')->all());
    }

    public function test_it_deletes_every_old_claim_across_chunks(): void
    {
        config()->set('magic-starter.billing.webhook_retention_days', 90);

        foreach (range(1, PruneBillingRecordsCommand::CHUNK_SIZE + 5) as $position) {
            $this->seedClaim("old-{$position}", 100);
        }

        $this->seedClaim('recent', 1);

        $this->artisan(PruneBillingRecordsCommand::NAME)
            ->expectsOutputToContain('Pruned ' . (PruneBillingRecordsCommand::CHUNK_SIZE + 5) . ' webhook claims')
            ->assertSuccessful();

        $this->assertSame(['recent'], ProcessedWebhookEvent::query()->pluck('event_id')->all());
    }

    public function test_a_retention_below_31_days_is_clamped_to_31(): void
    {
        config()->set('magic-starter.billing.webhook_retention_days', 5);
        $this->seedClaim('inside-resend-window', 20);
        $this->seedClaim('past-resend-window', 40);

        $this->artisan(PruneBillingRecordsCommand::NAME)
            ->expectsOutputToContain('older than 31 days')
            ->assertSuccessful();

        $this->assertSame(['inside-resend-window'], ProcessedWebhookEvent::query()->pluck('event_id')->all());
    }

    public function test_it_leaves_the_billing_history_alone_when_no_events_retention_is_set(): void
    {
        config()->set('magic-starter.billing.events_retention_days', null);
        $this->seedEvent('ancient', 4000);

        $this->artisan(PruneBillingRecordsCommand::NAME)->assertSuccessful();

        $this->assertSame(['ancient'], BillingEvent::query()->pluck('external_id')->all());
    }

    /**
     * `MAGIC_STARTER_BILLING_EVENTS_RETENTION_DAYS=` reads as '', which is not
     * null; cast to int it would be a one-day retention and wipe the history.
     */
    public function test_a_blank_events_retention_keeps_the_billing_history(): void
    {
        config()->set('magic-starter.billing.events_retention_days', '');
        $this->seedEvent('ancient', 4000);
        $this->seedEvent('yesterday', 2);

        $this->artisan(PruneBillingRecordsCommand::NAME)
            ->expectsOutputToContain('Kept every billing event')
            ->assertSuccessful();

        $this->assertSame(['ancient', 'yesterday'], BillingEvent::query()->pluck('external_id')->all());
    }

    public function test_a_non_numeric_events_retention_keeps_the_billing_history(): void
    {
        config()->set('magic-starter.billing.events_retention_days', 'abc');
        $this->seedEvent('ancient', 4000);

        $this->artisan(PruneBillingRecordsCommand::NAME)
            ->expectsOutputToContain('Kept every billing event')
            ->assertSuccessful();

        $this->assertSame(['ancient'], BillingEvent::query()->pluck('external_id')->all());
    }

    /**
     * The append-only guard throws from a model `deleting` event, so a prune that
     * went through a model instance would throw here instead of deleting.
     */
    public function test_it_prunes_the_billing_history_past_the_configured_retention(): void
    {
        // The env value arrives as a string.
        config()->set('magic-starter.billing.events_retention_days', '30');
        $this->seedEvent('old', 40);
        $this->seedEvent('recent', 10);

        $this->artisan(PruneBillingRecordsCommand::NAME)
            ->expectsOutputToContain('Pruned 1 billing event')
            ->assertSuccessful();

        $this->assertSame(['recent'], BillingEvent::query()->pluck('external_id')->all());
    }

    public function test_it_deletes_every_old_billing_event_across_chunks(): void
    {
        config()->set('magic-starter.billing.events_retention_days', 30);

        foreach (range(1, PruneBillingRecordsCommand::CHUNK_SIZE + 5) as $position) {
            $this->seedEvent("old-{$position}", 40);
        }

        $this->seedEvent('recent', 1);

        $this->artisan(PruneBillingRecordsCommand::NAME)
            ->expectsOutputToContain('Pruned ' . (PruneBillingRecordsCommand::CHUNK_SIZE + 5) . ' billing events')
            ->assertSuccessful();

        $this->assertSame(['recent'], BillingEvent::query()->pluck('external_id')->all());
    }

    public function test_a_missing_table_is_skipped_rather_than_failing_the_command(): void
    {
        config()->set('magic-starter.billing.events_retention_days', 30);
        Schema::drop('processed_webhook_events');
        Schema::drop('billing_events');

        $this->artisan(PruneBillingRecordsCommand::NAME)
            ->expectsOutputToContain('processed_webhook_events does not exist')
            ->expectsOutputToContain('billing_events does not exist')
            ->assertSuccessful();
    }

    public function test_it_is_scheduled_daily_without_overlapping_while_billing_is_on(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString(PruneBillingRecordsCommand::NAME, Artisan::output());

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, PruneBillingRecordsCommand::NAME));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    #[DefineEnvironment('withBillingDisabled')]
    public function test_it_is_neither_scheduled_nor_registered_while_billing_is_off(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringNotContainsString(PruneBillingRecordsCommand::NAME, Artisan::output());
        $this->assertArrayNotHasKey(PruneBillingRecordsCommand::NAME, Artisan::all());
    }

    private function seedClaim(string $eventId, int $daysOld): void
    {
        $claim = new ProcessedWebhookEvent;
        $claim->forceFill([
            'event_id' => $eventId,
            'type' => 'test.event',
            'processed_at' => now()->subDays($daysOld),
        ])->save();
    }

    private function seedEvent(string $externalId, int $daysOld): void
    {
        BillingEvent::query()->create([
            'type' => BillingEventType::ENTITLEMENT_APPLIED,
            'source' => BillingSource::WEBHOOK,
            'external_id' => $externalId,
            'created_at' => now()->subDays($daysOld),
        ]);
    }
}
