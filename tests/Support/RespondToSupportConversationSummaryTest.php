<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use Closure;
use FlutterSdk\MagicStarter\Contracts\OpensSupportConversations;
use FlutterSdk\MagicStarter\Contracts\PostsCustomerSupportMessages;
use FlutterSdk\MagicStarter\Contracts\RespondsToSupport;
use FlutterSdk\MagicStarter\Enums\SupportAiPool;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportHandoffReason;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
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
use FlutterSdk\MagicStarter\Support\SupportAi\SupportHistorySummary;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportTurn;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportUsage;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;

/**
 * The stored history summary as the responder job reads and writes it.
 *
 * A turn sees the summary and only the messages after its boundary, compared
 * as the tuple (created_at, id) so a message written in the boundary's second
 * lands on the right side. A compaction the responder returns is stored with
 * the answer, and only while the boundary is still the one the turn read: a
 * turn that lost the race, failed, or handed off leaves the summary alone.
 *
 * Each thread is built by hand with explicit timestamps and keys, so the
 * ordering under test never depends on the clock or on how keys are minted.
 */
class RespondToSupportConversationSummaryTest extends TestCase
{
    private const START = '2026-10-13 10:00:00';

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
            $migration = require __DIR__ . '/../../database/migrations/' . $file;
            $migration->up();
        }

        Queue::fake();
        Notification::fake();
        Event::fake([
            SupportConversationNeedsStaff::class,
            SupportConversationUpdated::class,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        MagicStarter::reset();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // What the turn reads
    // -----------------------------------------------------------------

    public function test_without_a_summary_the_turn_carries_the_history_exactly_as_before(): void
    {
        $responder = $this->bindResponder();
        [$conversation] = $this->thread();
        $trigger = $this->message($conversation, 'Third question.', '10:03:00');

        $this->answer($conversation, $trigger);

        $turn = $responder->turns[0];
        $this->assertSame([
            'Hello, I have a question.',
            'First follow-up.',
            'Second follow-up.',
        ], $this->bodies($turn));
        $this->assertNull($turn->summary);
        $this->assertNull($turn->summaryThroughId);
        $this->assertFalse($turn->historyTruncated);
    }

    public function test_with_a_boundary_only_the_messages_after_it_are_history_and_the_summary_rides_along(): void
    {
        $responder = $this->bindResponder();
        [$conversation, , $first] = $this->thread();
        $trigger = $this->message($conversation, 'Third question.', '10:03:00');
        $this->summarise($conversation, 'The customer asked how to add a monitor.', $first->getKey());

        $this->answer($conversation, $trigger);

        $turn = $responder->turns[0];
        $this->assertSame([
            'Second follow-up.',
        ], $this->bodies($turn));
        $this->assertSame('The customer asked how to add a monitor.', $turn->summary);
        $this->assertSame((string) $first->getKey(), (string) $turn->summaryThroughId);
        $this->assertFalse($turn->historyTruncated);
    }

    public function test_a_message_in_the_boundarys_second_is_history_only_when_its_key_sorts_after_the_boundary(): void
    {
        $responder = $this->bindResponder();
        $conversation = $this->open();
        $before = $this->message($conversation, 'Same second, lower key.', '10:01:00', $this->key(9));
        $boundary = $this->message($conversation, 'The boundary.', '10:01:00', $this->key(10));
        $after = $this->message($conversation, 'Same second, higher key.', '10:01:00', $this->key(11));
        $trigger = $this->message($conversation, 'The question.', '10:02:00');
        $this->summarise($conversation, 'Everything up to the boundary.', $boundary->getKey());

        $this->answer($conversation, $trigger);

        $history = $responder->turns[0]->history;
        $this->assertSame([
            (string) $after->getKey(),
        ], array_map(static fn (SupportMessage $message): string => (string) $message->getKey(), $history));
        $this->assertNotContains((string) $before->getKey(), array_map(
            static fn (SupportMessage $message): string => (string) $message->getKey(),
            $history,
        ));
    }

    public function test_the_turn_is_flagged_truncated_when_the_cap_dropped_messages_after_the_boundary(): void
    {
        config()->set('magic-starter.support.ai.history_messages', 2);
        $responder = $this->bindResponder();
        [$conversation] = $this->thread();
        $this->message($conversation, 'Third follow-up.', '10:03:00');
        $trigger = $this->message($conversation, 'The question.', '10:04:00');

        $this->answer($conversation, $trigger);

        $turn = $responder->turns[0];
        $this->assertSame([
            'Second follow-up.',
            'Third follow-up.',
        ], $this->bodies($turn));
        $this->assertTrue($turn->historyTruncated);
    }

    public function test_the_turn_is_not_flagged_truncated_when_the_window_after_the_boundary_fits_the_cap(): void
    {
        config()->set('magic-starter.support.ai.history_messages', 2);
        $responder = $this->bindResponder();
        [$conversation, , $first] = $this->thread();
        $this->message($conversation, 'Third follow-up.', '10:03:00');
        $trigger = $this->message($conversation, 'The question.', '10:04:00');
        $this->summarise($conversation, 'The opening and the first follow-up.', $first->getKey());

        $this->answer($conversation, $trigger);

        $turn = $responder->turns[0];
        $this->assertSame([
            'Second follow-up.',
            'Third follow-up.',
        ], $this->bodies($turn));
        $this->assertFalse($turn->historyTruncated);
    }

    public function test_a_boundary_whose_message_is_gone_reads_the_whole_thread_but_still_compacts(): void
    {
        $responder = $this->bindResponder(fn (SupportTurn $turn): SupportAnswer => $this->compacting(
            'A fresh summary.',
            $turn->history[0]->getKey(),
        ));
        [$conversation] = $this->thread();
        $trigger = $this->message($conversation, 'Third question.', '10:03:00');
        $this->summarise($conversation, 'A summary of a deleted message.', $this->key(404));

        $this->answer($conversation, $trigger);

        $turn = $responder->turns[0];
        $this->assertCount(3, $turn->history);
        $this->assertSame($this->key(404), (string) $turn->summaryThroughId);
        $conversation->refresh();
        $this->assertSame('A fresh summary.', $conversation->ai_summary);
        $this->assertSame((string) $turn->history[0]->getKey(), (string) $conversation->ai_summary_through_id);
    }

    // -----------------------------------------------------------------
    // What the turn writes
    // -----------------------------------------------------------------

    public function test_an_answer_with_a_compaction_stores_the_summary_with_the_answer(): void
    {
        [$conversation, , $second] = $this->thread();
        $this->bindResponder(fn (): SupportAnswer => $this->compacting('The opening two messages.', $second->getKey()));
        $trigger = $this->message($conversation, 'Third question.', '10:03:00');

        $this->answer($conversation, $trigger);

        $conversation->refresh();
        $this->assertSame('The opening two messages.', $conversation->ai_summary);
        $this->assertSame((string) $second->getKey(), (string) $conversation->ai_summary_through_id);
        $this->assertSame((string) $trigger->getKey(), (string) $conversation->answered_message_id);
        $this->assertSame(1, $this->aiReplies()->count());
    }

    public function test_a_compaction_is_dropped_when_another_turn_moved_the_boundary_first(): void
    {
        [$conversation, $first, $second] = $this->thread();
        $this->bindResponder(function () use ($conversation, $first, $second): SupportAnswer {
            // Another turn compacts while this one waits on the model.
            SupportConversation::query()->whereKey($conversation->getKey())->update([
                'ai_summary' => 'Their summary.',
                'ai_summary_through_id' => $first->getKey(),
            ]);

            return $this->compacting('My summary.', $second->getKey());
        });
        $trigger = $this->message($conversation, 'Third question.', '10:03:00');

        $this->answer($conversation, $trigger);

        $conversation->refresh();
        $this->assertSame('Their summary.', $conversation->ai_summary);
        $this->assertSame((string) $first->getKey(), (string) $conversation->ai_summary_through_id);
        $this->assertSame(1, $this->aiReplies()->count());
    }

    public function test_a_failed_turn_never_stores_a_compaction(): void
    {
        [$conversation, $first] = $this->thread();
        $this->bindResponder(fn (): SupportAnswer => new SupportAnswer(
            'Reset it at https://evil.example/login.',
            0.9,
            new SupportUsage(300, 100),
            compaction: new SupportHistorySummary('A summary.', $first->getKey()),
        ));
        $trigger = $this->message($conversation, 'Third question.', '10:03:00');

        $this->answer($conversation, $trigger);

        $this->assertNoSummary($conversation);
        $this->assertSame(0, $this->aiReplies()->count());
    }

    public function test_a_superseded_turn_never_stores_a_compaction(): void
    {
        $customer = $this->customer();
        [$conversation, $first] = $this->thread($customer);
        $this->bindResponder(function () use ($conversation, $customer, $first): SupportAnswer {
            // A newer customer message takes the turn while the model thinks.
            app(PostsCustomerSupportMessages::class)->post($conversation->refresh(), 'Wait, one more.', $customer);

            return $this->compacting('A summary.', $first->getKey());
        });
        $trigger = $this->message($conversation, 'Third question.', '10:03:00');

        $this->answer($conversation, $trigger);

        $this->assertNoSummary($conversation);
        $this->assertSame(0, $this->aiReplies()->count());
    }

    public function test_an_unavailable_answer_never_stores_a_compaction(): void
    {
        [$conversation, $first] = $this->thread();
        $this->bindResponder(fn (): SupportAnswer => new SupportAnswer(
            'I cannot answer right now.',
            0.0,
            new SupportUsage(300, 100),
            SupportHandoffReason::UNAVAILABLE,
            SupportPriority::NORMAL,
            new SupportHistorySummary('A summary.', $first->getKey()),
        ));
        $trigger = $this->message($conversation, 'Third question.', '10:03:00');

        $this->answer($conversation, $trigger);

        $this->assertNoSummary($conversation);
        $this->assertSame(0, $this->aiReplies()->count());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * A pending conversation of the opening message and two follow-ups, a minute apart.
     *
     * @return array{SupportConversation, SupportMessage, SupportMessage, SupportMessage}
     */
    private function thread(?ConcreteUser $customer = null): array
    {
        $conversation = $this->open($customer);
        $opening = $conversation->messages()->sole();

        return [
            $conversation,
            $opening,
            $this->message($conversation, 'First follow-up.', '10:01:00'),
            $this->message($conversation, 'Second follow-up.', '10:02:00'),
        ];
    }

    /**
     * Open a pending conversation; a responder must be bound for that, so a test that binds its own after the
     * thread exists swaps out the default one bound here.
     */
    private function open(?ConcreteUser $customer = null): SupportConversation
    {
        Carbon::setTestNow(self::START);

        if (! $this->app->bound(RespondsToSupport::class)) {
            $this->bindResponder();
        }

        return app(OpensSupportConversations::class)->open($customer ?? $this->customer(), [
            'topic' => 'technical',
            'body' => 'Hello, I have a question.',
        ])->refresh();
    }

    /**
     * A customer message written at `$time` on the test day, with `$key` as its key when given.
     */
    private function message(
        SupportConversation $conversation,
        string $body,
        string $time,
        ?string $key = null,
    ): SupportMessage {
        $message = new SupportMessage;
        $message->forceFill(array_filter([
            'id' => $key,
            'conversation_id' => $conversation->getKey(),
            'sender_type' => SupportSenderType::CUSTOMER,
            'sender_id' => $conversation->user_id,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => $body,
            'created_at' => Carbon::parse('2026-10-13 ' . $time),
            'updated_at' => Carbon::parse('2026-10-13 ' . $time),
        ], static fn (mixed $value): bool => $value !== null))->save();

        return $message;
    }

    /**
     * An ordered-UUID shaped key whose lexical order follows `$n`.
     */
    private function key(int $n): string
    {
        return sprintf('ffffffff-0000-7000-8000-%012d', $n);
    }

    private function summarise(SupportConversation $conversation, string $summary, int|string $throughId): void
    {
        $conversation->forceFill([
            'ai_summary' => $summary,
            'ai_summary_through_id' => $throughId,
        ])->save();
    }

    /**
     * Run the responder job for `$trigger` as the newest customer message of the pending conversation.
     */
    private function answer(SupportConversation $conversation, SupportMessage $trigger): void
    {
        Carbon::setTestNow('2026-10-13 10:30:00');
        $conversation->refresh()->forceFill([
            'last_customer_message_id' => $trigger->getKey(),
        ])->save();
        $this->assertSame(SupportConversationStatus::PENDING, $conversation->status);

        $job = new RespondToSupportConversation($conversation->getKey(), $trigger->getKey());
        $job->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);
    }

    private function compacting(string $summary, int|string $throughId): SupportAnswer
    {
        return new SupportAnswer(
            'Your plan renews on the 1st.',
            0.9,
            new SupportUsage(300, 100),
            compaction: new SupportHistorySummary($summary, $throughId),
        );
    }

    /**
     * @return list<string>
     */
    private function bodies(SupportTurn $turn): array
    {
        return array_map(static fn (SupportMessage $message): string => $message->body, $turn->history);
    }

    private function assertNoSummary(SupportConversation $conversation): void
    {
        $conversation->refresh();
        $this->assertNull($conversation->ai_summary);
        $this->assertNull($conversation->ai_summary_through_id);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<SupportMessage>
     */
    private function aiReplies(): \Illuminate\Database\Eloquent\Builder
    {
        return SupportMessage::query()->where('sender_type', SupportSenderType::AI->value);
    }

    private function bindResponder(?Closure $respond = null): CompactionRecordingResponder
    {
        $responder = new CompactionRecordingResponder($respond);
        $this->app->instance(RespondsToSupport::class, $responder);

        return $responder;
    }

    private function customer(): ConcreteUser
    {
        return ConcreteUser::create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'secret',
            'email_verified_at' => now(),
        ]);
    }
}

/**
 * A responder that records every turn and answers through a closure, never a model.
 */
class CompactionRecordingResponder implements RespondsToSupport
{
    /** @var list<SupportTurn> */
    public array $turns = [];

    public function __construct(
        private readonly ?Closure $respond = null,
    ) {}

    public function respond(SupportTurn $turn): SupportAnswer
    {
        $this->turns[] = $turn;

        if ($this->respond !== null) {
            return ($this->respond)($turn);
        }

        return new SupportAnswer('Your plan renews on the 1st.', 0.9, new SupportUsage(300, 100));
    }

    public function pool(SupportConversation $conversation): SupportAiPool
    {
        return SupportAiPool::FREE;
    }
}
