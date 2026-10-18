<?php

namespace FlutterSdk\MagicStarter\Tests\Jobs;

use Closure;
use FlutterSdk\MagicStarter\Contracts\RecordsSupportProposals;
use FlutterSdk\MagicStarter\Contracts\RespondsToSupport;
use FlutterSdk\MagicStarter\Enums\SupportAiPool;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportProposalAction;
use FlutterSdk\MagicStarter\Enums\SupportProposalStatus;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Events\SupportConversationUpdated;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Jobs\RespondToSupportConversation;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\Subscription;
use FlutterSdk\MagicStarter\Models\SubscriptionItem;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Models\SupportProposal;
use FlutterSdk\MagicStarter\Models\Team;
use FlutterSdk\MagicStarter\Notifications\SupportConversationNeedsStaffNotification;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAnswer;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportTurn;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportUsage;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;

/**
 * A billing proposal the AI desk records during a turn stays a hidden draft
 * until that turn's answer is stored; only then does it become pending and
 * staff are told, once, with the `proposal` reason. A turn that never lands
 * leaves its draft where nobody sees it.
 *
 * The fake responder calls the real record contract inside `respond()`, which
 * is where the model's tool call runs, against a team owner on a card
 * subscription so the draft is actually written.
 */
class RespondToSupportConversationProposalsTest extends TestCase
{
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
            'magic-starter.billing.tier_order' => [
                'free',
                'pro',
            ],
            'magic-starter.billing.products' => [
                'pro_monthly' => [
                    'type' => 'subscription',
                    'tier' => 'pro',
                    'cycle' => 'monthly',
                    'refs' => [
                        'stripe_price' => 'price_pro',
                    ],
                ],
            ],
            'magic-starter.billing.revenuecat.secret_api_key' => null,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(ProposalTurnUser::class);
        MagicStarter::useTeamModel(ProposalTurnTeam::class);
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);

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

        Notification::fake();
        Event::fake([
            SupportConversationNeedsStaff::class,
            SupportConversationUpdated::class,
        ]);
    }

    protected function tearDown(): void
    {
        Cashier::useSubscriptionModel(CashierSubscription::class);
        Cashier::useSubscriptionItemModel(CashierSubscriptionItem::class);
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_a_landed_turn_promotes_its_draft_and_alerts_staff_once_with_the_proposal_reason(): void
    {
        [$conversation, $trigger] = $this->pendingTurn();
        $this->bindResponder(function (SupportTurn $turn): SupportAnswer {
            $this->recordDraft($turn);

            return $this->answer();
        });

        $this->runTurn($conversation, $trigger);

        $this->assertSame(SupportProposalStatus::PENDING, SupportProposal::query()->sole()->status);
        $this->assertSame(1, $this->aiReplies());
        Event::assertDispatchedTimes(SupportConversationNeedsStaff::class, 1);
        Event::assertDispatched(
            SupportConversationNeedsStaff::class,
            fn (SupportConversationNeedsStaff $event): bool => $event->reason === 'proposal'
                && (string) $event->conversation->getKey() === (string) $conversation->getKey(),
        );
    }

    public function test_a_turn_that_loses_ownership_leaves_the_draft_hidden_and_alerts_nobody(): void
    {
        [$conversation, $trigger] = $this->pendingTurn();
        $this->bindResponder(function (SupportTurn $turn) use ($conversation): SupportAnswer {
            $this->recordDraft($turn);

            // The customer writes again while the model thinks: the turn moves on to the newer message.
            $newer = $this->customerMessage($conversation, 'One more thing.');
            $conversation->forceFill([
                'last_customer_message_id' => $newer->getKey(),
            ])->save();

            return $this->answer();
        });

        $this->runTurn($conversation, $trigger);

        $this->assertSame(SupportProposalStatus::DRAFT, SupportProposal::query()->sole()->status);
        $this->assertSame(0, $this->aiReplies());
        Event::assertNotDispatched(SupportConversationNeedsStaff::class);
    }

    public function test_a_turn_without_a_draft_fires_no_proposal_alert(): void
    {
        [$conversation, $trigger] = $this->pendingTurn();
        $this->bindResponder(fn (): SupportAnswer => $this->answer());

        $this->runTurn($conversation, $trigger);

        $this->assertSame(1, $this->aiReplies());
        $this->assertSame(0, SupportProposal::query()->count());
        Event::assertNotDispatched(SupportConversationNeedsStaff::class);
    }

    public function test_with_the_feature_off_when_the_turn_lands_the_draft_is_not_promoted(): void
    {
        [$conversation, $trigger] = $this->pendingTurn();
        $this->bindResponder(function (SupportTurn $turn): SupportAnswer {
            $this->recordDraft($turn);

            // The flag is read where the answer is stored, so switching it off now must stop the promotion.
            config()->set('magic-starter.features', [
                Features::teams(),
                Features::support(),
                Features::billing(),
            ]);

            return $this->answer();
        });

        $this->runTurn($conversation, $trigger);

        $this->assertSame(1, $this->aiReplies());
        $this->assertSame(SupportProposalStatus::DRAFT, SupportProposal::query()->sole()->status);
        Event::assertNotDispatched(SupportConversationNeedsStaff::class);
    }

    public function test_the_staff_notification_renders_the_proposal_reason_line(): void
    {
        [$conversation] = $this->pendingTurn();
        $staff = $this->user('staff@example.com');
        $notification = new SupportConversationNeedsStaffNotification($conversation, 'proposal');

        $expected = Lang::get('magic-starter::admin_support.notifications.staff.proposal');

        $this->assertSame(
            "The AI desk recorded a billing request that needs a teammate's approval.",
            $expected,
        );
        $this->assertSame(
            [
                $expected,
                Lang::get('magic-starter::admin_support.notifications.staff.open_panel'),
            ],
            $notification->toMail($staff)->introLines,
        );
        $this->assertNotSame(
            'magic-starter::admin_support.notifications.staff.proposal',
            Lang::get('magic-starter::admin_support.notifications.staff.proposal', [], 'tr'),
        );
    }

    private function bindResponder(Closure $respond): void
    {
        $this->app->instance(RespondsToSupport::class, new class($respond) implements RespondsToSupport
        {
            public function __construct(
                private readonly Closure $respond,
            ) {}

            public function respond(SupportTurn $turn): SupportAnswer
            {
                return ($this->respond)($turn);
            }

            public function pool(SupportConversation $conversation): SupportAiPool
            {
                return SupportAiPool::FREE;
            }
        });
    }

    /**
     * What the model's tool call does mid-turn: record a draft for the turn's own trigger.
     */
    private function recordDraft(SupportTurn $turn): void
    {
        $sentence = $this->app->make(RecordsSupportProposals::class)->record(
            $turn->conversation,
            SupportProposalAction::CANCEL_AT_PERIOD_END,
            [],
            'The customer asked to stop at the end of the period.',
            $turn->trigger->getKey(),
        );

        $this->assertStringStartsWith('Recorded.', $sentence);
    }

    private function answer(): SupportAnswer
    {
        return new SupportAnswer(
            'A teammate will confirm the cancellation here.',
            0.9,
            new SupportUsage(300, 100),
        );
    }

    private function runTurn(SupportConversation $conversation, SupportMessage $trigger): void
    {
        $this->app->call([
            new RespondToSupportConversation($conversation->getKey(), $trigger->getKey()),
            'handle',
        ]);
    }

    /**
     * A pending conversation whose newest customer message is owed an answer, opened by a team owner on a card
     * subscription.
     *
     * @return array{SupportConversation, SupportMessage}
     */
    private function pendingTurn(): array
    {
        $owner = $this->user('owner@example.com');
        $team = ProposalTurnTeam::create([
            'user_id' => $owner->getKey(),
            'name' => 'Acme',
            'personal_team' => false,
        ]);
        $this->subscription($team);

        $conversation = SupportConversation::create([
            'user_id' => $owner->getKey(),
            'team_id' => $team->getKey(),
            'email' => $owner->getAttribute('email'),
            'subject' => 'About my plan',
            'source' => SupportConversationSource::IN_APP,
            'status' => SupportConversationStatus::PENDING,
        ]);
        $trigger = $this->customerMessage($conversation, 'Can you stop my subscription from renewing next month?');
        $conversation->forceFill([
            'last_customer_message_id' => $trigger->getKey(),
        ])->save();

        return [
            $conversation,
            $trigger,
        ];
    }

    private function customerMessage(SupportConversation $conversation, string $body): SupportMessage
    {
        return $conversation->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => $body,
        ]);
    }

    private function user(string $email): ProposalTurnUser
    {
        return ProposalTurnUser::create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => now(),
        ]);
    }

    private function subscription(ProposalTurnTeam $team): void
    {
        (new Subscription)->forceFill([
            'team_id' => $team->getKey(),
            'type' => 'default',
            'stripe_id' => 'sub_' . Str::random(10),
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ])->save();
    }

    private function aiReplies(): int
    {
        return SupportMessage::query()->where('sender_type', SupportSenderType::AI->value)->count();
    }
}

/**
 * A user model carrying Cashier's trait, as the record contract reads the billable's card rail.
 */
class ProposalTurnUser extends ConcreteUser
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
class ProposalTurnTeam extends Team
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
