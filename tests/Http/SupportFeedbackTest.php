<?php

namespace FlutterSdk\MagicStarter\Tests\Http;

use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportFeedback;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The in-app 0-10 feedback card, driven through the routes the package registers.
 *
 * Time is frozen so the day and the account age are exact. Every refusal asserts nothing was written, and every
 * low score asserts the staff alert next to the conversation it opened.
 */
class SupportFeedbackTest extends TestCase
{
    /**
     * @param  Application  $app
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
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::support(),
            Features::supportFeedback(),
        ]);
        $app['config']->set('auth.providers.users.model', ConcreteUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 12:00:00');

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('current_team_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->timestamps();
        });

        foreach ([
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
            'add_ai_columns_to_support_tables.php',
            'add_feedback_columns_to_support_conversations_table.php',
            'create_support_feedback_table.php',
        ] as $file) {
            (require __DIR__ . '/../../database/migrations/' . $file)->up();
        }

        Notification::fake();
        Event::fake([
            SupportConversationNeedsStaff::class,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_a_guest_is_not_eligible_and_cannot_answer(): void
    {
        $guest = $this->customer('guest@example.com', guest: true);

        $this->actingAs($guest, 'sanctum');

        $this->getJson('/support/feedback')->assertOk()->assertExactJson(['eligible' => false]);
        $this->postJson('/support/feedback', ['score' => 3])->assertForbidden();

        $this->assertSame(0, SupportFeedback::query()->count());
        $this->assertSame(0, SupportConversation::query()->count());
    }

    public function test_an_unverified_email_is_not_eligible_and_cannot_answer(): void
    {
        $unverified = $this->customer('unverified@example.com', verified: false);

        $this->actingAs($unverified, 'sanctum');

        $this->getJson('/support/feedback')->assertOk()->assertExactJson(['eligible' => false]);
        $this->postJson('/support/feedback', ['score' => 3])->assertForbidden();

        $this->assertSame(0, SupportFeedback::query()->count());
    }

    public function test_an_account_is_eligible_only_after_the_minimum_age(): void
    {
        $young = $this->customer('young@example.com', ageDays: 13);
        $old = $this->customer('old@example.com', ageDays: 14);

        $this->actingAs($young, 'sanctum');
        $this->getJson('/support/feedback')->assertExactJson(['eligible' => false]);

        $this->actingAs($old, 'sanctum');
        $this->getJson('/support/feedback')->assertExactJson(['eligible' => true]);
    }

    public function test_a_dismissal_is_recorded_and_the_card_waits_out_the_interval(): void
    {
        $customer = $this->customer('ada@example.com', ageDays: 30);
        $this->actingAs($customer, 'sanctum');

        $this->postJson('/support/feedback', ['score' => null])
            ->assertOk()
            ->assertExactJson(['conversation_id' => null]);

        $row = SupportFeedback::query()->sole();
        $this->assertNull($row->score);
        $this->assertNull($row->conversation_id);
        $this->assertSame((string) $customer->getKey(), (string) $row->user_id);
        $this->assertSame(0, SupportConversation::query()->count());

        $this->getJson('/support/feedback')->assertExactJson(['eligible' => false]);

        $this->travel(89)->days();
        $this->getJson('/support/feedback')->assertExactJson(['eligible' => false]);

        $this->travel(2)->days();
        $this->getJson('/support/feedback')->assertExactJson(['eligible' => true]);
    }

    public function test_a_low_score_opens_a_high_priority_feedback_conversation_and_alerts_staff(): void
    {
        $customer = $this->customer('ada@example.com', ageDays: 30);
        $this->actingAs($customer, 'sanctum');

        $response = $this->postJson('/support/feedback', ['score' => 4, 'comment' => 'Alerts arrive late.'])
            ->assertOk();

        $conversation = SupportConversation::query()->sole();
        $response->assertExactJson(['conversation_id' => $conversation->getKey()]);

        $this->assertSame(SupportConversationSource::FEEDBACK, $conversation->source);
        $this->assertSame(SupportPriority::HIGH, $conversation->priority);
        $this->assertSame(SupportConversationStatus::OPEN, $conversation->status);
        $this->assertSame('Feedback: 4/10', $conversation->subject);
        $this->assertSame((string) $customer->getKey(), (string) $conversation->user_id);
        $this->assertSame('Alerts arrive late.', $conversation->messages()->sole()->body);

        $row = SupportFeedback::query()->sole();
        $this->assertSame(4, $row->score);
        $this->assertSame('Alerts arrive late.', $row->comment);
        $this->assertSame((string) $conversation->getKey(), (string) $row->conversation_id);

        $this->assertCount(1, $this->alerts('feedback'));
        $this->assertCount(0, $this->alerts('opened'));
    }

    public function test_a_low_score_without_a_comment_gets_a_synthesised_body(): void
    {
        $this->actingAs($this->customer('ada@example.com', ageDays: 30), 'sanctum');

        $this->postJson('/support/feedback', ['score' => 2])->assertOk();

        $this->assertSame('Score 2/10, no comment', SupportConversation::query()->sole()->messages()->sole()->body);
    }

    public function test_a_second_low_score_the_same_day_continues_the_open_conversation(): void
    {
        $this->actingAs($this->customer('ada@example.com', ageDays: 30), 'sanctum');

        $first = $this->postJson('/support/feedback', ['score' => 4, 'comment' => 'Slow.'])->json('conversation_id');
        $second = $this->postJson('/support/feedback', ['score' => 2, 'comment' => 'Still slow.'])
            ->assertOk()
            ->json('conversation_id');

        $this->assertSame($first, $second);
        $conversation = SupportConversation::query()->sole();
        $this->assertSame(['Slow.', 'Still slow.'], $conversation->messages()->orderBy('created_at')
            ->orderBy('id')->pluck('body')->all());
        $this->assertSame(2, SupportFeedback::query()->count());
        // The thread is still waiting on staff from the first score, and the
        // desk alerts once per wait: the second score lands in the thread
        // without paging anyone again.
        $this->assertCount(1, $this->alerts('feedback'));
        $this->assertCount(0, $this->alerts('customer_message'));
    }

    public function test_at_most_one_new_low_score_conversation_is_opened_per_day(): void
    {
        $this->actingAs($this->customer('ada@example.com', ageDays: 30), 'sanctum');

        $this->postJson('/support/feedback', ['score' => 4])->assertOk();
        SupportConversation::query()->update([
            'status' => SupportConversationStatus::RESOLVED,
            'resolved_at' => now(),
        ]);

        $this->postJson('/support/feedback', ['score' => 3])
            ->assertOk()
            ->assertExactJson(['conversation_id' => null]);

        $this->assertSame(1, SupportConversation::query()->count());
        $this->assertSame(2, SupportFeedback::query()->count());
        $this->assertCount(1, $this->alerts('feedback'));

        $this->travel(1)->days();
        $this->postJson('/support/feedback', ['score' => 3])->assertOk();

        $this->assertSame(2, SupportConversation::query()->count());
    }

    #[DataProvider('scores')]
    public function test_only_a_score_up_to_the_threshold_opens_a_conversation(int $score, bool $opens): void
    {
        $this->actingAs($this->customer('ada@example.com', ageDays: 30), 'sanctum');

        $this->postJson('/support/feedback', ['score' => $score, 'comment' => 'Fine.'])->assertOk();

        $this->assertSame($opens ? 1 : 0, SupportConversation::query()->count());
        $this->assertCount($opens ? 1 : 0, $this->alerts('feedback'));
        $this->assertSame($score, SupportFeedback::query()->sole()->score);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function scores(): array
    {
        return [
            'zero' => [0, true],
            'at the threshold' => [6, true],
            'above the threshold' => [7, false],
            'nine' => [9, false],
            'ten' => [10, false],
        ];
    }

    public function test_a_score_outside_zero_to_ten_and_an_overlong_comment_are_refused(): void
    {
        $this->actingAs($this->customer('ada@example.com', ageDays: 30), 'sanctum');

        $this->postJson('/support/feedback', ['score' => 11])->assertJsonValidationErrors('score');
        $this->postJson('/support/feedback', ['score' => -1])->assertJsonValidationErrors('score');
        $this->postJson('/support/feedback', ['score' => 8, 'comment' => str_repeat('a', 2001)])
            ->assertJsonValidationErrors('comment');

        $this->assertSame(0, SupportFeedback::query()->count());
    }

    public function test_the_client_cannot_open_a_conversation_as_feedback(): void
    {
        $this->actingAs($this->customer('ada@example.com', ageDays: 30), 'sanctum');

        $this->postJson('/support/conversations', [
            'topic' => 'other',
            'body' => 'Hello.',
            'source' => 'feedback',
        ])->assertJsonValidationErrors('source');

        $this->assertSame(0, SupportConversation::query()->count());
    }

    /**
     * @return \Illuminate\Support\Collection<int, SupportConversationNeedsStaff>
     */
    private function alerts(string $reason): \Illuminate\Support\Collection
    {
        return Event::dispatched(
            SupportConversationNeedsStaff::class,
            fn (SupportConversationNeedsStaff $event): bool => $event->reason === $reason,
        )->map(fn (array $arguments): SupportConversationNeedsStaff => $arguments[0]);
    }

    private function customer(
        string $email,
        bool $verified = true,
        bool $guest = false,
        int $ageDays = 0,
    ): ConcreteUser {
        return ConcreteUser::create([
            'name' => 'Customer',
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => $verified ? now() : null,
            'is_guest' => $guest,
            'created_at' => now()->subDays($ageDays),
            'updated_at' => now()->subDays($ageDays),
        ]);
    }
}
