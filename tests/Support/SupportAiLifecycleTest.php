<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use Closure;
use FlutterSdk\MagicStarter\Contracts\AssistsSupportStaff;
use FlutterSdk\MagicStarter\Contracts\OpensSupportConversations;
use FlutterSdk\MagicStarter\Contracts\PostsCustomerSupportMessages;
use FlutterSdk\MagicStarter\Contracts\RepliesToSupport;
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
use FlutterSdk\MagicStarter\Jobs\PrepareSupportStaffAssist;
use FlutterSdk\MagicStarter\Jobs\RespondToSupportConversation;
use FlutterSdk\MagicStarter\Listeners\NotifyStaffOfSupportConversation;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Notifications\SupportConversationNeedsStaffNotification;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAnswer;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportHandoff;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportStaffAssist;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportTurn;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportUsage;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use Mockery;
use RuntimeException;

/**
 * The AI desk's lifecycle: which conversations go pending, what the responder
 * job posts, and how every write path keeps one answer per turn.
 *
 * Each concurrency case plays its interleaving out explicitly (dispatch,
 * mutate, run) instead of relying on timing: a job is captured from the fake
 * queue and run by hand after the competing write, or the competing write
 * happens inside the fake responder, which is where a real model call leaves
 * the window open.
 */
class SupportAiLifecycleTest extends TestCase
{
    private const STAFF_EMAIL = 'staff@example.com';

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
    // Who goes pending
    // -----------------------------------------------------------------

    public function test_with_no_responder_bound_a_conversation_opens_for_staff_as_before(): void
    {
        $conversation = $this->open($this->customer());

        $this->assertSame(SupportConversationStatus::OPEN, $conversation->status);
        Event::assertDispatched(
            SupportConversationNeedsStaff::class,
            fn (SupportConversationNeedsStaff $event): bool => $event->reason === 'opened',
        );
        Queue::assertNotPushed(RespondToSupportConversation::class);
    }

    public function test_a_verified_customer_goes_pending_and_the_responder_is_queued_without_a_staff_alert(): void
    {
        $this->bindResponder();

        $conversation = $this->open($this->customer());

        $first = $conversation->messages()->sole();
        $this->assertSame(SupportConversationStatus::PENDING, $conversation->status);
        $this->assertSame((string) $first->getKey(), (string) $conversation->last_customer_message_id);
        Event::assertNotDispatched(SupportConversationNeedsStaff::class);
        Queue::assertPushed(
            RespondToSupportConversation::class,
            fn (RespondToSupportConversation $job): bool => (string) $job->triggerId === (string) $first->getKey()
                && (string) $job->conversationId === (string) $conversation->getKey()
                && $job->afterCommit === true
                && $job->queue === 'support',
        );
    }

    public function test_the_responder_runs_only_once_the_opening_transaction_commits(): void
    {
        config()->set('magic-starter.support.ai.connection', 'sync');
        $responder = $this->bindResponder();
        $customer = $this->customer();

        // The real queue manager, so the sync connection honours the job's after-commit flag.
        $this->app->forgetInstance('queue');
        Queue::clearResolvedInstance('queue');

        DB::transaction(function () use ($customer, $responder): void {
            $this->open($customer);

            $this->assertSame(0, $responder->calls);
        });

        $this->assertSame(1, $responder->calls);
        $this->assertSame(1, $this->aiReplies()->count());
    }

    public function test_a_guest_an_unverified_user_and_a_public_form_never_reach_the_responder(): void
    {
        $this->bindResponder();

        $guest = $this->open($this->customer('guest@example.com', guest: true));
        $unverified = $this->open($this->customer('new@example.com', verified: false));
        $public = app(OpensSupportConversations::class)->openPublic([
            'email' => 'visitor@example.com',
            'body' => 'Hello from the form.',
        ]);

        foreach ([$guest, $unverified, $public] as $conversation) {
            $this->assertSame(SupportConversationStatus::OPEN, $conversation->refresh()->status);
        }
        Queue::assertNotPushed(RespondToSupportConversation::class);
        Event::assertDispatchedTimes(SupportConversationNeedsStaff::class, 3);
    }

    public function test_a_message_on_a_pending_conversation_stays_pending_and_queues_instead_of_alerting(): void
    {
        $this->bindResponder();
        $customer = $this->customer();
        $conversation = $this->open($customer);

        $message = $this->write($conversation, 'One more thing.', $customer);

        $conversation->refresh();
        $this->assertSame(SupportConversationStatus::PENDING, $conversation->status);
        $this->assertSame((string) $message->getKey(), (string) $conversation->last_customer_message_id);
        Event::assertNotDispatched(SupportConversationNeedsStaff::class);
        Queue::assertPushed(
            RespondToSupportConversation::class,
            fn (RespondToSupportConversation $job): bool => (string) $job->triggerId === (string) $message->getKey(),
        );
    }

    public function test_a_customer_message_alerts_staff_only_when_the_conversation_was_not_already_waiting(): void
    {
        $customer = $this->customer();
        $conversation = $this->open($customer);

        // 1. Still waiting on the opening alert: no second alert.
        $this->write($conversation, 'Anyone there?', $customer);
        $this->assertCount(0, $this->alerts('customer_message'));

        // 2. Staff answered, so the wait ended: the next message alerts again.
        app(RepliesToSupport::class)->reply($this->staff(), $conversation->refresh(), 'Looking into it.', false);
        $this->write($conversation->refresh(), 'Thanks, still broken.', $customer);
        $this->assertCount(1, $this->alerts('customer_message'));
    }

    // -----------------------------------------------------------------
    // The responder job
    // -----------------------------------------------------------------

    public function test_the_job_posts_the_answer_as_the_ai_and_settles_the_turn(): void
    {
        $responder = $this->bindResponder();
        $conversation = $this->open($this->customer());
        $conversation->forceFill(['ai_failures' => 1])->save();

        $this->runQueued();

        $reply = $this->aiReplies()->sole();
        $conversation->refresh();
        $this->assertSame(1, $responder->calls);
        $this->assertSame('Your plan renews on the 1st.', $reply->body);
        $this->assertSame(SupportMessageKind::MESSAGE, $reply->kind);
        $this->assertNull($reply->sender_id);
        $this->assertSame(0.9, $reply->meta['confidence']);
        $this->assertSame((string) $conversation->last_customer_message_id, (string) $reply->meta['trigger_id']);
        $this->assertSame(
            (string) $conversation->last_customer_message_id,
            (string) $conversation->answered_message_id,
        );
        $this->assertSame(0, $conversation->ai_failures);
        $this->assertSame(SupportConversationStatus::PENDING, $conversation->status);
        Event::assertDispatched(SupportConversationUpdated::class);
        $this->assertTrue(Cache::lock('support-ai:' . $conversation->getKey(), 1)->get());
    }

    public function test_the_turn_carries_the_customer_visible_history_and_the_trigger(): void
    {
        $responder = $this->bindResponder();
        $customer = $this->customer();
        $conversation = $this->open($customer);
        $conversation->messages()->create([
            'sender_type' => SupportSenderType::STAFF,
            'kind' => SupportMessageKind::NOTE,
            'body' => 'Internal: VIP.',
        ]);
        $second = $this->write($conversation, 'And the invoice?', $customer);

        $this->runQueued(last: true);

        $turn = $responder->turns[0];
        $this->assertSame((string) $second->getKey(), (string) $turn->trigger->getKey());
        $this->assertSame(['Hello, how do I add a monitor?'], array_map(
            static fn (SupportMessage $message): string => $message->body,
            $turn->history,
        ));
        $this->assertSame(75, $turn->deadlineSeconds);
    }

    public function test_two_messages_in_one_second_get_one_answer_never_the_earlier_after_the_later(): void
    {
        Carbon::setTestNow('2026-10-13 10:00:00');
        $responder = $this->bindResponder();
        $customer = $this->customer();
        $conversation = $this->open($customer);
        $this->write($conversation, 'Also, my checks are red.', $customer);
        [$earlier, $later] = $this->queuedJobs();

        // 1. The later job wins the race to the worker; the earlier one runs after it.
        $this->runJob($later);
        $this->runJob($earlier);

        // 2. And a retry of each changes nothing.
        $this->runJob($later);
        $this->runJob($earlier);

        $reply = $this->aiReplies()->sole();
        $this->assertSame((string) $later->triggerId, (string) $reply->meta['trigger_id']);
        $this->assertSame(1, $responder->calls);
    }

    public function test_the_earlier_job_skips_once_a_later_message_arrived_before_it_ran(): void
    {
        $responder = $this->bindResponder();
        $customer = $this->customer();
        $conversation = $this->open($customer);
        $this->write($conversation, 'Also, my checks are red.', $customer);
        [$earlier, $later] = $this->queuedJobs();

        $this->runJob($earlier);
        $this->assertSame(0, $responder->calls);
        $this->assertSame(0, $this->aiReplies()->count());

        $this->runJob($later);
        $this->assertSame(1, $this->aiReplies()->count());
    }

    public function test_a_message_arriving_during_the_model_call_discards_the_stale_answer(): void
    {
        $customer = $this->customer();
        $conversation = null;
        $responder = $this->bindResponder(function (SupportTurn $turn) use (&$conversation, $customer): SupportAnswer {
            $this->write($conversation, 'Wait, one more detail.', $customer);

            return new SupportAnswer('Stale answer.', 0.9, new SupportUsage(300, 100));
        });
        $conversation = $this->open($customer);
        [$earlier] = $this->queuedJobs();

        $this->runJob($earlier);

        $this->assertSame(1, $responder->calls);
        $this->assertSame(0, $this->aiReplies()->count());
        $this->assertSame(SupportConversationStatus::PENDING, $conversation->refresh()->status);
        $this->assertNull($conversation->answered_message_id);
    }

    public function test_a_job_run_twice_for_one_trigger_posts_one_reply_and_charges_once(): void
    {
        $responder = $this->bindResponder();
        $customer = $this->customer();
        $this->open($customer);
        [$job] = $this->queuedJobs();

        $this->runJob($job);
        $this->runJob($job);

        $this->assertSame(1, $this->aiReplies()->count());
        $this->assertSame(1, $responder->calls);
        $this->assertSame(1, app(RateLimiter::class)->attempts(
            sprintf('magic-starter:support-ai:user:%s:%s', $customer->getKey(), now()->format('Y-m-d')),
        ));
    }

    public function test_a_staff_reply_flips_pending_to_open_and_the_queued_job_then_posts_nothing(): void
    {
        $responder = $this->bindResponder();
        $conversation = $this->open($this->customer());

        app(RepliesToSupport::class)->reply($this->staff(), $conversation, 'A person here.', false);

        $this->assertSame(SupportConversationStatus::OPEN, $conversation->refresh()->status);
        $this->runQueued();
        $this->assertSame(0, $responder->calls);
        $this->assertSame(0, $this->aiReplies()->count());
    }

    public function test_a_staff_reply_during_the_model_call_wins_over_the_ai_answer(): void
    {
        $staff = $this->staff();
        $conversation = null;
        $this->bindResponder(function () use (&$conversation, $staff): SupportAnswer {
            app(RepliesToSupport::class)->reply($staff, $conversation, 'A person here.', false);

            return new SupportAnswer('Too late.', 0.9, new SupportUsage(300, 100));
        });
        $conversation = $this->open($this->customer());

        $this->runQueued();

        $this->assertSame(0, $this->aiReplies()->count());
        $this->assertSame(SupportConversationStatus::OPEN, $conversation->refresh()->status);
    }

    public function test_a_staff_note_leaves_the_conversation_with_the_ai(): void
    {
        $this->bindResponder();
        $conversation = $this->open($this->customer());

        app(RepliesToSupport::class)->reply($this->staff(), $conversation, 'Watch this one.', true);

        $this->assertSame(SupportConversationStatus::PENDING, $conversation->refresh()->status);
    }

    public function test_a_held_lock_releases_the_job_for_later_without_calling_the_model(): void
    {
        $responder = $this->bindResponder();
        $conversation = $this->open($this->customer());
        [$job] = $this->queuedJobs();
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->with(15)->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')->once()->with('support-ai:' . $conversation->getKey(), 120)->andReturn($lock);
        $job->withFakeQueueInteractions();

        $this->runJob($job);

        $job->assertReleased(10);
        $this->assertSame(0, $responder->calls);
    }

    public function test_the_job_carries_its_ceiling_queue_and_retry_window(): void
    {
        config()->set('magic-starter.support.ai.job_timeout', 40);
        config()->set('magic-starter.support.ai.connection', 'redis-support');

        $job = new RespondToSupportConversation('1', '2');

        $this->assertSame(40, $job->timeout);
        $this->assertSame(2, $job->tries);
        $this->assertSame('redis-support', $job->connection);
        $this->assertSame('support', $job->queue);
        $this->assertEqualsWithDelta(now()->addMinutes(10)->getTimestamp(), $job->retryUntil()->getTimestamp(), 2);
    }

    // -----------------------------------------------------------------
    // Guards and handoffs
    // -----------------------------------------------------------------

    public function test_a_classifier_hit_hands_off_with_zero_responder_calls_and_queues_the_staff_assist(): void
    {
        $responder = $this->bindResponder();
        $this->bindAssist();
        $conversation = $this->open($this->customer(), 'I was charged twice, I want a refund.');

        $this->runQueued();

        $conversation->refresh();
        $this->assertSame(0, $responder->calls);
        $this->assertSame(SupportConversationStatus::OPEN, $conversation->status);
        $this->assertSame(SupportHandoffReason::BILLING, $conversation->handoff_reason);
        $this->assertSame(SupportPriority::HIGH, $conversation->priority);
        $this->assertNotNull($conversation->waiting_since);
        Queue::assertPushed(PrepareSupportStaffAssist::class);
        $this->assertCount(1, $this->alerts('handoff'));
    }

    public function test_a_classifier_hit_on_an_earlier_unanswered_message_still_hands_off(): void
    {
        $responder = $this->bindResponder();
        $customer = $this->customer();
        $conversation = $this->open($customer, 'Someone hacked my account.');
        $this->write($conversation, 'Hello?', $customer);

        $this->runQueued(last: true);

        $this->assertSame(0, $responder->calls);
        $this->assertSame(SupportHandoffReason::SECURITY, $conversation->refresh()->handoff_reason);
    }

    public function test_two_guard_rejections_hand_off_as_failures(): void
    {
        $responder = $this->bindResponder(fn (): SupportAnswer => new SupportAnswer(
            'Reset it at https://evil.example/login.',
            0.9,
            new SupportUsage(300, 100),
        ));
        $conversation = $this->open($this->customer());
        [$job] = $this->queuedJobs();

        // 1. The first rejection counts and leaves the turn with the AI for its retry.
        $job->withFakeQueueInteractions();
        $this->runJob($job);
        $job->assertReleased();
        $conversation->refresh();
        $this->assertSame(1, $conversation->ai_failures);
        $this->assertSame(SupportConversationStatus::PENDING, $conversation->status);

        // 2. The second hands the conversation to staff.
        $this->runJob($job);
        $conversation->refresh();
        $this->assertSame(2, $responder->calls);
        $this->assertSame(0, $this->aiReplies()->count());
        $this->assertSame(SupportConversationStatus::OPEN, $conversation->status);
        $this->assertSame(SupportHandoffReason::FAILURES, $conversation->handoff_reason);
        $this->assertSame(1, $this->handoffLines()->count());
    }

    public function test_a_responder_that_throws_counts_as_a_failed_turn(): void
    {
        config()->set('magic-starter.support.ai.failures_before_handoff', 1);
        $this->bindResponder(fn (): SupportAnswer => throw new RuntimeException('provider down'));
        $conversation = $this->open($this->customer());

        $this->runQueued();

        $this->assertSame(SupportHandoffReason::FAILURES, $conversation->refresh()->handoff_reason);
    }

    public function test_a_spent_quota_hands_off_as_quota_without_calling_the_model(): void
    {
        config()->set('magic-starter.support.ai.quotas.user_daily', 0);
        $responder = $this->bindResponder();
        $conversation = $this->open($this->customer());

        $this->runQueued();

        $this->assertSame(0, $responder->calls);
        $this->assertSame(SupportHandoffReason::QUOTA, $conversation->refresh()->handoff_reason);
    }

    public function test_an_answer_carrying_a_handoff_posts_its_reply_then_hands_off_with_its_reason(): void
    {
        $this->bindResponder(fn (): SupportAnswer => new SupportAnswer(
            'I am handing this to a colleague.',
            0.4,
            new SupportUsage(300, 100),
            SupportHandoffReason::LOW_CONFIDENCE,
            SupportPriority::NORMAL,
        ));
        $conversation = $this->open($this->customer());

        $this->runQueued();

        $conversation->refresh();
        $this->assertSame(1, $this->aiReplies()->count());
        $this->assertSame(SupportHandoffReason::LOW_CONFIDENCE, $conversation->handoff_reason);
        $this->assertSame(SupportConversationStatus::OPEN, $conversation->status);
        $this->assertSame(1, $this->handoffLines()->count());
        $this->assertSame(
            (string) $conversation->last_customer_message_id,
            (string) $conversation->answered_message_id,
        );
    }

    public function test_an_opener_who_lost_eligibility_is_handed_off_as_unavailable(): void
    {
        $responder = $this->bindResponder();
        $customer = $this->customer();
        $conversation = $this->open($customer);
        $customer->forceFill(['email_verified_at' => null])->save();

        $this->runQueued();

        $this->assertSame(0, $responder->calls);
        $this->assertSame(SupportHandoffReason::UNAVAILABLE, $conversation->refresh()->handoff_reason);
    }

    public function test_a_job_that_failed_for_good_hands_off_as_unavailable(): void
    {
        $this->bindResponder();
        $conversation = $this->open($this->customer());
        [$job] = $this->queuedJobs();

        $job->failed(new RuntimeException('killed'));

        $conversation->refresh();
        $this->assertSame(SupportConversationStatus::OPEN, $conversation->status);
        $this->assertSame(SupportHandoffReason::UNAVAILABLE, $conversation->handoff_reason);
    }

    public function test_two_concurrent_handoffs_post_one_line_and_one_alert(): void
    {
        $this->bindResponder();
        $conversation = $this->open($this->customer());
        $first = SupportConversation::query()->findOrFail($conversation->getKey());
        $second = SupportConversation::query()->findOrFail($conversation->getKey());
        $desk = app(SupportHandoff::class);

        $this->assertTrue($desk->handoff($first, SupportHandoffReason::QUOTA));
        $this->assertFalse($desk->handoff($second, SupportHandoffReason::UNAVAILABLE));

        $this->assertSame(1, $this->handoffLines()->count());
        $this->assertCount(1, $this->alerts('handoff'));
        $this->assertSame(SupportHandoffReason::QUOTA, $conversation->refresh()->handoff_reason);
    }

    public function test_the_handoff_alert_names_the_reason_not_a_customer_message(): void
    {
        config()->set('magic-starter.admin.emails', [self::STAFF_EMAIL]);
        $staff = $this->staff();
        $this->bindResponder();
        $conversation = $this->open($this->customer());

        app(SupportHandoff::class)->handoff($conversation, SupportHandoffReason::OUTAGE, SupportPriority::URGENT);

        // The listener the provider wires, fed the one event the handoff fired.
        app(NotifyStaffOfSupportConversation::class)->handle($this->alerts('handoff')->sole());

        $expected = Lang::get('magic-starter::admin_support.notifications.staff.handoff', [
            'reason' => Lang::get('magic-starter::admin_support.enums.handoff_reason.outage'),
        ]);
        Notification::assertSentTo(
            $staff,
            SupportConversationNeedsStaffNotification::class,
            fn (SupportConversationNeedsStaffNotification $notification): bool => $notification->reason === 'handoff'
                && $notification->toMail($staff)->introLines === [
                    $expected,
                    Lang::get('magic-starter::admin_support.notifications.staff.open_panel'),
                ],
        );
        $this->assertStringContainsString('Outage reported', $expected);
        $this->assertStringNotContainsString(
            Lang::get('magic-starter::admin_support.notifications.staff.customer_wrote'),
            $expected,
        );
    }

    public function test_the_handoff_line_is_a_system_activity_the_customer_reads_in_the_thread(): void
    {
        $this->bindResponder();
        $customer = $this->customer();
        $conversation = $this->open($customer);
        Carbon::setTestNow(now()->addSecond());

        app(SupportHandoff::class)->handoff(
            $conversation,
            SupportHandoffReason::HUMAN_REQUESTED,
            SupportPriority::NORMAL,
        );

        $line = $this->handoffLines()->sole();
        $this->assertSame(SupportMessageKind::ACTIVITY, $line->kind);
        $this->assertNull($line->sender_id);
        $this->actingAs($customer, 'sanctum');
        $this->getJson("/support/conversations/{$conversation->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.messages.1.sender_type', 'system')
            ->assertJsonPath('data.messages.1.kind', 'activity')
            ->assertJsonPath('data.messages.1.body', Lang::get('magic-starter::support.ai.handoff'));
        Event::assertDispatched(SupportConversationUpdated::class);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Bind a responder that records each turn and answers through `$respond`, or with a fixed safe answer.
     */
    private function bindResponder(?Closure $respond = null): FakeSupportResponder
    {
        $responder = new FakeSupportResponder($respond);
        $this->app->instance(RespondsToSupport::class, $responder);

        return $responder;
    }

    private function bindAssist(): void
    {
        $this->app->instance(AssistsSupportStaff::class, new class implements AssistsSupportStaff
        {
            public function assist(SupportConversation $conversation): SupportStaffAssist
            {
                return new SupportStaffAssist('Summary.', 'Draft.');
            }
        });
    }

    private function open(ConcreteUser $user, string $body = 'Hello, how do I add a monitor?'): SupportConversation
    {
        return app(OpensSupportConversations::class)->open($user, [
            'topic' => 'technical',
            'body' => $body,
        ])->refresh();
    }

    private function write(SupportConversation $conversation, string $body, ConcreteUser $user): SupportMessage
    {
        return app(PostsCustomerSupportMessages::class)->post($conversation, $body, $user);
    }

    /**
     * Every responder job queued so far, oldest first.
     *
     * @return list<RespondToSupportConversation>
     */
    private function queuedJobs(): array
    {
        return Queue::pushed(RespondToSupportConversation::class)->values()->all();
    }

    private function runQueued(bool $last = false): void
    {
        $jobs = $this->queuedJobs();

        $this->runJob($last ? $jobs[array_key_last($jobs)] : $jobs[0]);
    }

    private function runJob(RespondToSupportConversation $job): void
    {
        $this->app->call([$job, 'handle']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<SupportMessage>
     */
    private function aiReplies(): \Illuminate\Database\Eloquent\Builder
    {
        return SupportMessage::query()->where('sender_type', SupportSenderType::AI->value);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<SupportMessage>
     */
    private function handoffLines(): \Illuminate\Database\Eloquent\Builder
    {
        return SupportMessage::query()
            ->where('sender_type', SupportSenderType::SYSTEM->value)
            ->where('kind', SupportMessageKind::ACTIVITY->value);
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
        string $email = 'ada@example.com',
        bool $verified = true,
        bool $guest = false,
    ): ConcreteUser {
        return ConcreteUser::create([
            'name' => 'Ada Lovelace',
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => $verified ? now() : null,
            'is_guest' => $guest,
        ]);
    }

    private function staff(): ConcreteUser
    {
        return ConcreteUser::query()->where('email', self::STAFF_EMAIL)->first()
            ?? ConcreteUser::create([
                'name' => 'Grace Hopper',
                'email' => self::STAFF_EMAIL,
                'password' => 'secret',
                'email_verified_at' => now(),
            ]);
    }
}

/**
 * A responder that records every turn and answers through a closure, never a model.
 */
class FakeSupportResponder implements RespondsToSupport
{
    public int $calls = 0;

    /** @var list<SupportTurn> */
    public array $turns = [];

    public function __construct(
        private readonly ?Closure $respond = null,
    ) {}

    public function respond(SupportTurn $turn): SupportAnswer
    {
        $this->calls++;
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
