<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use Carbon\CarbonImmutable;
use FlutterSdk\MagicStarter\Contracts\RecordsSupportProposals;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportProposalAction;
use FlutterSdk\MagicStarter\Enums\SupportProposalStatus;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Models\SubscriptionItem;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Models\SupportProposal;
use FlutterSdk\MagicStarter\Models\Team;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Locks what the support AI may record when the customer asks for a change to
 * their subscription: only a draft for a staff member to approve later, only
 * for the account owner, only while the rail state makes the change possible,
 * and at most one open request per action and conversation.
 *
 * Team billing is the default here, since the owner gate only differs from the
 * opener there; one test switches to user billing. The clock is frozen,
 * because trial and grace-period checks compare it.
 */
class RecordSupportProposalTest extends TestCase
{
    private const RECORDED_TAIL = ' A teammate will review this request; '
        . 'tell the customer a person will confirm it here.';

    private const OWNER_ONLY = 'Only the account owner can request this change. '
        . "Tell the customer to write in from the owner's account.";

    private const UNAVAILABLE = 'Proposals are not available here; tell the customer a teammate will help.';

    private const ALREADY_WAITING = 'A request for this is already waiting for a teammate.';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'cashier.secret' => 'sk_test_support_proposals',
            'magic-starter.features' => [
                Features::teams(),
                Features::support(),
                Features::billing(),
                Features::supportProposals(),
            ],
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
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(ProposalUser::class);
        MagicStarter::useTeamModel(ProposalTeam::class);
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);

        $this->travelTo($this->now());

        foreach ([
            'create_users_table.php',
            'create_teams_table.php',
            'create_team_user_table.php',
            'create_subscriptions_table.php',
            'create_subscription_items_table.php',
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
            'add_ai_columns_to_support_tables.php',
            'create_support_proposals_table.php',
        ] as $migration) {
            (require __DIR__ . '/../../database/migrations/' . $migration)->up();
        }
    }

    protected function tearDown(): void
    {
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_the_contract_is_bound(): void
    {
        $this->assertInstanceOf(RecordsSupportProposals::class, $this->app->make(RecordsSupportProposals::class));
    }

    public function test_a_team_member_who_is_not_the_owner_gets_the_owner_refusal_and_no_row(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $member = $this->user('member@example.com');
        $member->teams()->attach($team->getKey(), [
            'role' => 'admin',
        ]);
        $this->subscription($team, 'trialing', trialEndsAt: $this->now()->addDays(3));
        $conversation = $this->conversation($member, $team);

        $sentence = $this->record($conversation, SupportProposalAction::EXTEND_TRIAL, ['until' => '2026-10-20']);

        $this->assertSame(self::OWNER_ONLY, $sentence);
        $this->assertSame(0, SupportProposal::query()->count());
    }

    public function test_the_owner_on_trial_records_a_draft_trial_extension_with_the_billable_snapshot(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $this->subscription($team, 'trialing', trialEndsAt: $this->now()->addDays(3));
        $conversation = $this->conversation($owner, $team);
        $trigger = $this->message($conversation);

        $sentence = $this->record(
            $conversation,
            SupportProposalAction::EXTEND_TRIAL,
            ['until' => '2026-10-20', 'days' => 99],
            '  The outage cost them a week.  ',
            $trigger->getKey(),
        );

        $this->assertSame($this->recorded('extend the trial to 2026-10-20'), $sentence);

        $proposal = SupportProposal::query()->sole();
        $this->assertSame(SupportProposalStatus::DRAFT, $proposal->status);
        $this->assertSame(SupportProposalAction::EXTEND_TRIAL, $proposal->action);
        $this->assertSame((string) $conversation->getKey(), (string) $proposal->conversation_id);
        $this->assertSame($team->getMorphClass(), $proposal->billable_type);
        $this->assertSame((string) $team->getKey(), (string) $proposal->billable_id);
        $this->assertSame((string) $owner->getKey(), (string) $proposal->requested_by);
        $this->assertSame(['until' => '2026-10-20'], $proposal->params);
        $this->assertSame('The outage cost them a week.', $proposal->rationale);
        $this->assertSame((string) $trigger->getKey(), (string) $proposal->trigger_message_id);
        $this->assertTrue($proposal->expires_at->equalTo($this->now()->addHours(72)));
        $this->assertNull($proposal->decided_by);
        $this->assertNull($proposal->decided_at);
    }

    public function test_the_rationale_is_cut_to_500_characters_and_a_blank_one_is_null(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $this->subscription($team);
        $conversation = $this->conversation($owner, $team);

        $this->record($conversation, SupportProposalAction::CANCEL_AT_PERIOD_END, [], str_repeat('a', 600));

        $this->assertSame(500, mb_strlen((string) SupportProposal::query()->sole()->rationale));

        SupportProposal::query()->delete();
        $this->record($conversation, SupportProposalAction::CANCEL_AT_PERIOD_END, [], '   ');

        $this->assertNull(SupportProposal::query()->sole()->rationale);
    }

    public function test_an_until_outside_the_allowed_window_is_refused(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $this->subscription($team, 'trialing', trialEndsAt: $this->now()->addDays(3));
        $conversation = $this->conversation($owner, $team);

        // The trial ends 2026-10-13 12:00, so the window closes 2026-10-27 12:00.
        foreach ([
            '2026-10-28',
            '2026-11-30',
            '2026-10-13',
            '2026-10-01',
        ] as $until) {
            $sentence = $this->record($conversation, SupportProposalAction::EXTEND_TRIAL, ['until' => $until]);

            $this->assertStringStartsNotWith('Recorded:', $sentence, "{$until} is outside the window.");
        }

        $this->assertSame(0, SupportProposal::query()->count());

        $this->assertSame(
            $this->recorded('extend the trial to 2026-10-27'),
            $this->record($conversation, SupportProposalAction::EXTEND_TRIAL, ['until' => '2026-10-27']),
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedUntil(): array
    {
        return [
            'relative phrase' => ['+1 year'],
            'month out of range' => ['2026-13-01'],
            'day out of range' => ['2026-02-30'],
            'with a time' => ['2026-10-20 10:00'],
            'empty' => [''],
            'not a string' => [20261020],
            'missing' => [null],
        ];
    }

    #[DataProvider('malformedUntil')]
    public function test_a_malformed_until_is_refused(mixed $until): void
    {
        [$owner, $team] = $this->ownedTeam();
        $this->subscription($team, 'trialing', trialEndsAt: $this->now()->addDays(3));
        $conversation = $this->conversation($owner, $team);

        $sentence = $this->record(
            $conversation,
            SupportProposalAction::EXTEND_TRIAL,
            $until === null ? [] : ['until' => $until],
        );

        $this->assertStringStartsNotWith('Recorded:', $sentence);
        $this->assertStringContainsString('YYYY-MM-DD', $sentence);
        $this->assertSame(0, SupportProposal::query()->count());
    }

    public function test_the_user_billable_config_resolves_the_opener_not_a_team(): void
    {
        // The subscriptions table keys on the billable, so rebuild it for user billing.
        config()->set('magic-starter.billing.billable', 'user');
        Schema::drop('subscription_items');
        Schema::drop('subscriptions');

        foreach ([
            'create_subscriptions_table.php',
            'create_subscription_items_table.php',
        ] as $migration) {
            (require __DIR__ . '/../../database/migrations/' . $migration)->up();
        }

        [$owner, $team] = $this->ownedTeam();
        $member = $this->user('member@example.com');
        $member->teams()->attach($team->getKey(), [
            'role' => 'member',
        ]);
        $this->subscription($member, trialEndsAt: null);
        $conversation = $this->conversation($member, $team);

        $sentence = $this->record($conversation, SupportProposalAction::CANCEL_AT_PERIOD_END, ['until' => 'x']);

        $this->assertSame($this->recorded('cancel at the end of the period'), $sentence);

        $proposal = SupportProposal::query()->sole();
        $this->assertSame($member->getMorphClass(), $proposal->billable_type);
        $this->assertSame((string) $member->getKey(), (string) $proposal->billable_id);
        $this->assertSame((string) $member->getKey(), (string) $proposal->requested_by);
        $this->assertSame([], $proposal->params);
    }

    public function test_a_retry_while_still_a_draft_keeps_the_latest_call_in_one_row(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $this->subscription($team, 'trialing', trialEndsAt: $this->now()->addDays(3));
        $conversation = $this->conversation($owner, $team);
        $trigger = $this->message($conversation);

        $this->assertSame(
            $this->recorded('extend the trial to 2026-10-25'),
            $this->record(
                $conversation,
                SupportProposalAction::EXTEND_TRIAL,
                ['until' => '2026-10-25'],
                'First try.',
                $trigger->getKey(),
            ),
        );
        $this->assertSame(
            $this->recorded('extend the trial to 2026-10-27'),
            $this->record(
                $conversation,
                SupportProposalAction::EXTEND_TRIAL,
                ['until' => '2026-10-27'],
                'Second try.',
                $trigger->getKey(),
            ),
        );

        $proposal = SupportProposal::query()->sole();
        $this->assertSame(SupportProposalStatus::DRAFT, $proposal->status);
        $this->assertSame(['until' => '2026-10-27'], $proposal->params);
        $this->assertSame('Second try.', $proposal->rationale);
    }

    public function test_a_retry_after_the_turn_landed_leaves_the_promoted_row_and_echoes_it(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $this->subscription($team, 'trialing', trialEndsAt: $this->now()->addDays(3));
        $conversation = $this->conversation($owner, $team);
        $trigger = $this->message($conversation);

        $this->record(
            $conversation,
            SupportProposalAction::EXTEND_TRIAL,
            ['until' => '2026-10-27'],
            'What staff see.',
            $trigger->getKey(),
        );
        SupportProposal::promoteDrafts($conversation, $trigger->getKey());

        $this->assertSame(
            $this->recorded('extend the trial to 2026-10-27'),
            $this->record(
                $conversation,
                SupportProposalAction::EXTEND_TRIAL,
                ['until' => '2026-10-20'],
                'A late retry.',
                $trigger->getKey(),
            ),
        );

        $proposal = SupportProposal::query()->sole();
        $this->assertSame(SupportProposalStatus::PENDING, $proposal->status);
        $this->assertSame(['until' => '2026-10-27'], $proposal->params);
        $this->assertSame('What staff see.', $proposal->rationale);
    }

    public function test_an_executing_row_blocks_a_new_one_for_the_same_action(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $this->subscription($team);
        $conversation = $this->conversation($owner, $team);
        $earlier = $this->message($conversation);
        $later = $this->message($conversation);
        $this->proposal($conversation, $team, $earlier, SupportProposalAction::CANCEL_AT_PERIOD_END, [
            'status' => SupportProposalStatus::EXECUTING,
            'expires_at' => $this->now()->subHour(),
        ]);

        $sentence = $this->record(
            $conversation,
            SupportProposalAction::CANCEL_AT_PERIOD_END,
            [],
            null,
            $later->getKey(),
        );

        $this->assertSame(self::ALREADY_WAITING, $sentence);
        $this->assertSame(1, SupportProposal::query()->count());
    }

    public function test_an_unexpired_pending_row_blocks_a_new_one_for_the_same_action_only(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $this->subscription($team);
        $conversation = $this->conversation($owner, $team);
        $earlier = $this->message($conversation);
        $later = $this->message($conversation);
        $this->proposal($conversation, $team, $earlier, SupportProposalAction::CANCEL_AT_PERIOD_END, [
            'status' => SupportProposalStatus::PENDING,
            'expires_at' => $this->now()->addHour(),
        ]);

        $sentence = $this->record(
            $conversation,
            SupportProposalAction::CANCEL_AT_PERIOD_END,
            [],
            null,
            $later->getKey(),
        );

        $this->assertSame(self::ALREADY_WAITING, $sentence);
        $this->assertSame(1, SupportProposal::query()->count());
    }

    public function test_an_expired_pending_row_and_a_stale_draft_of_another_trigger_do_not_block(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $this->subscription($team);
        $conversation = $this->conversation($owner, $team);
        $first = $this->message($conversation);
        $second = $this->message($conversation);
        $current = $this->message($conversation);
        $this->proposal($conversation, $team, $first, SupportProposalAction::CANCEL_AT_PERIOD_END, [
            'status' => SupportProposalStatus::PENDING,
            'expires_at' => $this->now()->subMinute(),
        ]);
        $this->proposal($conversation, $team, $second, SupportProposalAction::CANCEL_AT_PERIOD_END, [
            'status' => SupportProposalStatus::DRAFT,
            'expires_at' => $this->now()->addHours(10),
        ]);

        $sentence = $this->record(
            $conversation,
            SupportProposalAction::CANCEL_AT_PERIOD_END,
            [],
            null,
            $current->getKey(),
        );

        $this->assertSame($this->recorded('cancel at the end of the period'), $sentence);
        $this->assertSame(
            1,
            SupportProposal::query()->where('trigger_message_id', $current->getKey())->count(),
        );
    }

    public function test_the_trigger_recorded_is_the_id_passed_in_not_the_newer_last_customer_message_id(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $this->subscription($team);
        $conversation = $this->conversation($owner, $team);
        $turn = $this->message($conversation);
        $newer = $this->message($conversation);
        $conversation->forceFill([
            'last_customer_message_id' => $newer->getKey(),
        ])->save();

        $this->record($conversation, SupportProposalAction::CANCEL_AT_PERIOD_END, [], null, $turn->getKey());

        $this->assertSame((string) $turn->getKey(), (string) SupportProposal::query()->sole()->trigger_message_id);
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function disabledFeatures(): array
    {
        return [
            'proposals off' => [['teams', 'support', 'billing']],
            'billing off' => [['teams', 'support', 'support-proposals']],
        ];
    }

    /**
     * @param  list<string>  $features
     */
    #[DataProvider('disabledFeatures')]
    public function test_with_the_feature_or_billing_off_it_refuses_and_writes_nothing(array $features): void
    {
        [$owner, $team] = $this->ownedTeam();
        $this->subscription($team);
        $conversation = $this->conversation($owner, $team);
        config()->set('magic-starter.features', $features);

        $sentence = $this->record($conversation, SupportProposalAction::CANCEL_AT_PERIOD_END);

        $this->assertSame(self::UNAVAILABLE, $sentence);
        $this->assertSame(0, SupportProposal::query()->count());
    }

    public function test_a_visitor_without_an_account_is_refused(): void
    {
        $conversation = SupportConversation::create([
            'email' => 'visitor@example.com',
            'subject' => 'Cancel my plan',
            'source' => SupportConversationSource::IN_APP,
            'status' => SupportConversationStatus::PENDING,
        ]);

        $sentence = $this->record($conversation, SupportProposalAction::CANCEL_AT_PERIOD_END);

        $this->assertStringStartsNotWith('Recorded:', $sentence);
        $this->assertStringContainsString('account', $sentence);
        $this->assertSame(0, SupportProposal::query()->count());
    }

    public function test_an_opener_outside_the_conversation_team_has_no_billable(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $stranger = $this->user('stranger@example.com');
        $this->subscription($team);

        $sentence = $this->record(
            $this->conversation($stranger, $team),
            SupportProposalAction::CANCEL_AT_PERIOD_END,
        );

        $this->assertStringStartsNotWith('Recorded:', $sentence);
        $this->assertNotSame(self::OWNER_ONLY, $sentence);
        $this->assertSame(0, SupportProposal::query()->count());
    }

    /**
     * @return array<string, array{SupportProposalAction, string, CarbonImmutable|null, CarbonImmutable|null}>
     */
    public static function railRefusals(): array
    {
        $now = CarbonImmutable::parse('2026-10-10 12:00:00');

        return [
            'extend off trial' => [SupportProposalAction::EXTEND_TRIAL, 'active', null, null],
            'extend a cancelled trial' => [
                SupportProposalAction::EXTEND_TRIAL,
                'trialing',
                $now->addDays(3),
                $now->addDays(3),
            ],
            'cancel a cancelled subscription' => [
                SupportProposalAction::CANCEL_AT_PERIOD_END,
                'active',
                null,
                $now->addDays(5),
            ],
            'cancel an ended subscription' => [
                SupportProposalAction::CANCEL_AT_PERIOD_END,
                'canceled',
                null,
                $now->subDay(),
            ],
            'resume a running subscription' => [SupportProposalAction::RESUME, 'active', null, null],
            'resume an ended subscription' => [SupportProposalAction::RESUME, 'canceled', null, $now->subDay()],
        ];
    }

    #[DataProvider('railRefusals')]
    public function test_the_rail_state_refuses_a_change_it_cannot_take(
        SupportProposalAction $action,
        string $status,
        ?CarbonImmutable $trialEndsAt,
        ?CarbonImmutable $endsAt,
    ): void {
        [$owner, $team] = $this->ownedTeam();
        $this->subscription($team, $status, $trialEndsAt, $endsAt);
        $conversation = $this->conversation($owner, $team);

        $sentence = $this->record($conversation, $action, ['until' => '2026-10-20']);

        $this->assertStringStartsNotWith('Recorded:', $sentence);
        $this->assertSame(0, SupportProposal::query()->count());
    }

    public function test_an_account_without_a_card_subscription_is_refused(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $conversation = $this->conversation($owner, $team);

        $sentence = $this->record($conversation, SupportProposalAction::CANCEL_AT_PERIOD_END);

        $this->assertStringContainsString('subscription', $sentence);
        $this->assertSame(0, SupportProposal::query()->count());
    }

    public function test_a_cancelled_subscription_inside_its_period_records_a_resume(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $this->subscription($team, endsAt: $this->now()->addDays(5));
        $conversation = $this->conversation($owner, $team);

        $this->assertSame(
            $this->recorded('resume the subscription'),
            $this->record($conversation, SupportProposalAction::RESUME),
        );
        $this->assertSame(SupportProposalAction::RESUME, SupportProposal::query()->sole()->action);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function record(
        SupportConversation $conversation,
        SupportProposalAction $action,
        array $params = [],
        ?string $rationale = null,
        int|string|null $triggerMessageId = null,
    ): string {
        return $this->app->make(RecordsSupportProposals::class)->record(
            $conversation,
            $action,
            $params,
            $rationale,
            $triggerMessageId,
        );
    }

    /**
     * The success sentence, naming what is stored.
     */
    private function recorded(string $what): string
    {
        return "Recorded: {$what}." . self::RECORDED_TAIL;
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-10 12:00:00');
    }

    private function user(string $email): ProposalUser
    {
        return ProposalUser::create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => 'secret',
        ]);
    }

    /**
     * @return array{ProposalUser, ProposalTeam}
     */
    private function ownedTeam(): array
    {
        $owner = $this->user('owner@example.com');
        $team = ProposalTeam::create([
            'user_id' => $owner->getKey(),
            'name' => 'Acme',
            'personal_team' => false,
        ]);

        return [$owner, $team];
    }

    private function subscription(
        ProposalUser|ProposalTeam $billable,
        string $status = 'active',
        ?CarbonImmutable $trialEndsAt = null,
        ?CarbonImmutable $endsAt = null,
    ): Subscription {
        $subscription = new Subscription;

        $subscription->forceFill([
            $billable->getForeignKey() => $billable->getKey(),
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

    private function conversation(ProposalUser $opener, ?ProposalTeam $team): SupportConversation
    {
        return SupportConversation::create([
            'user_id' => $opener->getKey(),
            'team_id' => $team?->getKey(),
            'email' => $opener->getAttribute('email'),
            'subject' => 'About my plan',
            'source' => SupportConversationSource::IN_APP,
            'status' => SupportConversationStatus::PENDING,
        ]);
    }

    private function message(SupportConversation $conversation): SupportMessage
    {
        return $conversation->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'Can you change my plan?',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function proposal(
        SupportConversation $conversation,
        ProposalTeam $team,
        SupportMessage $trigger,
        SupportProposalAction $action,
        array $attributes,
    ): SupportProposal {
        return SupportProposal::create([
            'conversation_id' => $conversation->getKey(),
            'billable_type' => $team->getMorphClass(),
            'billable_id' => $team->getKey(),
            'action' => $action,
            'params' => [],
            'trigger_message_id' => $trigger->getKey(),
            ...$attributes,
        ]);
    }
}

/**
 * A user model carrying Cashier's trait, so user billing has a card rail.
 */
class ProposalUser extends ConcreteUser
{
    use Billable;

    protected $table = 'users';

    public function getForeignKey(): string
    {
        return 'user_id';
    }
}

/**
 * A team model carrying Cashier's trait, so team billing has a card rail.
 */
class ProposalTeam extends Team
{
    use Billable;

    protected $table = 'teams';

    protected $fillable = [
        'name',
        'personal_team',
        'user_id',
    ];

    public function getForeignKey(): string
    {
        return 'team_id';
    }
}
