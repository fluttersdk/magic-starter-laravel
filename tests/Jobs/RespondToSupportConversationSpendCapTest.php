<?php

namespace FlutterSdk\MagicStarter\Tests\Jobs;

use FlutterSdk\MagicStarter\Contracts\CapsSupportSpend;
use FlutterSdk\MagicStarter\Contracts\RespondsToSupport;
use FlutterSdk\MagicStarter\Enums\SupportAiPool;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportHandoffReason;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Events\SupportConversationUpdated;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Jobs\RespondToSupportConversation;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAiQuota;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAnswer;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportTurn;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportUsage;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use Mockery\MockInterface;

/**
 * The host's optional daily spend cap.
 *
 * Bound and spent, it hands the turn to staff as `quota` before the quota
 * reserves anything and before any model is called, so nothing is left
 * unsettled and nothing is spent. Unbound or not spent, the turn runs as ever.
 */
class RespondToSupportConversationSpendCapTest extends TestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            MagicStarterServiceProvider::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::support(),
        ]);
        $app['config']->set('auth.providers.users.model', ConcreteUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('current_team_id')->nullable();
            $table->string('locale')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->timestamps();
        });

        foreach ([
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
            'add_ai_columns_to_support_tables.php',
            'create_support_message_attachments_table.php',
            'add_ai_summary_to_support_conversations_table.php',
        ] as $file) {
            (require __DIR__ . '/../../database/migrations/' . $file)->up();
        }

        Notification::fake();
        Event::fake([
            SupportConversationNeedsStaff::class,
            SupportConversationUpdated::class,
        ]);
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_a_spent_cap_hands_the_turn_off_as_quota_without_a_model_call_or_a_reservation(): void
    {
        $this->partialMock(SupportAiQuota::class, function (MockInterface $quota): void {
            $quota->shouldReceive('reserve')->never();
            $quota->shouldReceive('reconcile')->never();
        });
        $this->bindCap(true);
        $responder = $this->bindResponder();
        [$conversation, $trigger] = $this->pendingTurn();

        $this->runTurn($conversation, $trigger);

        $this->assertSame(0, $responder->turns);
        $conversation->refresh();
        $this->assertSame(SupportConversationStatus::OPEN, $conversation->status);
        $this->assertSame(SupportHandoffReason::QUOTA, $conversation->handoff_reason);
        $this->assertSame(0, SupportMessage::query()->where('sender_type', SupportSenderType::AI->value)->count());
        Event::assertDispatched(
            SupportConversationNeedsStaff::class,
            static fn (SupportConversationNeedsStaff $event): bool => $event->reason === 'handoff',
        );
    }

    public function test_a_cap_with_room_left_lets_the_turn_run(): void
    {
        $this->bindCap(false);
        $responder = $this->bindResponder();
        [$conversation, $trigger] = $this->pendingTurn();

        $this->runTurn($conversation, $trigger);

        $this->assertSame(1, $responder->turns);
        $this->assertSame(SupportConversationStatus::PENDING, $conversation->refresh()->status);
        $this->assertSame(1, SupportMessage::query()->where('sender_type', SupportSenderType::AI->value)->count());
    }

    public function test_without_a_cap_bound_the_turn_runs(): void
    {
        $this->assertFalse($this->app->bound(CapsSupportSpend::class));
        $responder = $this->bindResponder();
        [$conversation, $trigger] = $this->pendingTurn();

        $this->runTurn($conversation, $trigger);

        $this->assertSame(1, $responder->turns);
    }

    private function bindCap(bool $exceeded): void
    {
        $this->app->instance(CapsSupportSpend::class, new class($exceeded) implements CapsSupportSpend
        {
            public function __construct(
                private readonly bool $exceeded,
            ) {}

            public function exceeded(): bool
            {
                return $this->exceeded;
            }
        });
    }

    /**
     * A responder that counts its turns; `turns` is how many times a model would have been called.
     */
    private function bindResponder(): object
    {
        $responder = new class implements RespondsToSupport
        {
            public int $turns = 0;

            public function respond(SupportTurn $turn): SupportAnswer
            {
                $this->turns++;

                return new SupportAnswer('Your plan renews on the 1st.', 0.9, new SupportUsage(300, 100));
            }

            public function pool(SupportConversation $conversation): SupportAiPool
            {
                return SupportAiPool::FREE;
            }
        };
        $this->app->instance(RespondsToSupport::class, $responder);

        return $responder;
    }

    private function runTurn(SupportConversation $conversation, SupportMessage $trigger): void
    {
        $job = new RespondToSupportConversation($conversation->getKey(), $trigger->getKey());
        $job->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);
    }

    /**
     * @return array{SupportConversation, SupportMessage}
     */
    private function pendingTurn(): array
    {
        $customer = ConcreteUser::create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'secret',
            'email_verified_at' => now(),
        ]);
        $conversation = SupportConversation::create([
            'user_id' => $customer->getKey(),
            'email' => 'ada@example.com',
            'subject' => 'Renewal',
            'source' => SupportConversationSource::IN_APP,
            'status' => SupportConversationStatus::PENDING,
        ]);
        $trigger = $conversation->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'When does my plan renew?',
        ]);
        $conversation->forceFill([
            'last_customer_message_id' => $trigger->getKey(),
        ])->save();

        return [
            $conversation,
            $trigger,
        ];
    }
}
