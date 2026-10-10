<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Filament\Panel;
use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Resources\BillingEvents\BillingEventResource;
use FlutterSdk\MagicStarter\Filament\Resources\BillingEvents\Pages\ListBillingEvents;
use FlutterSdk\MagicStarter\Filament\Resources\BillingEvents\Pages\ViewBillingEvent;
use FlutterSdk\MagicStarter\Filament\Resources\WebhookDeliveries\Pages\ListWebhookDeliveries;
use FlutterSdk\MagicStarter\Filament\Resources\WebhookDeliveries\Pages\ViewWebhookDelivery;
use FlutterSdk\MagicStarter\Filament\Resources\WebhookDeliveries\WebhookDeliveryResource;
use FlutterSdk\MagicStarter\Jobs\SyncRevenueCatEntitlement;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Models\ProcessedWebhookEvent;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;
use Livewire\Livewire;

/**
 * The two read-only billing resources: the billing events history and the
 * webhook deliveries claimed in processed_webhook_events.
 *
 * Billing is on while the application boots, because a resource's routes are
 * registered at that moment; the "billing off" case builds its own panel the
 * way PluginTest does.
 */
class BillingResourcesTest extends FilamentTestCase
{
    private const ADMIN_EMAIL = 'ops@example.com';

    /**
     * A badge element carrying the danger colour, whatever order Filament lists
     * its classes in: the page has other danger controls, such as the filter reset.
     */
    private const DANGER_BADGE = '/<span(?=[^>]*\\bfi-badge\\b)(?=[^>]*\\bfi-color-danger\\b)[^>]*>/';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::teams(),
            Features::billing(),
        ]);

        // Billing refuses to boot without a ranking.
        $app['config']->set('magic-starter.billing.tier_order', ['free', 'pro']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The provider wires Cashier while it REGISTERS, which is before the
        // features above are written, so the fixture does what an application
        // with billing on gets from the provider.
        Cashier::useSubscriptionModel(Subscription::class);
    }

    protected function tearDown(): void
    {
        // Statics on Cashier outlive the application that set them.
        Cashier::useCustomerModel('App\\Models\\User');
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);

        parent::tearDown();
    }

    public function test_both_resources_are_mounted_only_with_the_billing_feature(): void
    {
        $resources = [
            'billing_events' => BillingEventResource::class,
            'webhook_deliveries' => WebhookDeliveryResource::class,
        ];

        config()->set('magic-starter.features', []);
        $off = Panel::make()->id('billing-off')->plugin(MagicStarterPlugin::make());

        config()->set('magic-starter.features', [Features::billing()]);
        $on = Panel::make()->id('billing-on')->plugin(MagicStarterPlugin::make());
        $dropped = Panel::make()->id('billing-dropped')->plugin(
            MagicStarterPlugin::make()->withoutResources(array_keys($resources)),
        );

        foreach ($resources as $key => $resource) {
            $this->assertNotContains($resource, $off->getResources(), "[{$key}] mounts with billing off.");
            $this->assertContains($resource, $on->getResources(), "[{$key}] is missing with billing on.");
            $this->assertNotContains($resource, $dropped->getResources(), "[{$key}] ignores withoutResources.");
        }
    }

    public function test_both_resources_have_only_an_index_and_a_view_page(): void
    {
        $this->assertSame(['index', 'view'], array_keys(BillingEventResource::getPages()));
        $this->assertSame(['index', 'view'], array_keys(WebhookDeliveryResource::getPages()));
    }

    public function test_the_billing_event_list_shows_rows_and_offers_no_write_action(): void
    {
        $granted = $this->event(BillingEventType::ENTITLEMENT_APPLIED);
        $refused = $this->event(BillingEventType::DELIVERY_REFUSED, ['reason' => 'unknown_product']);

        Livewire::actingAs($this->admin())
            ->test(ListBillingEvents::class)
            ->assertCanSeeTableRecords([$granted, $refused])
            ->assertSee('unknown_product')
            ->assertActionDoesNotExist('create')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');
    }

    public function test_the_billing_event_list_filters_by_type_source_and_provider(): void
    {
        $webhook = $this->event(BillingEventType::ENTITLEMENT_APPLIED, [
            'source' => BillingSource::WEBHOOK,
            'provider' => BillingProvider::STRIPE,
        ]);
        $admin = $this->event(BillingEventType::GRANT_ADDED, [
            'source' => BillingSource::ADMIN,
            'provider' => BillingProvider::MANUAL,
        ]);

        $list = Livewire::actingAs($this->admin())->test(ListBillingEvents::class);

        $list->filterTable('type', BillingEventType::GRANT_ADDED->value)
            ->assertCanSeeTableRecords([$admin])
            ->assertCanNotSeeTableRecords([$webhook]);

        $list->removeTableFilters()
            ->filterTable('source', BillingSource::WEBHOOK->value)
            ->assertCanSeeTableRecords([$webhook])
            ->assertCanNotSeeTableRecords([$admin]);

        $list->removeTableFilters()
            ->filterTable('provider', BillingProvider::MANUAL->value)
            ->assertCanSeeTableRecords([$admin])
            ->assertCanNotSeeTableRecords([$webhook]);
    }

    public function test_the_billing_event_list_filters_by_a_created_at_range(): void
    {
        $old = $this->event(BillingEventType::ENTITLEMENT_APPLIED, ['created_at' => now()->subDays(10)]);
        $recent = $this->event(BillingEventType::ENTITLEMENT_APPLIED, ['created_at' => now()->subDay()]);

        Livewire::actingAs($this->admin())
            ->test(ListBillingEvents::class)
            ->filterTable('created_at', ['created_from' => now()->subDays(3)->toDateString()])
            ->assertCanSeeTableRecords([$recent])
            ->assertCanNotSeeTableRecords([$old])
            ->removeTableFilters()
            ->filterTable('created_at', ['created_until' => now()->subDays(3)->toDateString()])
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$recent]);
    }

    public function test_the_billing_event_list_searches_the_external_id_and_names_the_billable_and_the_actor(): void
    {
        $actor = $this->admin();
        $billable = $this->admin('billable@example.com');
        $hit = $this->event(BillingEventType::ENTITLEMENT_APPLIED, [
            'external_id' => 'evt_needle',
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => (string) $billable->getKey(),
            'actor_user_id' => $actor->getKey(),
        ]);
        $miss = $this->event(BillingEventType::ENTITLEMENT_APPLIED, ['external_id' => 'evt_other']);

        Livewire::actingAs($actor)
            ->test(ListBillingEvents::class)
            ->searchTable('evt_needle')
            ->assertCanSeeTableRecords([$hit])
            ->assertCanNotSeeTableRecords([$miss])
            ->assertTableColumnStateSet('billable', 'ConcreteAdminUser ' . $billable->getKey(), $hit)
            ->assertTableColumnStateSet('actor.email', self::ADMIN_EMAIL, $hit);
    }

    public function test_a_refusal_is_a_danger_badge_and_any_other_outcome_is_not(): void
    {
        $refusal = $this->event(BillingEventType::REQUEST_REFUSED);

        $refused = Livewire::actingAs($this->admin())
            ->test(ListBillingEvents::class)
            ->assertCanSeeTableRecords([$refusal]);

        $this->assertMatchesRegularExpression(self::DANGER_BADGE, $refused->html());

        $refusal->newQuery()->whereKey($refusal->getKey())->delete();
        $outcome = $this->event(BillingEventType::GRANT_ADDED);

        $granted = Livewire::actingAs($this->admin())
            ->test(ListBillingEvents::class)
            ->assertCanSeeTableRecords([$outcome]);

        $this->assertDoesNotMatchRegularExpression(self::DANGER_BADGE, $granted->html());
    }

    public function test_the_billing_event_view_shows_every_column_and_the_properties(): void
    {
        $actor = $this->admin();
        $event = $this->event(BillingEventType::ENTITLEMENT_DROPPED, [
            'reason' => 'stale_event',
            'external_id' => 'evt_view',
            'actor_user_id' => $actor->getKey(),
            'properties' => [
                'app_user_id' => '$RCAnonymousID:abc',
                'snapshot' => ['plan' => 'pro'],
            ],
        ]);

        Livewire::actingAs($actor)
            ->test(ViewBillingEvent::class, ['record' => $event->getKey()])
            ->assertSee('entitlement_dropped')
            ->assertSee('stale_event')
            ->assertSee('evt_view')
            ->assertSee(self::ADMIN_EMAIL)
            ->assertSee('$RCAnonymousID:abc')
            ->assertSee('"plan": "pro"');
    }

    public function test_the_delivery_list_derives_the_provider_and_strips_the_revenuecat_prefix(): void
    {
        $stripe = $this->delivery('evt_stripe_1', 'invoice.paid');
        $revenueCat = $this->delivery(SyncRevenueCatEntitlement::CLAIM_PREFIX . 'rc_event_1', 'INITIAL_PURCHASE');

        Livewire::actingAs($this->admin())
            ->test(ListWebhookDeliveries::class)
            ->assertCanSeeTableRecords([$stripe, $revenueCat])
            ->assertTableColumnStateSet('provider', 'Stripe', $stripe)
            ->assertTableColumnStateSet('provider', 'RevenueCat', $revenueCat)
            ->assertTableColumnFormattedStateSet('event_id', 'evt_stripe_1', $stripe)
            ->assertTableColumnFormattedStateSet('event_id', 'rc_event_1', $revenueCat)
            ->assertActionDoesNotExist('create')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');
    }

    public function test_the_delivery_list_filters_by_provider_and_searches_the_event_id(): void
    {
        $stripe = $this->delivery('evt_stripe_1', 'invoice.paid');
        $revenueCat = $this->delivery(SyncRevenueCatEntitlement::CLAIM_PREFIX . 'rc_event_1', 'RENEWAL');

        $list = Livewire::actingAs($this->admin())->test(ListWebhookDeliveries::class);

        $list->filterTable('provider', 'revenuecat')
            ->assertCanSeeTableRecords([$revenueCat])
            ->assertCanNotSeeTableRecords([$stripe]);

        $list->removeTableFilters()
            ->filterTable('provider', 'stripe')
            ->assertCanSeeTableRecords([$stripe])
            ->assertCanNotSeeTableRecords([$revenueCat]);

        $list->removeTableFilters()
            ->searchTable('rc_event_1')
            ->assertCanSeeTableRecords([$revenueCat])
            ->assertCanNotSeeTableRecords([$stripe]);
    }

    public function test_a_stripe_delivery_shows_the_billing_rows_that_carry_its_event_id(): void
    {
        $delivery = $this->delivery('evt_stripe_join', 'invoice.paid');
        $this->event(BillingEventType::ENTITLEMENT_APPLIED, [
            'external_id' => 'evt_stripe_join',
            'reason' => 'joined_row',
        ]);
        $this->event(BillingEventType::ENTITLEMENT_APPLIED, [
            'external_id' => 'evt_unrelated',
            'reason' => 'unrelated_row',
        ]);
        // A checkout row keys on the Stripe session, so it never joins a delivery.
        $this->event(BillingEventType::CHECKOUT_STARTED, ['external_id' => 'cs_123', 'reason' => 'checkout_row']);

        Livewire::actingAs($this->admin())
            ->test(ViewWebhookDelivery::class, ['record' => $delivery->getKey()])
            ->assertSee('evt_stripe_join')
            ->assertSee('entitlement_applied')
            ->assertSee('joined_row')
            ->assertDontSee('unrelated_row')
            ->assertDontSee('checkout_row')
            ->assertSee(__('magic-starter::admin_misc.webhook_deliveries.view.billing_events_note'));
    }

    public function test_a_revenuecat_delivery_joins_on_the_id_without_its_claim_prefix(): void
    {
        $delivery = $this->delivery(SyncRevenueCatEntitlement::CLAIM_PREFIX . 'rc_join', 'RENEWAL');
        $this->event(BillingEventType::ENTITLEMENT_APPLIED, ['external_id' => 'rc_join', 'reason' => 'joined_row']);
        $this->event(BillingEventType::ENTITLEMENT_APPLIED, [
            'external_id' => 'rc:rc_join',
            'reason' => 'prefixed_row',
        ]);

        Livewire::actingAs($this->admin())
            ->test(ViewWebhookDelivery::class, ['record' => $delivery->getKey()])
            ->assertSee('RevenueCat')
            ->assertSee('rc_join')
            ->assertSee('joined_row')
            ->assertDontSee('prefixed_row');
    }

    public function test_a_delivery_that_changed_nothing_says_so(): void
    {
        $delivery = $this->delivery('evt_quiet', 'customer.updated');

        Livewire::actingAs($this->admin())
            ->test(ViewWebhookDelivery::class, ['record' => $delivery->getKey()])
            ->assertSee(__('magic-starter::admin_misc.webhook_deliveries.view.no_billing_events'));
    }

    public function test_every_label_exists_in_every_shipped_locale(): void
    {
        foreach (['en', 'tr'] as $locale) {
            app()->setLocale($locale);

            foreach ([BillingEventResource::class, WebhookDeliveryResource::class] as $resource) {
                $this->assertNotSame('', $resource::getNavigationLabel());
                $this->assertStringNotContainsString('admin_misc', $resource::getNavigationLabel(), "[{$locale}]");
                $this->assertStringNotContainsString('admin_misc', $resource::getModelLabel(), "[{$locale}]");
                $this->assertStringNotContainsString('admin_misc', $resource::getPluralModelLabel(), "[{$locale}]");
            }
        }
    }

    private function admin(string $email = self::ADMIN_EMAIL): ConcreteAdminUser
    {
        $user = ConcreteAdminUser::query()->where('email', $email)->first();

        if ($user instanceof ConcreteAdminUser) {
            return $user;
        }

        $user = new ConcreteAdminUser;
        $user->forceFill([
            'name' => 'Ops',
            'email' => $email,
            'email_verified_at' => now(),
            'password' => 'secret',
        ])->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(BillingEventType $type, array $attributes = []): BillingEvent
    {
        return BillingEvent::query()->create([
            'type' => $type,
            'source' => BillingSource::WEBHOOK,
            'provider' => BillingProvider::STRIPE,
            ...$attributes,
        ]);
    }

    private function delivery(string $eventId, string $type): ProcessedWebhookEvent
    {
        return ProcessedWebhookEvent::query()->create([
            'event_id' => $eventId,
            'type' => $type,
            'processed_at' => now(),
        ]);
    }
}
