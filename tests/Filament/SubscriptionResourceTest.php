<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Resources\Billing\RelationManagers\BillingRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use FlutterSdk\MagicStarter\Filament\Resources\Subscriptions\SubscriptionResource;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\TeamResource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Tests\Fixtures\BillableTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;
use Livewire\Livewire;

/**
 * The subscriptions list: one row per customer any billing rail has touched.
 *
 * Every rail ends in the same columns on the billable (`plan`, `plan_status`,
 * `plan_provider` and the rest), so the list reads those, and a store purchase
 * or a manual plan is as visible as a card subscription.
 */
class SubscriptionResourceTest extends FilamentTestCase
{
    private const ADMIN_EMAIL = 'ops@example.com';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'magic-starter.features' => [
                Features::teams(),
                Features::billing(),
            ],
            'magic-starter.use_uuids' => false,
            'magic-starter.billing.billable' => 'team',
            'magic-starter.billing.tier_order' => ['free', 'pro', 'enterprise'],
            'magic-starter.billing.tiers' => [
                'pro' => ['name' => 'Pro'],
                'enterprise' => ['name' => 'Enterprise'],
            ],
            'magic-starter.admin.emails' => [self::ADMIN_EMAIL],
            'magic-starter.admin.billing_emails' => [],
            'cashier.secret' => 'sk_test_subscription_resource',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useTeamModel(BillableTeam::class);
        Cashier::useCustomerModel(BillableTeam::class);
        Cashier::useSubscriptionModel(Subscription::class);
    }

    protected function tearDown(): void
    {
        Cashier::useCustomerModel('App\\Models\\User');
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_the_list_reads_the_billable_so_every_rail_shows(): void
    {
        $this->assertSame(BillableTeam::class, SubscriptionResource::getModel());
    }

    public function test_the_list_holds_every_customer_a_rail_has_touched(): void
    {
        $lapsedStore = $this->team('Lapsed store', [
            'plan' => null,
            'plan_status' => PlanStatus::CANCELED->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_product_id' => 'com.example.app.pro_monthly',
        ]);
        $card = $this->team('Card payer', [
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
        ]);
        $play = $this->team('Play payer', [
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::PLAY_STORE->value,
        ]);
        $comp = $this->team('Comped', [
            'plan' => 'enterprise',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::MANUAL->value,
        ]);
        $legacy = $this->team('Predates rails', ['plan' => 'pro', 'plan_status' => PlanStatus::ACTIVE->value]);
        $dropped = $this->team('Dropped webhook');
        $this->cashierRow($dropped);

        $free = $this->team('Never paid');
        $floor = $this->team('On the floor', ['plan' => 'free', 'plan_status' => PlanStatus::NONE->value]);
        $reset = $this->team('Reset', ['plan_provider' => BillingProvider::NONE->value]);

        Livewire::actingAs($this->admin())
            ->test(ListSubscriptions::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$lapsedStore, $card, $play, $comp, $legacy, $dropped])
            ->assertCanNotSeeTableRecords([$free, $floor, $reset])
            // A billable no rail has written is named, not left blank.
            ->assertTableColumnFormattedStateSet('plan_provider', BillingProvider::NONE->label(), $dropped)
            ->assertTableColumnFormattedStateSet('plan_status', PlanStatus::NONE->label(), $dropped)
            ->assertTableColumnFormattedStateSet('plan_provider', BillingProvider::NONE->label(), $legacy);
    }

    public function test_a_row_names_the_rail_the_status_and_the_store_product(): void
    {
        $lapsed = $this->team('Lapsed store', [
            'plan' => null,
            'plan_status' => PlanStatus::CANCELED->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
            'plan_product_id' => 'com.example.app.pro_monthly',
        ]);

        // The labels also sit in the filter options, so a page-wide assertSee
        // would pass with no row at all: ask the row's own cells.
        Livewire::actingAs($this->admin())
            ->test(ListSubscriptions::class)
            ->loadTable()
            ->assertTableColumnFormattedStateSet('name', 'Lapsed store', $lapsed)
            ->assertTableColumnFormattedStateSet('plan_provider', BillingProvider::APP_STORE->label(), $lapsed)
            ->assertTableColumnFormattedStateSet('plan_status', PlanStatus::CANCELED->label(), $lapsed)
            ->assertTableColumnFormattedStateSet('plan_product_id', 'com.example.app.pro_monthly', $lapsed);
    }

    public function test_the_list_filters_by_rail_status_and_plan_and_searches_by_name(): void
    {
        $store = $this->team('Store payer', [
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::APP_STORE->value,
        ]);
        $card = $this->team('Card payer', [
            'plan' => 'enterprise',
            'plan_status' => PlanStatus::PAST_DUE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
        ]);

        $list = Livewire::actingAs($this->admin())->test(ListSubscriptions::class)->loadTable();

        // "Not billed" and "no plan" are the absence of a value, which an
        // equality filter cannot match, so neither is offered.
        $filters = $list->instance()->getTable();
        $this->assertArrayNotHasKey(BillingProvider::NONE->value, $filters->getFilter('plan_provider')->getOptions());
        $this->assertArrayNotHasKey(PlanStatus::NONE->value, $filters->getFilter('plan_status')->getOptions());

        $list->filterTable('plan_provider', BillingProvider::APP_STORE->value)
            ->assertCanSeeTableRecords([$store])
            ->assertCanNotSeeTableRecords([$card])
            ->removeTableFilters();

        $list->filterTable('plan_status', PlanStatus::PAST_DUE->value)
            ->assertCanSeeTableRecords([$card])
            ->assertCanNotSeeTableRecords([$store])
            ->removeTableFilters();

        $list->filterTable('plan', 'pro')
            ->assertCanSeeTableRecords([$store])
            ->assertCanNotSeeTableRecords([$card])
            ->removeTableFilters();

        $list->searchTable('Card')
            ->assertCanSeeTableRecords([$card])
            ->assertCanNotSeeTableRecords([$store]);
    }

    /**
     * A model may cast `plan` or `plan_provider` to an enum of its own; the
     * columns say the same thing for the cast value and for the raw word.
     */
    public function test_the_columns_read_the_stored_word_whether_or_not_the_model_casts_it(): void
    {
        $table = Livewire::actingAs($this->admin())
            ->test(ListSubscriptions::class)
            ->loadTable()
            ->instance()
            ->getTable();
        $status = $table->getColumn('plan_status');
        $provider = $table->getColumn('plan_provider');
        $plan = $table->getColumn('plan');

        $statuses = [
            [PlanStatus::ACTIVE, 'success'],
            [PlanStatus::TRIALING, 'success'],
            [PlanStatus::PAST_DUE, 'warning'],
            [PlanStatus::GRACE, 'warning'],
            [PlanStatus::CANCELED, 'gray'],
            [PlanStatus::EXPIRED, 'gray'],
        ];

        foreach ($statuses as [$case, $color]) {
            foreach ([$case->value, $case] as $stored) {
                $this->assertSame($color, $status->getColor($stored), $case->value);
                $this->assertSame($case->label(), $status->formatState($stored), $case->value);
            }
        }

        foreach ([BillingProvider::STRIPE, BillingProvider::APP_STORE, BillingProvider::MANUAL] as $case) {
            foreach ([$case->value, $case] as $stored) {
                $this->assertSame($case->label(), $provider->formatState($stored), $case->value);
            }
        }

        $this->assertSame('info', $provider->getColor(BillingProvider::STRIPE->value));
        $this->assertSame('primary', $provider->getColor(BillingProvider::PLAY_STORE));
        $this->assertSame('warning', $provider->getColor(BillingProvider::MANUAL->value));
        $this->assertSame('Pro', $plan->formatState('pro'));
        $this->assertSame('Pro', $plan->formatState(CastTier::Pro));
        $this->assertSame('retired', $plan->formatState('retired'));
    }

    public function test_a_row_opens_the_billing_tab_of_its_customer(): void
    {
        $team = $this->team('Store payer', ['plan_provider' => BillingProvider::APP_STORE->value]);
        $tab = array_search(BillingRelationManager::class, TeamResource::getRelations(), true);

        $this->assertIsInt($tab);

        Livewire::actingAs($this->admin())
            ->test(ListSubscriptions::class)
            ->loadTable()
            ->assertTableActionHasUrl(
                'manage',
                TeamResource::getUrl('edit', ['record' => $team, 'relation' => (string) $tab]),
                $team,
            )
            ->assertTableActionDoesNotExist('delete')
            ->assertTableActionDoesNotExist('edit');
    }

    public function test_the_list_keeps_its_header_actions(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ListSubscriptions::class)
            ->assertActionExists('grantPlan')
            ->assertActionExists('reconcile')
            ->assertActionDoesNotExist('create');

        $this->assertSame(['index'], array_keys(SubscriptionResource::getPages()));
    }

    public function test_every_label_exists_in_every_shipped_locale(): void
    {
        $keys = ['billable', 'email', 'plan', 'status', 'provider', 'product', 'period_end'];
        $filters = ['plan', 'status', 'provider'];

        foreach (['en', 'tr'] as $locale) {
            app()->setLocale($locale);

            foreach ($keys as $key) {
                $label = __("magic-starter::admin_misc.subscriptions.columns.{$key}");
                $this->assertStringNotContainsString('admin_misc', $label, "[{$locale}] columns.{$key}");
            }

            foreach ($filters as $key) {
                $label = __("magic-starter::admin_misc.subscriptions.filters.{$key}");
                $this->assertStringNotContainsString('admin_misc', $label, "[{$locale}] filters.{$key}");
            }

            $this->assertStringNotContainsString(
                'admin_misc',
                __('magic-starter::admin_misc.subscriptions.actions.manage'),
                "[{$locale}] actions.manage",
            );
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
     * @param  array<string, mixed>  $entitlement  the billable's `plan*` columns
     */
    private function team(string $name, array $entitlement = []): BillableTeam
    {
        $team = new BillableTeam;
        $team->forceFill([
            'user_id' => $this->admin()->getKey(),
            'name' => $name,
            'personal_team' => false,
            ...$entitlement,
        ])->save();

        return $team;
    }

    private function cashierRow(BillableTeam $team): Subscription
    {
        return Subscription::query()->create([
            $team->getForeignKey() => $team->getKey(),
            'type' => 'default',
            'stripe_id' => 'sub_' . $team->getKey(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ]);
    }
}

/**
 * A tier enum of the kind an application casts `plan` to.
 */
enum CastTier: string
{
    case Pro = 'pro';
}
