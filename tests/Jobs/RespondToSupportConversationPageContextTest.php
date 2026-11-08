<?php

namespace FlutterSdk\MagicStarter\Tests\Jobs;

use Closure;
use FlutterSdk\MagicStarter\Contracts\DescribesSupportPage;
use FlutterSdk\MagicStarter\Contracts\PostsCustomerSupportMessages;
use FlutterSdk\MagicStarter\Contracts\RespondsToSupport;
use FlutterSdk\MagicStarter\Enums\SupportAiPool;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
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
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAnswer;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportTurn;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportUsage;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;

/**
 * The page a customer wrote from, as the host describes it to the AI desk.
 *
 * The refs stored on the trigger are sanitised again before the host sees
 * them, the host is asked only when it bound {@see DescribesSupportPage}, and a
 * host that throws is reported and costs the turn nothing but the context.
 */
class RespondToSupportConversationPageContextTest extends TestCase
{
    private const REFS = [
        [
            'kind' => 'monitor',
            'id' => '42',
        ],
        [
            'kind' => 'incident',
            'id' => '6f1d1c55-4a35-4f0c-9d1f-4a3e5f7b2c10',
        ],
    ];

    private const DESCRIBED = [
        [
            'kind' => 'monitor',
            'name' => 'API',
            'status' => 'down',
        ],
    ];

    /**
     * The turns the responder was asked to answer.
     *
     * @var list<SupportTurn>
     */
    private array $turns = [];

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
        $app['config']->set('magic-starter.support.page_context', [
            'kinds' => [
                'monitor',
                'incident',
            ],
            'max_refs' => 3,
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
        $this->bindResponder();
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_the_host_describes_the_stored_refs_and_the_responder_receives_the_result(): void
    {
        $calls = [];
        $this->bindDescriber(static function (SupportConversation $conversation, array $refs) use (&$calls): array {
            $calls[] = [
                $conversation->getKey(),
                $refs,
            ];

            return self::DESCRIBED;
        });
        [$conversation, $trigger] = $this->pendingTurn(self::REFS);

        $this->runTurn($conversation, $trigger);

        $this->assertSame([
            [
                $conversation->getKey(),
                self::REFS,
            ],
        ], $calls);
        $this->assertCount(1, $this->turns);
        $this->assertSame(self::DESCRIBED, $this->turns[0]->pageContext);
        $this->assertAnswered();
    }

    public function test_the_refs_of_a_reply_reach_the_host_through_the_turn(): void
    {
        $this->bindDescriber(static fn (): array => self::DESCRIBED);
        [$conversation] = $this->pendingTurn(null);
        $customer = ConcreteUser::query()->sole();
        Bus::fake();

        app(PostsCustomerSupportMessages::class)->post(
            $conversation,
            'Still red.',
            $customer,
            self::REFS,
        );

        Bus::assertDispatched(RespondToSupportConversation::class);
        $reply = SupportMessage::query()->where('body', 'Still red.')->sole();
        $this->assertSame(self::REFS, $reply->meta['page_context'] ?? null);

        $this->runTurn($conversation->refresh(), $reply);

        $this->assertCount(1, $this->turns);
        $this->assertSame(self::DESCRIBED, $this->turns[0]->pageContext);
    }

    public function test_a_describer_answer_longer_than_the_refs_is_cut_to_one_entry_per_ref(): void
    {
        $this->bindDescriber(static fn (): array => array_map(
            static fn (int $index): array => [
                'kind' => 'monitor',
                'name' => "Entry {$index}",
            ],
            range(1, 10),
        ));
        [$conversation, $trigger] = $this->pendingTurn(self::REFS);

        $this->runTurn($conversation, $trigger);

        $this->assertCount(1, $this->turns);
        $this->assertCount(count(self::REFS), $this->turns[0]->pageContext);
        $this->assertSame('Entry 1', $this->turns[0]->pageContext[0]['name']);
    }

    public function test_an_unbound_describer_leaves_the_page_context_empty(): void
    {
        $this->assertFalse($this->app->bound(DescribesSupportPage::class));
        [$conversation, $trigger] = $this->pendingTurn(self::REFS);

        $this->runTurn($conversation, $trigger);

        $this->assertCount(1, $this->turns);
        $this->assertSame([], $this->turns[0]->pageContext);
        $this->assertAnswered();
    }

    public function test_a_trigger_without_refs_never_asks_the_host(): void
    {
        $this->bindDescriber(static fn (): never => throw new RuntimeException('Must not be called.'));
        [$conversation, $trigger] = $this->pendingTurn(null);

        $this->runTurn($conversation, $trigger);

        $this->assertSame([], $this->turns[0]->pageContext);
        $this->assertAnswered();
    }

    public function test_a_describer_that_throws_is_reported_and_the_turn_is_still_answered(): void
    {
        $failure = new RuntimeException('Monitor lookup failed.');
        $this->bindDescriber(static fn (): never => throw $failure);
        $this->mock(ExceptionHandler::class, function (MockInterface $handler) use ($failure): void {
            $handler->shouldReceive('report')->once()->with(Mockery::on(
                static fn (mixed $reported): bool => $reported === $failure,
            ));
        });
        [$conversation, $trigger] = $this->pendingTurn(self::REFS);

        $this->runTurn($conversation, $trigger);

        $this->assertCount(1, $this->turns);
        $this->assertSame([], $this->turns[0]->pageContext);
        $this->assertAnswered();
    }

    public function test_a_stored_ref_whose_kind_is_no_longer_allowed_never_reaches_the_host(): void
    {
        $seen = [];
        $this->bindDescriber(static function (SupportConversation $conversation, array $refs) use (&$seen): array {
            $seen[] = $refs;

            return self::DESCRIBED;
        });
        [$conversation, $trigger] = $this->pendingTurn(self::REFS);

        config()->set('magic-starter.support.page_context.kinds', [
            'incident',
        ]);
        $this->runTurn($conversation, $trigger);

        $this->assertSame([
            [
                self::REFS[1],
            ],
        ], $seen);
    }

    public function test_refs_of_kinds_all_switched_off_since_never_reach_the_host(): void
    {
        $this->bindDescriber(static fn (): never => throw new RuntimeException('Must not be called.'));
        [$conversation, $trigger] = $this->pendingTurn(self::REFS);

        config()->set('magic-starter.support.page_context.kinds', []);
        $this->runTurn($conversation, $trigger);

        $this->assertSame([], $this->turns[0]->pageContext);
        $this->assertAnswered();
    }

    private function assertAnswered(): void
    {
        $this->assertSame(
            1,
            SupportMessage::query()->where('sender_type', SupportSenderType::AI->value)->count(),
        );
    }

    private function bindDescriber(Closure $describe): void
    {
        $this->app->instance(DescribesSupportPage::class, new class($describe) implements DescribesSupportPage
        {
            public function __construct(
                private readonly Closure $describe,
            ) {}

            public function describe(SupportConversation $conversation, array $refs): array
            {
                return ($this->describe)($conversation, $refs);
            }
        });
    }

    private function bindResponder(): void
    {
        $turns = &$this->turns;
        $this->app->instance(RespondsToSupport::class, new class($turns) implements RespondsToSupport
        {
            /**
             * @param  list<SupportTurn>  $turns
             */
            public function __construct(
                private array &$turns,
            ) {}

            public function respond(SupportTurn $turn): SupportAnswer
            {
                $this->turns[] = $turn;

                return new SupportAnswer('Your API monitor is down since noon.', 0.9, new SupportUsage(300, 100));
            }

            public function pool(SupportConversation $conversation): SupportAiPool
            {
                return SupportAiPool::FREE;
            }
        });
    }

    private function runTurn(SupportConversation $conversation, SupportMessage $trigger): void
    {
        $job = new RespondToSupportConversation($conversation->getKey(), $trigger->getKey());
        $job->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);
    }

    /**
     * @param  list<array{kind: string, id: string}>|null  $refs  stored as the trigger's `meta.page_context`
     * @return array{SupportConversation, SupportMessage}
     */
    private function pendingTurn(?array $refs): array
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
            'subject' => 'Monitor down',
            'source' => SupportConversationSource::IN_APP,
            'status' => SupportConversationStatus::PENDING,
        ]);
        $trigger = $conversation->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'Why is this red?',
            'meta' => $refs === null ? null : [
                'page_context' => $refs,
            ],
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
