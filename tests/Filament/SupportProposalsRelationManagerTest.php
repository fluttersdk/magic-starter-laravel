<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\ActionGroup;
use Filament\Support\Enums\Size;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Enums\BillingEventType;
use FlutterSdk\MagicStarter\Enums\BillingSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportProposalAction;
use FlutterSdk\MagicStarter\Enums\SupportProposalStatus;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\AdminActionPerformed;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\Pages\ViewSupportConversation;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\RelationManagers\ProposalsRelationManager;
use FlutterSdk\MagicStarter\Filament\Resources\SupportConversations\SupportConversationResource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\BillingEvent;
use FlutterSdk\MagicStarter\Models\BillingGrant;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Models\SupportProposal;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;

/**
 * The proposals tab on the support thread page: which requests staff see, the
 * facts beside each one, and that a decision only ever runs through the
 * `DecidesSupportProposals` contract (the real one, over a recording billing
 * stand-in, so an approval is proven end to end without Stripe).
 *
 * Team billing, as the decision tests use it. The clock is frozen, because
 * expiry compares it.
 */
class SupportProposalsRelationManagerTest extends FilamentTestCase
{
    private const STAFF_EMAIL = 'staff@example.com';

    private ProposalTabBilling $billing;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'cashier.secret' => 'sk_test_support_proposals_tab',
            'magic-starter.features' => [
                Features::teams(),
                Features::support(),
                Features::billing(),
                Features::supportProposals(),
            ],
            'magic-starter.use_uuids' => false,
            'magic-starter.billing.billable' => 'team',
            'magic-starter.billing.tier_order' => ['free', 'pro'],
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'refs' => ['stripe_price' => 'price_pro'],
                ],
            ],
            'magic-starter.billing.revenuecat.secret_api_key' => null,
            'magic-starter.admin.emails' => [self::STAFF_EMAIL],
            'magic-starter.admin.billing_emails' => [],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useTeamModel(ProposalTabTeam::class);
        Cashier::useCustomerModel(ProposalTabTeam::class);
        Cashier::useSubscriptionModel(Subscription::class);

        $this->travelTo($this->now());

        $this->billing = new ProposalTabBilling;
        $this->app->instance(AdministersBilling::class, $this->billing);
    }

    protected function tearDown(): void
    {
        Cashier::useCustomerModel('App\\Models\\User');
        Cashier::useSubscriptionModel(CashierSubscription::class);
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_the_tab_is_registered_only_with_the_proposals_feature(): void
    {
        $this->assertContains(ProposalsRelationManager::class, SupportConversationResource::getRelations());

        config()->set('magic-starter.features', [
            Features::teams(),
            Features::support(),
            Features::billing(),
        ]);

        $this->assertNotContains(ProposalsRelationManager::class, SupportConversationResource::getRelations());
    }

    public function test_pending_proposals_are_listed_and_drafts_are_not(): void
    {
        [$conversation, $team] = $this->arrange();
        $pending = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);
        $draft = $this->proposal($conversation, $team, SupportProposalAction::RESUME, [], [
            'status' => SupportProposalStatus::DRAFT,
        ]);

        $this->tab($conversation)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$draft]);
    }

    public function test_the_tab_is_not_read_only_on_the_view_page(): void
    {
        [$conversation] = $this->arrange();

        $this->assertFalse($this->tab($conversation)->instance()->isReadOnly());
    }

    public function test_approve_runs_the_contract_and_the_row_shows_approved(): void
    {
        [$conversation, $team] = $this->arrange();
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);

        $this->tab($conversation)
            ->assertTableActionVisible('approve', $proposal)
            ->callTableAction('approve', $proposal)
            ->assertHasNoTableActionErrors()
            ->assertTableActionHidden('approve', $proposal)
            ->assertSee((string) __('magic-starter::admin_support.proposals.statuses.approved'));

        $this->assertSame(['cancel'], array_column($this->billing->calls, 'method'));
        $this->assertTrue($this->staff()->is($this->billing->calls[0]['actor']));
        $this->assertSame(SupportProposalStatus::APPROVED, $proposal->fresh()?->status);
    }

    public function test_reject_requires_a_reason_and_then_declines(): void
    {
        [$conversation, $team] = $this->arrange();
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);

        $this->tab($conversation)
            ->callTableAction('reject', $proposal, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertSame(SupportProposalStatus::PENDING, $proposal->fresh()?->status);

        $this->tab($conversation)
            ->callTableAction('reject', $proposal, data: ['reason' => 'Not eligible.'])
            ->assertHasNoTableActionErrors();

        $fresh = $proposal->fresh();
        $this->assertSame(SupportProposalStatus::REJECTED, $fresh?->status);
        $this->assertSame('Not eligible.', $fresh->decision_reason);
        $this->assertSame([], $this->billing->calls);
    }

    public function test_decisions_are_hidden_from_staff_outside_the_billing_allowlist(): void
    {
        config()->set('magic-starter.admin.billing_emails', ['money@example.com']);
        [$conversation, $team] = $this->arrange();
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);

        $this->tab($conversation)
            ->assertCanSeeTableRecords([$proposal])
            ->assertTableActionHidden('approve', $proposal)
            ->assertTableActionHidden('reject', $proposal);
    }

    public function test_decisions_are_hidden_on_decided_and_expired_rows(): void
    {
        [$conversation, $team] = $this->arrange();
        $rejected = $this->proposal($conversation, $team, SupportProposalAction::RESUME, [], [
            'status' => SupportProposalStatus::REJECTED,
        ]);
        $expired = $this->proposal($conversation, $team, SupportProposalAction::RESUME, [], [
            'expires_at' => $this->now()->subMinute(),
        ]);

        $this->tab($conversation)
            ->assertTableActionHidden('approve', $rejected)
            ->assertTableActionHidden('reject', $rejected)
            ->assertTableActionHidden('markFailed', $rejected)
            ->assertTableActionHidden('approve', $expired)
            ->assertTableActionHidden('reject', $expired)
            ->assertSee((string) __('magic-starter::admin_support.proposals.labels.expired'));
    }

    public function test_the_row_decisions_share_one_menu_with_a_44_px_trigger(): void
    {
        [$conversation] = $this->arrange();

        $actions = array_values($this->tab($conversation)->instance()->getTable()->getRecordActions());

        $this->assertCount(1, $actions);
        $this->assertInstanceOf(ActionGroup::class, $actions[0]);
        $this->assertSame(Size::ExtraLarge, $actions[0]->getSize());
        $this->assertSame(
            [
                'view',
                'approve',
                'reject',
                'markFailed',
                'markApplied',
            ],
            array_keys($actions[0]->getFlatActions()),
        );
    }

    public function test_the_secondary_columns_show_from_the_lg_breakpoint(): void
    {
        [$conversation] = $this->arrange();

        $table = $this->tab($conversation)->instance()->getTable();

        foreach (['params_summary', 'requester_name', 'created_at', 'expires_at'] as $name) {
            $this->assertSame('lg', $table->getColumn($name)?->getVisibleFrom(), $name);
        }

        $this->assertNull($table->getColumn('action')?->getVisibleFrom());
        $this->assertNull($table->getColumn('status')?->getVisibleFrom());
        $this->assertTrue($table->getColumn('action')?->canWrap());
    }

    public function test_the_status_column_says_a_lapsed_request_expired_since_its_deadline_column_hides_below_lg(): void
    {
        [$conversation, $team] = $this->arrange();
        $lapsed = $this->proposal($conversation, $team, SupportProposalAction::RESUME, [], [
            'expires_at' => $this->now()->subMinute(),
        ]);
        $open = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);

        $status = $this->tab($conversation)->instance()->getTable()->getColumn('status');

        $this->assertSame(
            (string) __('magic-starter::admin_support.proposals.labels.expired'),
            $status?->record($lapsed)->getDescriptionBelow(),
        );
        $this->assertNull($status?->record($open)->getDescriptionBelow());
    }

    public function test_mark_failed_is_offered_only_for_an_executing_row_and_needs_a_reason(): void
    {
        [$conversation, $team] = $this->arrange();
        $pending = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);
        $executing = $this->proposal($conversation, $team, SupportProposalAction::RESUME, [], [
            'status' => SupportProposalStatus::EXECUTING,
        ]);

        $this->tab($conversation)
            ->assertTableActionHidden('markFailed', $pending)
            ->assertTableActionVisible('markFailed', $executing)
            ->assertTableActionHidden('approve', $executing)
            ->callTableAction('markFailed', $executing, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->tab($conversation)
            ->callTableAction('markFailed', $executing, data: ['reason' => 'Stripe shows no change.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(SupportProposalStatus::FAILED, $executing->fresh()?->status);
    }

    public function test_mark_applied_is_offered_only_for_an_executing_row_needs_a_reason_and_approves_it(): void
    {
        [$conversation, $team] = $this->arrange();
        $pending = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);
        $executing = $this->proposal($conversation, $team, SupportProposalAction::RESUME, [], [
            'status' => SupportProposalStatus::EXECUTING,
        ]);

        $this->tab($conversation)
            ->assertTableActionHidden('markApplied', $pending)
            ->assertTableActionVisible('markApplied', $executing)
            ->callTableAction('markApplied', $executing, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertSame(SupportProposalStatus::EXECUTING, $executing->fresh()?->status);

        $this->tab($conversation)
            ->callTableAction('markApplied', $executing, data: ['reason' => 'The billing events show the resume.'])
            ->assertHasNoTableActionErrors();

        $fresh = $executing->fresh();
        $this->assertSame(SupportProposalStatus::APPROVED, $fresh?->status);
        $this->assertSame('The billing events show the resume.', $fresh->decision_reason);
        $this->assertSame([], $this->billing->calls);
    }

    public function test_mark_applied_is_hidden_from_staff_outside_the_billing_allowlist(): void
    {
        config()->set('magic-starter.admin.billing_emails', ['money@example.com']);
        [$conversation, $team] = $this->arrange();
        $executing = $this->proposal($conversation, $team, SupportProposalAction::RESUME, [], [
            'status' => SupportProposalStatus::EXECUTING,
        ]);

        $this->tab($conversation)
            ->assertTableActionHidden('markApplied', $executing)
            ->assertTableActionHidden('markFailed', $executing);
    }

    public function test_each_decision_is_audited_under_its_past_tense_name(): void
    {
        Event::fake([AdminActionPerformed::class]);
        [$conversation, $team] = $this->arrange();
        $approved = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);
        $rejected = $this->proposal($conversation, $team, SupportProposalAction::RESUME);
        $failed = $this->proposal($conversation, $team, SupportProposalAction::RESUME, [], [
            'status' => SupportProposalStatus::EXECUTING,
        ]);
        $applied = $this->proposal($conversation, $team, SupportProposalAction::RESUME, [], [
            'status' => SupportProposalStatus::EXECUTING,
        ]);

        $this->tab($conversation)->callTableAction('approve', $approved);
        $this->tab($conversation)->callTableAction('reject', $rejected, data: ['reason' => 'No.']);
        $this->tab($conversation)->callTableAction('markFailed', $failed, data: ['reason' => 'Not applied.']);
        $this->tab($conversation)->callTableAction('markApplied', $applied, data: ['reason' => 'Applied.']);

        foreach ([
            'support.proposal_approved' => $approved,
            'support.proposal_rejected' => $rejected,
            'support.proposal_marked_failed' => $failed,
            'support.proposal_marked_applied' => $applied,
        ] as $name => $proposal) {
            Event::assertDispatched(
                AdminActionPerformed::class,
                static fn (AdminActionPerformed $event): bool => $event->action === $name
                    && $proposal->is($event->subject),
            );
        }
    }

    public function test_the_details_show_the_facts_and_escape_customer_and_ai_text(): void
    {
        [$conversation, $team] = $this->arrange();
        $this->subscription($team, $this->now()->addDays(3));
        BillingEvent::query()->create([
            'type' => BillingEventType::TRIAL_EXTENDED,
            'source' => BillingSource::REQUEST,
            'billable_type' => $team->getMorphClass(),
            'billable_id' => (string) $team->getKey(),
        ]);
        $trigger = $this->message($conversation, '<script>alert("customer")</script> please extend');
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::EXTEND_TRIAL, [
            'until' => '2026-10-20',
        ], [
            'trigger_message_id' => $trigger->getKey(),
            'rationale' => '<b>owner</b> asked',
        ]);

        $this->travel(5)->minutes();
        $this->message($conversation, 'Any news?');
        $this->message($conversation, 'Hello?');

        $this->tab($conversation)
            ->mountTableAction('view', $proposal)
            ->assertMountedActionModalSee([
                (string) __('magic-starter::admin_support.proposals.labels.customer_words'),
                (string) __('magic-starter::admin_support.proposals.labels.rationale'),
                (string) __('magic-starter::admin_support.proposals.labels.trial_ends_at'),
                (string) __('magic-starter::admin_support.proposals.labels.prior_extensions'),
                (string) __('magic-starter::admin_support.proposals.labels.messages_since'),
                '<script>alert("customer")</script> please extend',
                '<b>owner</b> asked',
            ])
            ->assertMountedActionModalDontSee([
                '<script>alert("customer")</script>',
                '<b>owner</b>',
            ], escape: false);
    }

    private function tab(SupportConversation $conversation): Testable
    {
        return Livewire::actingAs($this->staff())->test(ProposalsRelationManager::class, [
            'ownerRecord' => $conversation->fresh(),
            'pageClass' => ViewSupportConversation::class,
        ]);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-15 09:00:00');
    }

    private function staff(): ConcreteAdminUser
    {
        $staff = ConcreteAdminUser::query()->where('email', self::STAFF_EMAIL)->first();

        if ($staff instanceof ConcreteAdminUser) {
            return $staff;
        }

        $staff = new ConcreteAdminUser;
        $staff->forceFill([
            'name' => 'Staff',
            'email' => self::STAFF_EMAIL,
            'email_verified_at' => now(),
            'password' => 'secret',
        ])->save();

        return $staff;
    }

    /**
     * A team its owner opened a conversation for.
     *
     * @return array{SupportConversation, ProposalTabTeam}
     */
    private function arrange(): array
    {
        $owner = new ConcreteAdminUser;
        $owner->forceFill([
            'name' => 'Olivia Owner',
            'email' => 'owner@example.com',
            'password' => 'secret',
        ])->save();

        $team = ProposalTabTeam::create([
            'user_id' => $owner->getKey(),
            'name' => 'Acme',
            'personal_team' => false,
        ]);

        $conversation = SupportConversation::create([
            'user_id' => $owner->getKey(),
            'team_id' => $team->getKey(),
            'email' => $owner->getAttribute('email'),
            'subject' => 'About my plan',
            'source' => SupportConversationSource::IN_APP,
            'status' => SupportConversationStatus::OPEN,
        ]);

        return [$conversation, $team];
    }

    private function subscription(ProposalTabTeam $team, CarbonImmutable $trialEndsAt): void
    {
        $subscription = new Subscription;
        $subscription->forceFill([
            $team->getForeignKey() => $team->getKey(),
            'type' => 'default',
            'stripe_id' => 'sub_' . Str::random(10),
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
            'trial_ends_at' => $trialEndsAt,
            'ends_at' => null,
        ])->save();
    }

    private function message(SupportConversation $conversation, string $body): SupportMessage
    {
        return $conversation->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'sender_id' => $conversation->user_id,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => $body,
        ]);
    }

    /**
     * A pending proposal recorded for the conversation's opener against the team.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $attributes
     */
    private function proposal(
        SupportConversation $conversation,
        ProposalTabTeam $team,
        SupportProposalAction $action,
        array $params = [],
        array $attributes = [],
    ): SupportProposal {
        return SupportProposal::create([
            'conversation_id' => $conversation->getKey(),
            'billable_type' => $team->getMorphClass(),
            'billable_id' => $team->getKey(),
            'requested_by' => $conversation->user_id,
            'action' => $action,
            'params' => $params,
            'status' => SupportProposalStatus::PENDING,
            'expires_at' => $this->now()->addHours(72),
            ...$attributes,
        ]);
    }
}

/**
 * The billable team, carrying Cashier's trait. Its foreign key is pinned to the
 * one the subscriptions migration derived from ConcreteTeam.
 */
class ProposalTabTeam extends ConcreteTeam
{
    use Billable;

    public function getForeignKey(): string
    {
        return 'concrete_team_id';
    }
}

/**
 * A stand-in for the billing contract that records the writes a proposal may
 * make and refuses the ones it never makes.
 */
class ProposalTabBilling implements AdministersBilling
{
    /**
     * @var list<array{method: string, actor: Authenticatable, billable: Model}>
     */
    public array $calls = [];

    public function grant(
        Authenticatable $actor,
        Model $billable,
        string $plan,
        string $reason,
        ?CarbonInterface $expiresAt,
    ): BillingGrant {
        throw new RuntimeException('A proposal never grants.');
    }

    public function revoke(Authenticatable $actor, Model $billable, string $reason): void
    {
        throw new RuntimeException('A proposal never revokes.');
    }

    public function extendTrial(Authenticatable $actor, Model $billable, CarbonInterface $until): void
    {
        $this->record('extendTrial', $actor, $billable);
    }

    public function endTrial(Authenticatable $actor, Model $billable): void
    {
        throw new RuntimeException('A proposal never ends a trial.');
    }

    public function cancel(Authenticatable $actor, Model $billable): void
    {
        $this->record('cancel', $actor, $billable);
    }

    public function resume(Authenticatable $actor, Model $billable): void
    {
        $this->record('resume', $actor, $billable);
    }

    public function refundLastInvoice(
        Authenticatable $actor,
        Model $billable,
        string $reason,
        ?string $expectedInvoiceId = null,
    ): string {
        throw new RuntimeException('A proposal never refunds.');
    }

    public function sync(Authenticatable $actor, Model $billable): void
    {
        throw new RuntimeException('A proposal never syncs.');
    }

    public function paidRailGrants(Model $billable): bool
    {
        return false;
    }

    public function refundablePayment(Model $billable): ?array
    {
        return null;
    }

    private function record(string $method, Authenticatable $actor, Model $billable): void
    {
        $this->calls[] = [
            'method' => $method,
            'actor' => $actor,
            'billable' => $billable,
        ];
    }
}
