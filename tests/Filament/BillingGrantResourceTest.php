<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Panel;
use FlutterSdk\MagicStarter\Actions\AdministerBilling;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\GrantEndReason;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Actions\GrantPlanAction;
use FlutterSdk\MagicStarter\Filament\AdminNavigationGroup;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Resources\BillingGrants\BillingGrantResource;
use FlutterSdk\MagicStarter\Filament\Resources\BillingGrants\Pages\ListBillingGrants;
use FlutterSdk\MagicStarter\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\BillingGrant;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Support\BillingAdministrationRefused;
use FlutterSdk\MagicStarter\Tests\Fixtures\BillableTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\RecordingBillingAdministration;
use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Manual plans: the list of every grant an operator made, and the "Grant a plan"
 * action that makes one from the billing lists without opening the customer's
 * page first (an enterprise contract, a partner, a comp).
 *
 * The action goes through AdministersBilling exactly as the Billing tab's grant
 * does, so most cases bind {@see RecordingBillingAdministration} and assert what
 * reaches the contract; one runs the real AdministerBilling end to end.
 */
class BillingGrantResourceTest extends FilamentTestCase
{
    private const ADMIN_EMAIL = 'ops@example.com';

    private RecordingBillingAdministration $billing;

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
            'magic-starter.admin.emails' => [self::ADMIN_EMAIL, 'support@example.com'],
            'magic-starter.admin.billing_emails' => [],
            'cashier.secret' => 'sk_test_billing_grant_resource',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useTeamModel(BillableTeam::class);
        Cashier::useCustomerModel(BillableTeam::class);
        Cashier::useSubscriptionModel(Subscription::class);

        $this->billing = new RecordingBillingAdministration;
        $this->app->instance(AdministersBilling::class, $this->billing);
    }

    protected function tearDown(): void
    {
        Cashier::useCustomerModel('App\\Models\\User');
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_the_list_is_mounted_behind_billing_in_the_billing_group(): void
    {
        config()->set('magic-starter.features', [Features::teams()]);
        $off = Panel::make()->id('grants-off')->plugin(MagicStarterPlugin::make());

        config()->set('magic-starter.features', [Features::teams(), Features::billing()]);
        $on = Panel::make()->id('grants-on')->plugin(MagicStarterPlugin::make());

        $this->assertNotContains(BillingGrantResource::class, $off->getResources());
        $this->assertContains(BillingGrantResource::class, $on->getResources());
        $this->assertSame(AdminNavigationGroup::Billing, BillingGrantResource::getNavigationGroup());
        $this->assertSame(15, BillingGrantResource::getNavigationSort());

        $this->assertSame('Manual plans', BillingGrantResource::getNavigationLabel());
        app()->setLocale('tr');
        $this->assertSame('Elle verilen planlar', BillingGrantResource::getNavigationLabel());
    }

    public function test_the_list_names_the_customer_the_plan_the_reason_and_whether_it_is_open(): void
    {
        $open = $this->grantRow($this->team('Acme'), 'enterprise', 'Contract #42');
        $ended = $this->grantRow($this->team('Globex'), 'pro', 'Conference comp');
        $ended->forceFill(['ended_at' => now(), 'end_reason' => GrantEndReason::REVOKED])->save();

        Livewire::actingAs($this->admin())
            ->test(ListBillingGrants::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$open, $ended])
            ->assertSee('Acme')
            ->assertSee('Globex')
            ->assertSee('Enterprise')
            ->assertSee('Contract #42')
            ->assertSee('Open')
            ->assertSee('Revoked')
            ->assertActionDoesNotExist('create');
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function lists(): array
    {
        return [
            'manual plans' => [ListBillingGrants::class],
            'subscriptions' => [ListSubscriptions::class],
        ];
    }

    /**
     * @param  class-string  $page
     */
    #[DataProvider('lists')]
    public function test_grant_a_plan_hands_the_chosen_customer_to_the_contract(string $page): void
    {
        Event::fake([AdminActionPerformed::class]);
        $admin = $this->admin();
        $this->team('Acme');
        $globex = $this->team('Globex');
        $expiresAt = CarbonImmutable::now()->addYear()->toDateString();

        Livewire::actingAs($admin)
            ->test($page)
            ->callAction('grantPlan', data: [
                'billable' => $globex->getKey(),
                'plan' => 'enterprise',
                'reason' => 'Contract #42',
                'expires_at' => $expiresAt,
            ])
            ->assertHasNoActionErrors();

        $writes = $this->billing->writes();
        $this->assertCount(1, $writes);
        $this->assertSame('grant', $writes[0]['method']);
        $this->assertTrue($writes[0]['billable']->is($globex));
        $this->assertTrue($writes[0]['actor']->is($admin));
        $this->assertSame('enterprise', $writes[0]['arguments']['plan']);
        $this->assertSame('Contract #42', $writes[0]['arguments']['reason']);
        $this->assertInstanceOf(CarbonInterface::class, $writes[0]['arguments']['expiresAt']);
        $this->assertSame($expiresAt, $writes[0]['arguments']['expiresAt']->toDateString());
        $this->assertSame('23:59:59', $writes[0]['arguments']['expiresAt']->format('H:i:s'));

        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $performed): bool => $performed->action === 'billing.grant_added'
                && $performed->subject?->is($globex) === true,
        );
        FilamentNotification::assertNotified((string) __('magic-starter::admin_billing.actions.grant.success'));
    }

    public function test_a_grant_with_no_expiry_reaches_the_contract_as_open_ended(): void
    {
        $acme = $this->team('Acme');

        Livewire::actingAs($this->admin())
            ->test(ListBillingGrants::class)
            ->callAction('grantPlan', data: [
                'billable' => $acme->getKey(),
                'plan' => 'pro',
                'reason' => 'Partner',
            ])
            ->assertHasNoActionErrors();

        $this->assertNull($this->billing->writes()[0]['arguments']['expiresAt']);
    }

    public function test_the_customer_plan_and_reason_are_required(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ListBillingGrants::class)
            ->callAction('grantPlan', data: [])
            ->assertHasActionErrors([
                'billable' => 'required',
                'plan' => 'required',
                'reason' => 'required',
            ]);

        $this->assertSame([], $this->billing->writes());
    }

    public function test_a_refusal_from_the_contract_is_a_danger_notification_without_the_admin_action(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $this->billing->refusal = new BillingAdministrationRefused('paid_rail_active');
        $acme = $this->team('Acme');

        Livewire::actingAs($this->admin())
            ->test(ListBillingGrants::class)
            ->callAction('grantPlan', data: [
                'billable' => $acme->getKey(),
                'plan' => 'pro',
                'reason' => 'Partner',
            ]);

        FilamentNotification::assertNotified(
            FilamentNotification::make()->danger()->title(
                (string) __('magic-starter::admin_billing.refusals.paid_rail_active'),
            ),
        );
        Event::assertNotDispatched(AdminActionPerformed::class);
    }

    #[DataProvider('lists')]
    public function test_an_admin_outside_the_billing_list_does_not_see_it(string $page): void
    {
        config()->set('magic-starter.admin.billing_emails', [self::ADMIN_EMAIL]);

        // Filament refuses to mount or call a hidden action, so hidden is the gate.
        Livewire::actingAs($this->admin('support@example.com'))
            ->test($page)
            ->assertActionHidden('grantPlan');

        Livewire::actingAs($this->admin())
            ->test($page)
            ->assertActionVisible('grantPlan');

        $this->assertSame([], $this->billing->writes());
    }

    public function test_the_customer_search_matches_any_case_and_labels_the_match_with_an_email(): void
    {
        $acme = $this->team('Acme Industries');
        $this->team('Globex');
        $label = 'Acme Industries · ' . self::ADMIN_EMAIL;

        $this->assertSame([$acme->getKey() => $label], GrantPlanAction::searchBillables('acme'));
        $this->assertSame([$acme->getKey() => $label], GrantPlanAction::searchBillables('INDUS'));
        $this->assertSame([], GrantPlanAction::searchBillables('initech'));
        $this->assertSame($label, GrantPlanAction::billableLabel($acme->getKey()));
        $this->assertNull(GrantPlanAction::billableLabel(999999));
    }

    public function test_two_teams_that_share_a_name_are_told_apart_by_their_owner(): void
    {
        $ours = $this->team('Acme');
        $theirs = $this->team('Acme', owner: $this->admin('support@example.com'));

        $this->assertSame(
            [
                $ours->getKey() => 'Acme · ' . self::ADMIN_EMAIL,
                $theirs->getKey() => 'Acme · support@example.com',
            ],
            GrantPlanAction::searchBillables('acme'),
        );
        $this->assertSame([$theirs->getKey() => 'Acme · support@example.com'], GrantPlanAction::searchBillables('support@'));
    }

    public function test_a_team_is_found_through_any_member_not_only_its_owner(): void
    {
        // An operator searches for the person who wrote in, who is often a member
        // rather than the owner; the label still names the owner.
        $acme = $this->team('Acme');
        $member = $this->admin('ayse@example.com');
        $member->forceFill(['name' => 'Ayşe Kaya'])->save();
        $acme->users()->attach($member->getKey(), ['role' => 'member']);
        $this->team('Globex');

        $label = [$acme->getKey() => 'Acme · ' . self::ADMIN_EMAIL];

        $this->assertSame($label, GrantPlanAction::searchBillables('ayse@'));
        $this->assertSame($label, GrantPlanAction::searchBillables('Kaya'));
    }

    public function test_a_user_billable_is_searched_and_labelled_by_its_own_email(): void
    {
        config()->set('magic-starter.billing.billable', 'user');
        $support = $this->admin('support@example.com');

        $this->assertSame(
            [$support->getKey() => 'Ops · support@example.com'],
            GrantPlanAction::searchBillables('support@'),
        );
    }

    public function test_a_grant_from_the_list_leaves_a_manual_entitlement_and_an_open_row(): void
    {
        $this->app->forgetInstance(AdministersBilling::class);
        $this->app->bind(AdministersBilling::class, AdministerBilling::class);
        $acme = $this->team('Acme');

        $list = Livewire::actingAs($this->admin())
            ->test(ListBillingGrants::class)
            ->callAction('grantPlan', data: [
                'billable' => $acme->getKey(),
                'plan' => 'enterprise',
                'reason' => 'Contract #42',
            ])
            ->assertHasNoActionErrors();

        $acme->refresh();
        $this->assertSame(BillingProvider::MANUAL->value, $acme->getAttribute('plan_provider'));
        $this->assertSame('enterprise', $acme->getAttribute('plan'));

        $grant = BillingGrant::forBillable($acme)->open()->sole();
        $list->loadTable()->assertCanSeeTableRecords([$grant]);
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

    private function team(string $name, ?ConcreteAdminUser $owner = null): BillableTeam
    {
        $team = new BillableTeam;
        $team->forceFill([
            'user_id' => ($owner ?? $this->admin())->getKey(),
            'name' => $name,
            'personal_team' => false,
        ])->save();

        return $team;
    }

    private function grantRow(BillableTeam $team, string $plan, string $reason): BillingGrant
    {
        return BillingGrant::query()->create([
            'billable_type' => $team->getMorphClass(),
            'billable_id' => (string) $team->getKey(),
            'plan' => $plan,
            'reason' => $reason,
            'granted_by' => $this->admin()->getKey(),
        ]);
    }
}
