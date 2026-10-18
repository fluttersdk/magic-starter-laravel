<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Filament\Panel;
use FlutterSdk\MagicStarter\Contracts\AdministersBilling;
use FlutterSdk\MagicStarter\Contracts\DecidesSupportProposals;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportProposalAction;
use FlutterSdk\MagicStarter\Enums\SupportProposalStatus;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationUpdated;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\BillingGrant;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Models\SupportProposal;
use FlutterSdk\MagicStarter\Support\BillingAdministrationRefused;
use FlutterSdk\MagicStarter\Tests\Filament\FilamentTestCase;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteAdminUser;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use RuntimeException;
use Throwable;

/**
 * Locks how a staff member decides a support proposal: who may, what approval
 * re-checks before it touches billing, that the billing call runs outside any
 * transaction exactly once, which status every outcome leaves committed, and
 * the line the customer reads in their own language.
 *
 * Team billing, since the snapshot and owner checks only differ from the opener
 * there. The clock is frozen, because expiry and trial windows compare it.
 */
class DecideSupportProposalTest extends FilamentTestCase
{
    private const STAFF_EMAIL = 'staff@example.com';

    private RecordingProposalBilling $billing;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'cashier.secret' => 'sk_test_decide_support_proposal',
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

        MagicStarter::useTeamModel(DecisionTeam::class);
        Cashier::useCustomerModel(DecisionTeam::class);
        Cashier::useSubscriptionModel(Subscription::class);

        $this->travelTo($this->now());

        $this->billing = new RecordingProposalBilling;
        $this->app->instance(AdministersBilling::class, $this->billing);
    }

    protected function tearDown(): void
    {
        Cashier::useCustomerModel('App\\Models\\User');
        Cashier::useSubscriptionModel(CashierSubscription::class);
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_the_contract_is_bound(): void
    {
        $this->assertInstanceOf(DecidesSupportProposals::class, $this->app->make(DecidesSupportProposals::class));
    }

    public function test_approve_cancels_once_as_the_staff_member_outside_any_transaction(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $conversation = $this->conversation($owner, $team);
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);
        $staff = $this->staff();

        $this->decider()->approve($staff, $proposal, $this->panel());

        $calls = $this->billing->calls;
        $this->assertCount(1, $calls);
        $this->assertSame('cancel', $calls[0]['method']);
        $this->assertTrue($staff->is($calls[0]['actor']));
        $this->assertTrue($team->is($calls[0]['billable']));
        $this->assertSame(0, $calls[0]['transactionLevel']);

        $fresh = $proposal->fresh();
        $this->assertSame(SupportProposalStatus::APPROVED, $fresh?->status);
        $this->assertSame((string) $staff->getKey(), (string) $fresh->decided_by);
        $this->assertNotNull($fresh->decided_at);
        $this->assertSame(
            ['A teammate approved your request: Cancel at the end of the period.'],
            $this->activityLines($conversation),
        );
        $this->assertTrue($conversation->fresh()?->last_activity_at?->equalTo($this->now()));
    }

    public function test_approve_extends_the_trial_to_the_recorded_day(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $this->subscription($team, trialEndsAt: $this->now()->addDays(3));
        $conversation = $this->conversation($owner, $team);
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::EXTEND_TRIAL, [
            'until' => '2026-10-20',
        ]);

        $this->decider()->approve($this->staff(), $proposal, $this->panel());

        $calls = $this->billing->calls;
        $this->assertCount(1, $calls);
        $this->assertSame('extendTrial', $calls[0]['method']);
        $until = $calls[0]['until'];
        $this->assertInstanceOf(CarbonInterface::class, $until);
        $this->assertSame('2026-10-20', $until->format('Y-m-d'));
        $this->assertSame(SupportProposalStatus::APPROVED, $proposal->fresh()?->status);
    }

    public function test_approve_resumes_through_resume(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $conversation = $this->conversation($owner, $team);
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::RESUME);

        $this->decider()->approve($this->staff(), $proposal, $this->panel());

        $this->assertSame(['resume'], array_column($this->billing->calls, 'method'));
    }

    public function test_a_second_approve_is_refused_and_bills_nothing_again(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $conversation = $this->conversation($owner, $team);
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);

        $this->decider()->approve($this->staff(), $proposal, $this->panel());
        $this->assertRefused(
            'This request has already been decided.',
            fn () => $this->decider()->approve($this->staff(), $proposal->fresh() ?? $proposal, $this->panel()),
        );

        $this->assertCount(1, $this->billing->calls);
        $this->assertSame(SupportProposalStatus::APPROVED, $proposal->fresh()?->status);
    }

    public function test_an_expired_proposal_is_refused_and_its_expired_status_is_committed(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $conversation = $this->conversation($owner, $team);
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END, [], [
            'expires_at' => $this->now(),
        ]);

        $this->assertRefused(
            'This request expired before it was approved.',
            fn () => $this->decider()->approve($this->staff(), $proposal, $this->panel()),
        );

        $this->assertSame([], $this->billing->calls);
        $this->assertSame(SupportProposalStatus::EXPIRED, $proposal->fresh()?->status);
    }

    public function test_a_conversation_moved_to_another_team_fails_as_billable_changed(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $conversation = $this->conversation($owner, $team);
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);
        $other = DecisionTeam::create([
            'user_id' => $owner->getKey(),
            'name' => 'Other',
            'personal_team' => false,
        ]);
        $conversation->forceFill([
            'team_id' => $other->getKey(),
        ])->save();

        $this->assertRefused(
            'The conversation now belongs to another billing account than the one the request was made for.',
            fn () => $this->decider()->approve($this->staff(), $proposal, $this->panel()),
        );

        $this->assertSame([], $this->billing->calls);
        $this->assertFailedWith('billable_changed', $proposal);
    }

    public function test_an_opener_who_lost_ownership_fails_as_owner_changed(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $conversation = $this->conversation($owner, $team);
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);
        $successor = $this->user('successor@example.com');
        $team->forceFill([
            'user_id' => $successor->getKey(),
        ])->save();
        $owner->teams()->attach($team->getKey(), [
            'role' => 'admin',
        ]);

        $this->assertRefused(
            'The customer who asked is no longer the account owner.',
            fn () => $this->decider()->approve($this->staff(), $proposal, $this->panel()),
        );

        $this->assertSame([], $this->billing->calls);
        $this->assertFailedWith('owner_changed', $proposal);
    }

    public function test_an_opener_who_left_the_team_fails_as_no_billable(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $member = $this->user('member@example.com');
        $member->teams()->attach($team->getKey(), [
            'role' => 'admin',
        ]);
        $conversation = $this->conversation($member, $team);
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);
        $member->teams()->detach($team->getKey());

        $this->assertRefused(
            'The conversation no longer has a billing account to change.',
            fn () => $this->decider()->approve($this->staff(), $proposal, $this->panel()),
        );

        $this->assertSame([], $this->billing->calls);
        $this->assertFailedWith('no_billable', $proposal);
    }

    public function test_a_trial_extension_the_current_trial_no_longer_allows_fails_as_params_stale(): void
    {
        [$owner, $team] = $this->ownedTeam();
        // Recorded against a trial ending 2026-10-13; the trial has since moved to 2026-10-20 12:00.
        $this->subscription($team, trialEndsAt: $this->now()->addDays(10));
        $conversation = $this->conversation($owner, $team);
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::EXTEND_TRIAL, [
            'until' => '2026-10-20',
        ]);

        $this->assertRefused(
            'The requested trial end is no longer valid for the current trial.',
            fn () => $this->decider()->approve($this->staff(), $proposal, $this->panel()),
        );

        $this->assertSame([], $this->billing->calls);
        $this->assertFailedWith('params_stale', $proposal);
    }

    public function test_a_staff_member_outside_the_billing_allowlist_is_refused_before_anything(): void
    {
        config()->set('magic-starter.admin.billing_emails', ['finance@example.com']);
        [$owner, $team] = $this->ownedTeam();
        $conversation = $this->conversation($owner, $team);
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);

        $this->assertRefused(
            'You are not allowed to decide billing requests.',
            fn () => $this->decider()->approve($this->staff(), $proposal, $this->panel()),
        );

        $this->assertSame([], $this->billing->calls);
        $this->assertSame(SupportProposalStatus::PENDING, $proposal->fresh()?->status);
    }

    public function test_a_staff_member_without_panel_access_is_refused_before_anything(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $conversation = $this->conversation($owner, $team);
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);

        $this->assertRefused(
            'You are not allowed to decide billing requests.',
            fn () => $this->decider()->approve($this->staff('outsider@example.com'), $proposal, $this->panel()),
        );
        $this->assertRefused(
            'You are not allowed to decide billing requests.',
            fn () => $this->decider()->reject($this->staff('outsider@example.com'), $proposal, $this->panel(), 'No'),
        );

        $this->assertSame([], $this->billing->calls);
        $this->assertSame(SupportProposalStatus::PENDING, $proposal->fresh()?->status);
    }

    public function test_a_billing_refusal_leaves_the_proposal_failed_tells_the_customer_and_propagates(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $conversation = $this->conversation($owner, $team);
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);
        $this->billing->failure = new BillingAdministrationRefused('already_cancelled');

        try {
            $this->decider()->approve($this->staff(), $proposal, $this->panel());
            $this->fail('The billing refusal should propagate.');
        } catch (BillingAdministrationRefused $refusal) {
            $this->assertSame('already_cancelled', $refusal->reason());
        }

        $this->assertFailedWith('already_cancelled', $proposal);
        $this->assertSame(
            ['We could not apply your request (Cancel at the end of the period). A teammate will follow up here.'],
            $this->activityLines($conversation),
        );
    }

    public function test_any_other_failure_propagates_and_leaves_the_proposal_executing(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $conversation = $this->conversation($owner, $team);
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);
        $this->billing->failure = new RuntimeException('Stripe is down.');

        try {
            $this->decider()->approve($this->staff(), $proposal, $this->panel());
            $this->fail('The failure should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Stripe is down.', $exception->getMessage());
        }

        $this->assertSame(SupportProposalStatus::EXECUTING, $proposal->fresh()?->status);
        $this->assertSame([], $this->activityLines($conversation));
    }

    public function test_approve_inside_an_open_transaction_is_refused_before_anything(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $conversation = $this->conversation($owner, $team);
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);

        DB::beginTransaction();

        try {
            $this->assertRefused(
                'Approval must run outside a database transaction, '
                . 'so the billing change commits on its own.',
                fn () => $this->decider()->approve($this->staff(), $proposal, $this->panel()),
            );
        } finally {
            DB::rollBack();
        }

        $this->assertSame([], $this->billing->calls);
        $this->assertSame(SupportProposalStatus::PENDING, $proposal->fresh()?->status);
    }

    public function test_a_decision_line_refreshes_the_customers_thread(): void
    {
        Event::fake([SupportConversationUpdated::class]);

        [$owner, $team] = $this->ownedTeam();
        $this->subscription($team, null);
        $conversation = $this->conversation($owner, $team);
        $approved = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);
        $declined = $this->proposal($conversation, $team, SupportProposalAction::RESUME);

        $this->decider()->approve($this->staff(), $approved, $this->panel());
        $this->decider()->reject($this->staff(), $declined, $this->panel(), 'Not needed.');

        Event::assertDispatchedTimes(SupportConversationUpdated::class, 2);
    }

    public function test_reject_declines_with_the_reason_and_tells_the_customer(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $conversation = $this->conversation($owner, $team);
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::RESUME);
        $staff = $this->staff();

        $this->decider()->reject($staff, $proposal, $this->panel(), 'The plan was already resumed by phone.');

        $fresh = $proposal->fresh();
        $this->assertSame(SupportProposalStatus::REJECTED, $fresh?->status);
        $this->assertSame('The plan was already resumed by phone.', $fresh->decision_reason);
        $this->assertSame((string) $staff->getKey(), (string) $fresh->decided_by);
        $this->assertNotNull($fresh->decided_at);
        $this->assertSame(['A teammate declined your request: Resume the subscription.'], $this->activityLines($conversation));
        $this->assertSame([], $this->billing->calls);

        $this->assertRefused(
            'This request has already been decided.',
            fn () => $this->decider()->reject($staff, $proposal, $this->panel(), 'Again'),
        );
    }

    public function test_mark_failed_only_moves_an_executing_proposal_and_tells_nobody(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $conversation = $this->conversation($owner, $team);
        $pending = $this->proposal($conversation, $team, SupportProposalAction::CANCEL_AT_PERIOD_END);
        $executing = $this->proposal($conversation, $team, SupportProposalAction::RESUME, [], [
            'status' => SupportProposalStatus::EXECUTING,
        ]);

        $this->assertRefused(
            'Only a request whose approval is still running can be marked as failed.',
            fn () => $this->decider()->markFailed($this->staff(), $pending, $this->panel(), 'Stuck'),
        );
        $this->assertSame(SupportProposalStatus::PENDING, $pending->fresh()?->status);

        $this->decider()->markFailed($this->staff(), $executing, $this->panel(), 'Stripe never answered.');

        $this->assertFailedWith('Stripe never answered.', $executing);
        $this->assertSame([], $this->activityLines($conversation));
        $this->assertSame([], $this->billing->calls);
    }

    public function test_the_customer_line_follows_the_conversation_locale_not_the_staff_members(): void
    {
        app()->setLocale('en');
        [$owner, $team] = $this->ownedTeam();
        $owner->forceFill([
            'locale' => 'tr',
        ])->save();
        $conversation = $this->conversation($owner, $team);
        $proposal = $this->proposal($conversation, $team, SupportProposalAction::RESUME);

        $this->decider()->approve($this->staff(), $proposal, $this->panel());

        $this->assertSame(
            ['Bir ekip arkadaşımız talebinizi onayladı: Aboneliği sürdür.'],
            $this->activityLines($conversation),
        );
    }

    private function decider(): DecidesSupportProposals
    {
        return $this->app->make(DecidesSupportProposals::class);
    }

    private function panel(): Panel
    {
        return Filament::getPanel('admin');
    }

    /**
     * Run the call, expect the refusal ValidationException with this message.
     */
    private function assertRefused(string $message, callable $call): void
    {
        try {
            $call();
        } catch (ValidationException $exception) {
            $this->assertSame([$message], array_merge(...array_values($exception->errors())));

            return;
        }

        $this->fail("Expected the refusal [{$message}].");
    }

    private function assertFailedWith(string $reason, SupportProposal $proposal): void
    {
        $fresh = $proposal->fresh();
        $this->assertSame(SupportProposalStatus::FAILED, $fresh?->status);
        $this->assertSame($reason, $fresh->decision_reason);
    }

    /**
     * @return list<string>
     */
    private function activityLines(SupportConversation $conversation): array
    {
        return SupportMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('kind', SupportMessageKind::ACTIVITY->value)
            ->where('sender_type', SupportSenderType::SYSTEM->value)
            ->orderBy('id')
            ->pluck('body')
            ->map(static fn (mixed $body): string => (string) $body)
            ->values()
            ->all();
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-10 12:00:00');
    }

    private function staff(string $email = self::STAFF_EMAIL): ConcreteAdminUser
    {
        $staff = ConcreteAdminUser::query()->where('email', $email)->first();

        if ($staff instanceof ConcreteAdminUser) {
            return $staff;
        }

        $staff = new ConcreteAdminUser;
        $staff->forceFill([
            'name' => 'Staff',
            'email' => $email,
            'email_verified_at' => now(),
            'password' => 'secret',
        ])->save();

        return $staff;
    }

    private function user(string $email): ConcreteAdminUser
    {
        $user = new ConcreteAdminUser;
        $user->forceFill([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => 'secret',
        ])->save();

        return $user;
    }

    /**
     * @return array{ConcreteAdminUser, DecisionTeam}
     */
    private function ownedTeam(): array
    {
        $owner = $this->user('owner@example.com');
        $team = DecisionTeam::create([
            'user_id' => $owner->getKey(),
            'name' => 'Acme',
            'personal_team' => false,
        ]);

        return [$owner, $team];
    }

    private function subscription(DecisionTeam $team, ?CarbonImmutable $trialEndsAt): void
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

    private function conversation(ConcreteAdminUser $opener, DecisionTeam $team): SupportConversation
    {
        return SupportConversation::create([
            'user_id' => $opener->getKey(),
            'team_id' => $team->getKey(),
            'email' => $opener->getAttribute('email'),
            'subject' => 'About my plan',
            'source' => SupportConversationSource::IN_APP,
            'status' => SupportConversationStatus::OPEN,
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
        DecisionTeam $team,
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
class DecisionTeam extends ConcreteTeam
{
    use Billable;

    public function getForeignKey(): string
    {
        return 'concrete_team_id';
    }
}

/**
 * A stand-in for the billing contract that records each write with the
 * transaction depth it ran at, and throws `$failure` when a test arms one.
 */
class RecordingProposalBilling implements AdministersBilling
{
    /**
     * @var list<array{method: string, actor: Authenticatable, billable: Model, until: CarbonInterface|null, transactionLevel: int}>
     */
    public array $calls = [];

    public ?Throwable $failure = null;

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
        $this->record('extendTrial', $actor, $billable, $until);
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

    private function record(string $method, Authenticatable $actor, Model $billable, ?CarbonInterface $until = null): void
    {
        $this->calls[] = [
            'method' => $method,
            'actor' => $actor,
            'billable' => $billable,
            'until' => $until,
            'transactionLevel' => DB::transactionLevel(),
        ];

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
