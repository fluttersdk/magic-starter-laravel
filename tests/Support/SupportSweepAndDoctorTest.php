<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Console\SupportDoctorCommand;
use FlutterSdk\MagicStarter\Console\SweepSupportConversationsCommand;
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
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAnswer;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportTurn;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportUsage;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * The two commands that keep the AI desk honest: the sweeper that hands a
 * stranded pending turn to staff, and the doctor that refuses a host whose
 * workers never consume the support queue.
 */
class SupportSweepAndDoctorTest extends TestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::support(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'create_users_table.php',
            'add_guest_and_phone_fields_to_users_table.php',
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
            'add_ai_columns_to_support_tables.php',
        ] as $file) {
            $this->artisan('migrate', [
                '--path' => __DIR__ . '/../../database/migrations/' . $file,
                '--realpath' => true,
            ]);
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

        parent::tearDown();
    }

    public function test_the_sweeper_hands_off_a_stale_pending_turn_and_ignores_an_answered_or_fresh_one(): void
    {
        Carbon::setTestNow('2026-10-13 10:00:00');
        $stale = $this->pendingConversation();
        $answered = $this->pendingConversation(answered: true);
        Carbon::setTestNow('2026-10-13 10:04:00');
        $fresh = $this->pendingConversation();
        Carbon::setTestNow('2026-10-13 10:06:00');

        $this->artisan(SweepSupportConversationsCommand::NAME)->assertSuccessful();

        $this->assertSame(SupportConversationStatus::OPEN, $stale->refresh()->status);
        $this->assertSame(SupportHandoffReason::UNAVAILABLE, $stale->handoff_reason);
        $this->assertSame(1, $stale->messages()->where('sender_type', SupportSenderType::SYSTEM->value)->count());
        $this->assertSame(SupportConversationStatus::PENDING, $answered->refresh()->status);
        $this->assertSame(SupportConversationStatus::PENDING, $fresh->refresh()->status);
        Event::assertDispatchedTimes(SupportConversationNeedsStaff::class, 1);
    }

    public function test_the_sweeper_reads_a_missing_minutes_key_as_the_package_default(): void
    {
        config()->set('magic-starter.support.ai', []);
        Carbon::setTestNow('2026-10-13 10:00:00');
        $conversation = $this->pendingConversation();
        Carbon::setTestNow('2026-10-13 10:04:00');

        $this->artisan(SweepSupportConversationsCommand::NAME)->assertSuccessful();
        $this->assertSame(SupportConversationStatus::PENDING, $conversation->refresh()->status);

        Carbon::setTestNow('2026-10-13 10:06:00');
        $this->artisan(SweepSupportConversationsCommand::NAME)->assertSuccessful();
        $this->assertSame(SupportConversationStatus::OPEN, $conversation->refresh()->status);
    }

    public function test_the_provider_schedules_the_sweeper_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            fn (ScheduledEvent $event): bool => str_contains(
                (string) $event->command,
                SweepSupportConversationsCommand::NAME,
            ),
        );

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_the_doctor_passes_when_no_responder_is_bound(): void
    {
        config()->set('horizon', []);

        $this->artisan(SupportDoctorCommand::NAME)->assertSuccessful();
    }

    public function test_the_doctor_fails_when_no_supervisor_lists_the_support_queue(): void
    {
        $this->bindResponder();
        config()->set('horizon.defaults', [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['default'],
            ],
        ]);
        config()->set('horizon.environments', [
            'production' => [
                'supervisor-1' => [
                    'maxProcesses' => 10,
                ],
            ],
        ]);

        $this->artisan(SupportDoctorCommand::NAME)->assertFailed();
    }

    public function test_the_doctor_fails_without_any_horizon_configuration(): void
    {
        $this->bindResponder();
        config()->set('horizon', []);

        $this->artisan(SupportDoctorCommand::NAME)->assertFailed();
    }

    public function test_the_doctor_passes_when_a_supervisor_in_any_environment_lists_the_queue(): void
    {
        $this->bindResponder();
        config()->set('horizon.defaults', [
            'support' => [
                'connection' => 'redis',
                'queue' => 'default,support',
            ],
        ]);
        config()->set('horizon.environments', [
            'local' => [
                'supervisor-1' => [
                    'queue' => ['default'],
                ],
            ],
            'production' => [
                'support' => [
                    'maxProcesses' => 2,
                ],
            ],
        ]);

        $this->artisan(SupportDoctorCommand::NAME)->assertSuccessful();
    }

    private function bindResponder(): void
    {
        // The doctor only asks whether the desk is on; it never calls the responder.
        $this->app->instance(RespondsToSupport::class, new class implements RespondsToSupport
        {
            public function respond(SupportTurn $turn): SupportAnswer
            {
                return new SupportAnswer('Unused.', 1.0, new SupportUsage(0, 0));
            }

            public function pool(SupportConversation $conversation): SupportAiPool
            {
                return SupportAiPool::FREE;
            }
        });
    }

    private function pendingConversation(bool $answered = false): SupportConversation
    {
        $opener = ConcreteUser::create([
            'name' => 'Ada',
            'email' => uniqid('ada', true) . '@example.com',
            'password' => 'secret',
            'email_verified_at' => now(),
        ]);
        $conversation = SupportConversation::query()->create([
            'user_id' => $opener->getKey(),
            'email' => $opener->getAttribute('email'),
            'subject' => 'Question',
            'source' => SupportConversationSource::IN_APP,
            'status' => SupportConversationStatus::PENDING,
            'last_activity_at' => now(),
        ]);
        $trigger = $conversation->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'sender_id' => $opener->getKey(),
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'Hello?',
        ]);
        $conversation->forceFill([
            'last_customer_message_id' => $trigger->getKey(),
            'answered_message_id' => $answered ? $trigger->getKey() : null,
        ])->save();

        return $conversation;
    }
}
