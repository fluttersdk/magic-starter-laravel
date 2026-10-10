<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Panel;
use FlutterSdk\MagicStarter\Actions\AdministerBilling;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingProvider;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\Enums\PlanStatus;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\MagicStarterPlugin;
use FlutterSdk\MagicStarter\Filament\Resources\Billing\RelationManagers\BillingRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\BillingEvents\BillingEventResource;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\Pages\EditTeam;
use FlutterSdk\MagicStarter\Filament\Resources\Teams\TeamResource;
use FlutterSdk\MagicStarter\Filament\Resources\Users\UserResource;
use FlutterSdk\MagicStarter\Filament\Support\BillingAuthorization;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Models\BillingGrant;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Support\BillingAdministrationRefused;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Support\StripeHttpStub;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\Exception\ApiConnectionException;
use Throwable;

/**
 * The Billing tab on a billable's edit page: its history, its summary, and the
 * eight operator actions that move money or plans through AdministersBilling.
 *
 * Most cases bind {@see RecordingBillingAdministration}, so what is asserted is
 * the panel's side: who sees which action, what reaches the contract, and what
 * the operator is told. The end-to-end cases run the real AdministerBilling, with
 * Stripe answered by {@see StripeHttpStub}.
 *
 * The application bills TEAMS here, on integer keys, which is the shape where a
 * key bound as an integer against the string `billable_id` column misses on
 * PostgreSQL.
 */
class BillingRelationManagerTest extends FilamentTestCase
{
    private const ADMIN_EMAIL = 'ops@example.com';

    /**
     * The eight header actions, by name.
     */
    private const ACTIONS = [
        'grant',
        'revoke',
        'extendTrial',
        'endTrial',
        'cancelSubscription',
        'resumeSubscription',
        'refund',
        'sync',
    ];

    private RecordingBillingAdministration $billing;

    private ?StripeHttpStub $stripe = null;

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
            'magic-starter.billing.tier_order' => ['free', 'pro', 'business'],
            'magic-starter.billing.tiers' => [
                'pro' => ['name' => 'Pro'],
            ],
            'magic-starter.admin.emails' => [self::ADMIN_EMAIL],
            'magic-starter.admin.billing_emails' => [],
            'cashier.secret' => 'sk_test_billing_relation_manager',
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
        if ($this->stripe !== null) {
            StripeHttpStub::uninstall();
        }

        Cashier::useCustomerModel('App\\Models\\User');
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);
        MagicStarter::reset();

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Attachment
    // -------------------------------------------------------------------------

    public function test_the_tab_sits_on_the_resource_of_the_subject_the_application_bills(): void
    {
        $this->assertContains(BillingRelationManager::class, TeamResource::getRelations());
        $this->assertNotContains(BillingRelationManager::class, UserResource::getRelations());

        config()->set('magic-starter.billing.billable', 'user');
        $this->assertContains(BillingRelationManager::class, UserResource::getRelations());
        $this->assertNotContains(BillingRelationManager::class, TeamResource::getRelations());

        config()->set('magic-starter.features', [Features::teams()]);
        $this->assertNotContains(BillingRelationManager::class, UserResource::getRelations());
        $this->assertNotContains(BillingRelationManager::class, TeamResource::getRelations());
    }

    // -------------------------------------------------------------------------
    // History and summary
    // -------------------------------------------------------------------------

    public function test_the_history_lists_only_the_owners_rows_newest_first_with_a_string_bound_key(): void
    {
        $team = $this->team();
        $other = $this->team('Other');
        $older = $this->event($team, BillingEventType::ENTITLEMENT_APPLIED, now()->subDay());
        $newer = $this->event($team, BillingEventType::GRANT_ADDED, now());
        $theirs = $this->event($other, BillingEventType::GRANT_ADDED, now());
        // Same key, different subject: a user row must not leak into a team's tab.
        $sameKey = BillingEvent::query()->create([
            'type' => BillingEventType::ENTITLEMENT_APPLIED,
            'source' => BillingSource::WEBHOOK,
            'billable_type' => (new ConcreteAdminUser)->getMorphClass(),
            'billable_id' => (string) $team->getKey(),
        ]);

        $this->assertIsInt($team->getKey());

        $tab = $this->tab($team)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
            ->assertCanNotSeeTableRecords([$theirs, $sameKey]);

        $bindings = $tab->instance()->getRelationship()->getQuery()->getQuery()->getBindings();
        $this->assertContains((string) $team->getKey(), $bindings);
        $this->assertNotContains($team->getKey(), $bindings, 'The key is bound as an integer.');
    }

    public function test_a_row_links_to_the_billing_event_resource_when_the_panel_mounts_it(): void
    {
        $team = $this->team();
        $row = $this->event($team, BillingEventType::GRANT_ADDED, now());

        $this->tab($team)->assertTableActionHasUrl(
            'view',
            BillingEventResource::getUrl('view', ['record' => $row]),
            $row,
        );
    }

    public function test_a_row_offers_no_link_when_the_panel_drops_the_billing_event_resource(): void
    {
        $team = $this->team();
        $row = $this->event($team, BillingEventType::GRANT_ADDED, now());

        Filament::setCurrentPanel(
            Panel::make()->id('no-events')->plugin(MagicStarterPlugin::make()->withoutResources(['billing_events'])),
        );

        $this->tab($team)->assertTableActionHidden('view', $row);
    }

    public function test_the_summary_reads_the_record_the_open_grant_and_the_local_subscription(): void
    {
        $team = $this->team(attributes: [
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::MANUAL->value,
            'plan_current_period_end' => CarbonImmutable::parse('2031-01-15 00:00:00'),
        ]);
        $this->grantRow($team, CarbonImmutable::parse('2031-02-20 00:00:00'));
        $this->subscription($team, 'trialing', trialEndsAt: CarbonImmutable::parse('2031-03-25 00:00:00'));

        $this->tab($team)
            ->assertSee(__('magic-starter::admin_billing.summary.heading'))
            ->assertSee('pro')
            ->assertSee(PlanStatus::ACTIVE->label())
            ->assertSee(BillingProvider::MANUAL->label())
            ->assertSee('Jan 15, 2031')
            ->assertSee('Feb 20, 2031')
            ->assertSee('Mar 25, 2031');
    }

    // -------------------------------------------------------------------------
    // Each action, through the contract
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function actions(): array
    {
        return [
            'grant' => ['grant', 'grant', 'billing.grant_added'],
            'revoke' => ['revoke', 'revoke', 'billing.grant_revoked'],
            'extend trial' => ['extendTrial', 'extendTrial', 'billing.trial_extended'],
            'end trial' => ['endTrial', 'endTrial', 'billing.trial_ended'],
            'cancel' => ['cancelSubscription', 'cancel', 'billing.subscription_cancelled'],
            'resume' => ['resumeSubscription', 'resume', 'billing.subscription_resumed'],
            'refund' => ['refund', 'refundLastInvoice', 'billing.invoice_refunded'],
            'sync' => ['sync', 'sync', 'billing.synced'],
        ];
    }

    #[DataProvider('actions')]
    public function test_an_action_calls_the_contract_records_the_admin_action_and_notifies(
        string $action,
        string $method,
        string $event,
    ): void {
        Event::fake([AdminActionPerformed::class]);
        $admin = $this->admin();
        $team = $this->arrange($action);

        $this->tab($team, $admin)
            ->assertTableActionVisible($action)
            ->callTableAction($action, data: $this->dataFor($action))
            ->assertHasNoTableActionErrors();

        // 1. The contract was asked, once, by the signed-in operator about this team.
        $calls = $this->billing->writes();
        $this->assertCount(1, $calls, "[{$action}] did not reach the contract exactly once.");
        $this->assertSame($method, $calls[0]['method']);
        $this->assertTrue($admin->is($calls[0]['actor']));
        $this->assertTrue($team->is($calls[0]['billable']));
        $this->assertArguments($action, $calls[0]['arguments']);

        // 2. The audit trail, under the stable event name.
        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $performed): bool => $performed->action === $event
                && $admin->is($performed->actor)
                && $team->is($performed->subject),
        );

        // 3. The operator is told it worked.
        FilamentNotification::assertNotified(
            (string) __('magic-starter::admin_billing.actions.' . Str::snake($action) . '.success'),
        );
    }

    public function test_a_refusal_from_the_contract_is_a_danger_notification_without_the_admin_action(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $team = $this->arrange('endTrial');
        $this->billing->refusal = new BillingAdministrationRefused('not_trialing');

        $this->tab($team)->callTableAction('endTrial');

        FilamentNotification::assertNotified(
            FilamentNotification::make()->danger()->title(
                (string) __('magic-starter::admin_billing.refusals.not_trialing'),
            ),
        );
        Event::assertNotDispatched(AdminActionPerformed::class);
    }

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    #[DataProvider('actions')]
    public function test_an_admin_outside_the_billing_list_neither_sees_nor_runs_an_action(string $action): void
    {
        Event::fake([AdminActionPerformed::class]);
        config()->set('magic-starter.admin.billing_emails', ['Money@Example.com']);
        $team = $this->arrange($action);

        $tab = $this->tab($team)->assertTableActionHidden($action);

        // 1. Filament refuses to mount it, so not even the refund's read runs.
        $tab->mountTableAction($action);
        $this->assertSame([], $tab->instance()->mountedActions);
        $this->assertSame(0, $this->billing->refundablePaymentReads);

        // 2. A crafted payload that skips the mount is refused at the call.
        $tab->set('mountedActions', [
            [
                'name' => $action,
                'arguments' => [],
                'context' => ['table' => true],
                'data' => $this->dataFor($action),
            ],
        ])->call('callMountedAction');

        $this->assertSame([], $this->billing->writes(), "A hidden [{$action}] still reached the contract.");
        Event::assertNotDispatched(AdminActionPerformed::class);

        // The same state shows the action to a billing admin, matched case-insensitively.
        $this->tab($team, $this->admin('money@example.com'))->assertTableActionVisible($action);
    }

    public function test_an_admin_outside_the_billing_list_sees_none_of_the_eight_actions(): void
    {
        config()->set('magic-starter.admin.billing_emails', ['money@example.com']);
        $team = $this->team(attributes: [
            'stripe_id' => 'cus_team',
            'plan_provider' => BillingProvider::MANUAL->value,
        ]);
        $this->grantRow($team, null);
        $this->subscription($team, 'trialing', trialEndsAt: CarbonImmutable::now()->addDays(3));

        $tab = $this->tab($team);

        foreach (self::ACTIONS as $action) {
            $tab->assertTableActionHidden($action);
        }
    }

    public function test_an_empty_billing_list_lets_every_panel_admin_administer_billing(): void
    {
        $this->assertTrue(BillingAuthorization::allows($this->admin()));

        config()->set('magic-starter.admin.billing_emails', [' MONEY@example.com ']);

        $this->assertTrue(BillingAuthorization::allows($this->admin('money@example.com')));
        $this->assertFalse(BillingAuthorization::allows($this->admin()));
    }

    public function test_the_plugin_callback_replaces_the_billing_list(): void
    {
        $plugin = MagicStarterPlugin::get();

        config()->set('magic-starter.admin.billing_emails', ['money@example.com']);
        $plugin->authorizeBillingUsing(
            static fn (Authenticatable $admin): bool => $admin->getAttribute('email') === self::ADMIN_EMAIL,
        );

        $this->assertTrue(BillingAuthorization::allows($this->admin()));
        $this->assertFalse(BillingAuthorization::allows($this->admin('money@example.com')));
        $this->tab($this->arrange('sync'))->assertTableActionVisible('sync');

        // Only a literal true admits.
        config()->set('magic-starter.admin.billing_emails', []);
        $plugin->authorizeBillingUsing(static fn (): string => 'yes');

        $this->assertFalse(BillingAuthorization::allows($this->admin()));
        $this->tab($this->arrange('sync'))->assertTableActionHidden('sync');
    }

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    public function test_grant_is_hidden_while_a_paid_rail_grants(): void
    {
        $team = $this->team();

        $this->tab($team)->assertTableActionVisible('grant');

        $this->billing->paidRailGrants = true;

        $this->tab($team)->assertTableActionHidden('grant');
    }

    public function test_revoke_is_offered_for_an_open_manual_grant_or_a_store_record_only(): void
    {
        $manual = $this->team(attributes: ['plan_provider' => BillingProvider::MANUAL->value]);
        $this->tab($manual)->assertTableActionHidden('revoke');

        $this->grantRow($manual, null);
        $this->tab($manual)->assertTableActionVisible('revoke');

        // The contract reads the store before it revokes, so without the rail
        // there is nothing it could do.
        $store = $this->team('Store', ['plan_provider' => BillingProvider::APP_STORE->value]);
        $this->tab($store)->assertTableActionHidden('revoke');

        config()->set('magic-starter.billing.revenuecat.secret_api_key', 'sk_test_revenuecat_secret');
        $this->tab($store)->assertTableActionVisible('revoke');

        $stripe = $this->team('Stripe', ['plan_provider' => BillingProvider::STRIPE->value]);
        $this->grantRow($stripe, null);
        $this->tab($stripe)->assertTableActionHidden('revoke');
    }

    public function test_the_trial_actions_follow_the_local_trial(): void
    {
        $team = $this->team();
        $subscription = $this->subscription($team, 'active');

        $this->tab($team)
            ->assertTableActionHidden('extendTrial')
            ->assertTableActionHidden('endTrial');

        $subscription->forceFill([
            'stripe_status' => 'trialing',
            'trial_ends_at' => CarbonImmutable::now()->addDays(3),
        ])->save();

        $this->tab($team)
            ->assertTableActionVisible('extendTrial')
            ->assertTableActionVisible('endTrial');
    }

    public function test_the_trial_actions_are_hidden_on_a_cancelled_trial(): void
    {
        $team = $this->team();
        $this->subscription(
            $team,
            'trialing',
            trialEndsAt: CarbonImmutable::now()->addDays(3),
            endsAt: CarbonImmutable::now()->addDays(3),
        );

        $this->tab($team)
            ->assertTableActionHidden('extendTrial')
            ->assertTableActionHidden('endTrial');
    }

    public function test_the_trial_extension_starts_the_day_after_the_current_trial_end(): void
    {
        $team = $this->team();
        $trialEndsAt = CarbonImmutable::now()->addDays(3)->setTime(15, 0);
        $this->subscription($team, 'trialing', trialEndsAt: $trialEndsAt);

        $this->tab($team)
            ->callTableAction('extendTrial', data: ['until' => $trialEndsAt->toDateString()])
            ->assertHasTableActionErrors(['until']);

        $this->assertSame([], $this->billing->writes());

        $this->tab($team)
            ->callTableAction('extendTrial', data: ['until' => $trialEndsAt->addDay()->toDateString()])
            ->assertHasNoTableActionErrors();

        $this->assertCount(1, $this->billing->writes());
    }

    public function test_cancel_and_resume_follow_the_grace_period(): void
    {
        $team = $this->team();
        $this->tab($team)
            ->assertTableActionHidden('cancelSubscription')
            ->assertTableActionHidden('resumeSubscription');

        $subscription = $this->subscription($team, 'active');
        $this->tab($team)
            ->assertTableActionVisible('cancelSubscription')
            ->assertTableActionHidden('resumeSubscription');

        $subscription->forceFill(['ends_at' => CarbonImmutable::now()->addDays(5)])->save();
        $this->tab($team)
            ->assertTableActionHidden('cancelSubscription')
            ->assertTableActionVisible('resumeSubscription');

        $subscription->forceFill(['ends_at' => CarbonImmutable::now()->subDay()])->save();
        $this->tab($team)
            ->assertTableActionHidden('cancelSubscription')
            ->assertTableActionHidden('resumeSubscription');
    }

    public function test_refund_needs_a_stripe_customer_and_a_local_subscription(): void
    {
        $team = $this->team();
        $this->subscription($team, 'active');
        $this->tab($team)->assertTableActionHidden('refund');

        $team->forceFill(['stripe_id' => 'cus_team'])->save();
        $this->tab($team)->assertTableActionVisible('refund');

        $customerOnly = $this->team('Customer only', ['stripe_id' => 'cus_other']);
        $this->tab($customerOnly)->assertTableActionHidden('refund');
    }

    public function test_the_refund_modal_names_the_amount_it_would_return(): void
    {
        $team = $this->arrange('refund');

        $this->tab($team)
            ->mountTableAction('refund')
            ->assertMountedActionModalSee(Cashier::formatAmount(2900, 'usd'))
            ->assertMountedActionModalSee('in_paid');

        $this->assertSame(1, $this->billing->refundablePaymentReads);
    }

    public function test_the_refund_modal_refuses_when_nothing_is_refundable(): void
    {
        $team = $this->arrange('refund');
        $this->billing->refundablePayment = null;

        $this->tab($team)
            ->mountTableAction('refund')
            ->assertMountedActionModalSee(__('magic-starter::admin_billing.refusals.nothing_refundable'))
            ->assertFormFieldHidden('reason', 'mountedActionSchema0');
    }

    public function test_the_refund_modal_says_so_when_stripe_cannot_be_reached(): void
    {
        $team = $this->arrange('refund');
        $this->billing->refundablePaymentFailure = ApiConnectionException::factory('Stripe is down.');

        $this->tab($team)
            ->mountTableAction('refund')
            ->assertMountedActionModalSee(__('magic-starter::admin_billing.refusals.rail_error'));
    }

    /**
     * Sync is offered where it can read something: a local Stripe
     * subscription, or the store rail for a store record or for a record no
     * open grant holds, behind which a store payer may sit.
     */
    public function test_sync_is_offered_where_a_rail_can_be_read(): void
    {
        $subscribed = $this->team('Subscribed');
        $this->subscription($subscribed, 'active');
        $this->tab($subscribed)->assertTableActionVisible('sync');

        $comped = $this->team('Comped', ['plan_provider' => BillingProvider::MANUAL->value]);
        $this->grantRow($comped, null);
        $lapsed = $this->team('Lapsed', ['plan_provider' => BillingProvider::MANUAL->value]);
        $nobody = $this->team();
        $store = $this->team('Store', ['plan_provider' => BillingProvider::APP_STORE->value]);
        $stripeRecord = $this->team('Stripe record', ['plan_provider' => BillingProvider::STRIPE->value]);

        // 1. No store rail and no local subscription: nothing to read.
        foreach ([$comped, $lapsed, $nobody, $store, $stripeRecord] as $team) {
            $this->tab($team)->assertTableActionHidden('sync');
        }

        // 2. With the store rail, anybody no open grant holds may be a store payer.
        config()->set('magic-starter.billing.revenuecat.secret_api_key', 'sk_test_revenuecat_secret');

        foreach ([$lapsed, $nobody, $store] as $team) {
            $this->tab($team)->assertTableActionVisible('sync');
        }

        $this->tab($comped)->assertTableActionHidden('sync');
        $this->tab($stripeRecord)->assertTableActionHidden('sync');
    }

    public function test_the_history_hides_the_billable_column_it_already_belongs_to(): void
    {
        $this->tab($this->team())->assertTableColumnHidden('billable');
    }

    /**
     * The open grant is read once per render, however many actions and
     * summary entries ask for it.
     */
    public function test_the_open_grant_is_read_once_per_render(): void
    {
        $team = $this->team(attributes: ['plan_provider' => BillingProvider::MANUAL->value]);
        $this->grantRow($team, CarbonImmutable::now()->addMonth());
        $owner = $team->fresh();
        $reads = 0;

        DB::listen(static function (QueryExecuted $query) use (&$reads): void {
            if (str_contains($query->sql, 'billing_grants')) {
                $reads++;
            }
        });

        Livewire::actingAs($this->admin())->test(BillingRelationManager::class, [
            'ownerRecord' => $owner,
            'pageClass' => EditTeam::class,
        ]);

        $this->assertSame(1, $reads);
    }

    // -------------------------------------------------------------------------
    // End to end, with the real AdministerBilling
    // -------------------------------------------------------------------------

    public function test_a_grant_through_the_panel_leaves_a_manual_entitlement_and_one_admin_row(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $this->useRealBilling();
        $admin = $this->admin();
        $team = $this->team();

        $this->tab($team, $admin)
            ->callTableAction('grant', data: [
                'plan' => 'business',
                'reason' => 'Conference comp',
                'expires_at' => CarbonImmutable::now()->addMonth()->toDateString(),
            ])
            ->assertHasNoTableActionErrors();

        $team->refresh();
        $this->assertSame(BillingProvider::MANUAL->value, $team->getAttribute('plan_provider'));
        $this->assertSame('business', $team->getAttribute('plan'));

        $granted = $this->eventsOf(BillingEventType::GRANT_ADDED);
        $this->assertCount(1, $granted);
        $this->assertSame(BillingSource::ADMIN, $granted[0]->source);
        $this->assertEquals($admin->getKey(), $granted[0]->actor_user_id);

        Event::assertDispatched(
            AdminActionPerformed::class,
            static fn (AdminActionPerformed $performed): bool => $performed->action === 'billing.grant_added',
        );
        FilamentNotification::assertNotified((string) __('magic-starter::admin_billing.actions.grant.success'));
    }

    public function test_a_refund_through_the_panel_leaves_one_refunded_row(): void
    {
        $this->useRealBilling();
        $this->stripe = StripeHttpStub::install();
        $team = $this->team(attributes: ['stripe_id' => 'cus_team']);
        $subscription = $this->subscription($team, 'active');

        // Once for the modal, once more inside the refund, which re-reads.
        foreach (range(1, 2) as $read) {
            $this->stripe
                ->answer($this->invoiceList($subscription->stripe_id))
                ->answer($this->paymentList());
        }

        $this->stripe->answer([
            'id' => 're_panel',
            'object' => 'refund',
            'amount' => 2900,
            'currency' => 'usd',
            'payment_intent' => 'pi_paid',
            'status' => 'succeeded',
        ]);

        $this->tab($team)
            ->mountTableAction('refund')
            ->assertMountedActionModalSee(Cashier::formatAmount(2900, 'usd'))
            ->setTableActionData(['reason' => 'requested_by_customer'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $refunds = $this->stripe->requestsTo('post', '/v1/refunds');
        $this->assertCount(1, $refunds);
        $this->assertSame('requested_by_customer', $refunds[0]['params']['reason']);
        $this->assertContains('Idempotency-Key: admin-refund:in_paid', $refunds[0]['headers']);

        $refunded = $this->eventsOf(BillingEventType::INVOICE_REFUNDED);
        $this->assertCount(1, $refunded);
        $this->assertSame('re_panel', $refunded[0]->external_id);
        $this->assertSame(BillingSource::ADMIN, $refunded[0]->source);
    }

    /**
     * The grant is shown over a paying team on purpose, so the refusal under
     * test is the contract's rather than the hidden action's.
     */
    public function test_a_refused_grant_keeps_its_refusal_row_after_the_halt(): void
    {
        Event::fake([AdminActionPerformed::class]);
        $this->useRealBilling();
        $team = $this->team(attributes: [
            'plan' => 'pro',
            'plan_status' => PlanStatus::ACTIVE->value,
            'plan_provider' => BillingProvider::STRIPE->value,
        ]);

        $manager = new class extends BillingRelationManager
        {
            protected function offersGrant(Model $owner): bool
            {
                return true;
            }
        };

        $this->tab($team, manager: $manager::class)
            ->callTableAction('grant', data: [
                'plan' => 'business',
                'reason' => 'Comp over a payer',
            ]);

        FilamentNotification::assertNotified(
            FilamentNotification::make()->danger()->title(
                (string) __('magic-starter::admin_billing.refusals.paid_rail_active'),
            ),
        );

        $refused = $this->eventsOf(BillingEventType::REQUEST_REFUSED);
        $this->assertCount(1, $refused);
        $this->assertSame('paid_rail_active', $refused[0]->reason);
        $this->assertSame(BillingSource::ADMIN, $refused[0]->source);
        $this->assertSame([], BillingGrant::forBillable($team)->get()->all());
        $this->assertSame(BillingProvider::STRIPE->value, $team->refresh()->getAttribute('plan_provider'));
        Event::assertNotDispatched(AdminActionPerformed::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * A team in the state that offers `$action`.
     */
    private function arrange(string $action): BillableTeam
    {
        $team = $this->team();

        match ($action) {
            'grant' => null,
            'revoke' => $this->grantRow(
                $this->updated($team, ['plan_provider' => BillingProvider::MANUAL->value]),
                null,
            ),
            'extendTrial', 'endTrial' => $this->subscription(
                $team,
                'trialing',
                trialEndsAt: CarbonImmutable::now()->addDays(3),
            ),
            'cancelSubscription' => $this->subscription($team, 'active'),
            'resumeSubscription' => $this->subscription($team, 'active', endsAt: CarbonImmutable::now()->addDays(5)),
            'refund' => $this->subscription($this->updated($team, ['stripe_id' => 'cus_team']), 'active'),
            'sync' => $this->subscription($team, 'active'),
        };

        return $team;
    }

    /**
     * @return array<string, mixed>
     */
    private function dataFor(string $action): array
    {
        return match ($action) {
            'grant' => [
                'plan' => 'pro',
                'reason' => 'Partner comp',
                'expires_at' => CarbonImmutable::now()->addMonth()->toDateString(),
            ],
            'revoke' => ['reason' => 'Comp ended early'],
            'extendTrial' => ['until' => CarbonImmutable::now()->addDays(10)->toDateString()],
            'refund' => ['reason' => 'duplicate'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function assertArguments(string $action, array $arguments): void
    {
        $data = $this->dataFor($action);

        match ($action) {
            'grant' => $this->assertGrantArguments($data, $arguments),
            'revoke' => $this->assertSame(['reason' => 'Comp ended early'], $arguments),
            'extendTrial' => $this->assertSame(
                CarbonImmutable::parse($data['until'])->toDateString(),
                $arguments['until']->toDateString(),
            ),
            'refund' => $this->assertSame([
                'reason' => 'duplicate',
                'expectedInvoiceId' => 'in_paid',
            ], $arguments),
            default => $this->assertSame([], $arguments),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $arguments
     */
    private function assertGrantArguments(array $data, array $arguments): void
    {
        $this->assertSame('pro', $arguments['plan']);
        $this->assertSame('Partner comp', $arguments['reason']);
        $this->assertInstanceOf(CarbonInterface::class, $arguments['expiresAt']);
        $this->assertSame($data['expires_at'], $arguments['expiresAt']->toDateString());
        $this->assertSame('23:59:59', $arguments['expiresAt']->format('H:i:s'));
    }

    private function useRealBilling(): void
    {
        $this->app->forgetInstance(AdministersBilling::class);
        $this->app->bind(AdministersBilling::class, AdministerBilling::class);
    }

    private function tab(Model $team, ?ConcreteAdminUser $admin = null, ?string $manager = null): Testable
    {
        // A fresh copy, as each request loads it: an instance a previous tab
        // rendered still carries the subscriptions it loaded then.
        return Livewire::actingAs($admin ?? $this->admin())->test($manager ?? BillingRelationManager::class, [
            'ownerRecord' => $team->fresh(),
            'pageClass' => EditTeam::class,
        ]);
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
    private function team(string $name = 'Acme', array $attributes = []): BillableTeam
    {
        $team = new BillableTeam;
        $team->forceFill([
            'user_id' => $this->admin()->getKey(),
            'name' => $name,
            'personal_team' => false,
            ...$attributes,
        ])->save();

        return $team;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updated(BillableTeam $team, array $attributes): BillableTeam
    {
        $team->forceFill($attributes)->save();

        return $team;
    }

    private function grantRow(Model $team, ?CarbonImmutable $expiresAt): BillingGrant
    {
        return BillingGrant::query()->create([
            'billable_type' => $team->getMorphClass(),
            'billable_id' => (string) $team->getKey(),
            'plan' => 'pro',
            'reason' => 'Partner comp',
            'expires_at' => $expiresAt,
        ]);
    }

    private function subscription(
        BillableTeam $team,
        string $status,
        ?CarbonImmutable $trialEndsAt = null,
        ?CarbonImmutable $endsAt = null,
    ): Subscription {
        $subscription = new Subscription;
        $subscription->forceFill([
            $team->getForeignKey() => $team->getKey(),
            'type' => 'default',
            'stripe_id' => 'sub_' . Str::random(10),
            'stripe_status' => $status,
            'stripe_price' => 'price_pro',
            'quantity' => 1,
            'trial_ends_at' => $trialEndsAt,
            'ends_at' => $endsAt,
        ])->save();

        return $subscription;
    }

    private function event(Model $team, BillingEventType $type, CarbonInterface $at): BillingEvent
    {
        return BillingEvent::query()->create([
            'type' => $type,
            'source' => BillingSource::ADMIN,
            'provider' => BillingProvider::MANUAL,
            'billable_type' => $team->getMorphClass(),
            'billable_id' => (string) $team->getKey(),
            'created_at' => $at,
        ]);
    }

    /**
     * @return list<BillingEvent>
     */
    private function eventsOf(BillingEventType $type): array
    {
        return BillingEvent::query()->where('type', $type->value)->orderBy('id')->get()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceList(string $subscriptionId): array
    {
        return [
            'object' => 'list',
            'url' => '/v1/invoices',
            'has_more' => false,
            'data' => [
                [
                    'id' => 'in_paid',
                    'object' => 'invoice',
                    'customer' => 'cus_team',
                    'status' => 'paid',
                    'amount_paid' => 2900,
                    'currency' => 'usd',
                    'created' => CarbonImmutable::now()->getTimestamp(),
                    'parent' => [
                        'type' => 'subscription_details',
                        'quote_details' => null,
                        'subscription_details' => [
                            'metadata' => null,
                            'subscription' => $subscriptionId,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentList(): array
    {
        return [
            'object' => 'list',
            'url' => '/v1/invoice_payments',
            'has_more' => false,
            'data' => [
                [
                    'id' => 'inpay_panel',
                    'object' => 'invoice_payment',
                    'status' => 'paid',
                    'amount_paid' => 2900,
                    'currency' => 'usd',
                    'payment' => [
                        'type' => 'payment_intent',
                        'payment_intent' => 'pi_paid',
                    ],
                ],
            ],
        ];
    }
}

/**
 * The billable team, carrying Cashier's trait. Its foreign key is pinned to the
 * one the subscriptions migration derived from ConcreteTeam, since this class's
 * own basename would derive another.
 */
class BillableTeam extends ConcreteTeam
{
    use Billable;

    public function getForeignKey(): string
    {
        return 'concrete_team_id';
    }
}

/**
 * A stand-in for the billing contract: records every write it is asked for and
 * answers the two reads from properties a test sets.
 */
class RecordingBillingAdministration implements AdministersBilling
{
    /**
     * @var list<array{method: string, actor: Authenticatable, billable: Model, arguments: array<string, mixed>}>
     */
    private array $writes = [];

    public bool $paidRailGrants = false;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $refundablePayment = [
        'invoice_id' => 'in_paid',
        'payment_intent' => 'pi_paid',
        'amount' => 2900,
        'currency' => 'usd',
    ];

    public ?Throwable $refundablePaymentFailure = null;

    public int $refundablePaymentReads = 0;

    public ?BillingAdministrationRefused $refusal = null;

    public function grant(
        Authenticatable $actor,
        Model $billable,
        string $plan,
        string $reason,
        ?CarbonInterface $expiresAt,
    ): BillingGrant {
        $this->write('grant', $actor, $billable, [
            'plan' => $plan,
            'reason' => $reason,
            'expiresAt' => $expiresAt,
        ]);

        return new BillingGrant;
    }

    public function revoke(Authenticatable $actor, Model $billable, string $reason): void
    {
        $this->write('revoke', $actor, $billable, ['reason' => $reason]);
    }

    public function extendTrial(Authenticatable $actor, Model $billable, CarbonInterface $until): void
    {
        $this->write('extendTrial', $actor, $billable, ['until' => $until]);
    }

    public function endTrial(Authenticatable $actor, Model $billable): void
    {
        $this->write('endTrial', $actor, $billable);
    }

    public function cancel(Authenticatable $actor, Model $billable): void
    {
        $this->write('cancel', $actor, $billable);
    }

    public function resume(Authenticatable $actor, Model $billable): void
    {
        $this->write('resume', $actor, $billable);
    }

    public function refundLastInvoice(
        Authenticatable $actor,
        Model $billable,
        string $reason,
        ?string $expectedInvoiceId = null,
    ): string {
        $this->write('refundLastInvoice', $actor, $billable, [
            'reason' => $reason,
            'expectedInvoiceId' => $expectedInvoiceId,
        ]);

        return 're_fake';
    }

    public function sync(Authenticatable $actor, Model $billable): void
    {
        $this->write('sync', $actor, $billable);
    }

    public function paidRailGrants(Model $billable): bool
    {
        return $this->paidRailGrants;
    }

    public function refundablePayment(Model $billable): ?array
    {
        $this->refundablePaymentReads++;

        if ($this->refundablePaymentFailure !== null) {
            throw $this->refundablePaymentFailure;
        }

        return $this->refundablePayment;
    }

    /**
     * @return list<array{method: string, actor: Authenticatable, billable: Model, arguments: array<string, mixed>}>
     */
    public function writes(): array
    {
        return $this->writes;
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @throws BillingAdministrationRefused when a test armed a refusal
     */
    private function write(string $method, Authenticatable $actor, Model $billable, array $arguments = []): void
    {
        if ($this->refusal !== null) {
            throw $this->refusal;
        }

        $this->writes[] = [
            'method' => $method,
            'actor' => $actor,
            'billable' => $billable,
            'arguments' => $arguments,
        ];
    }
}
