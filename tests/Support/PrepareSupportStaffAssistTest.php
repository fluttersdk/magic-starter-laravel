<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Contracts\AssistsSupportStaff;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Jobs\PrepareSupportStaffAssist;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportStaffAssist;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportStaffAssistCache;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The queued staff assist: it asks the contract once, stores the summary and the
 * draft under the conversation's turn key, and never throws to the panel.
 *
 * Extends the plain test case on purpose: the no-Filament CI job runs this file.
 */
class PrepareSupportStaffAssistTest extends TestCase
{
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
    }

    public function test_it_stores_the_summary_and_the_draft_under_the_turn_key(): void
    {
        $conversation = $this->conversation();
        $this->bindAssist(new SupportStaffAssist('Wants Enterprise.', 'Happy to help with that.'));

        $this->runJob($conversation);

        $stored = app(SupportStaffAssistCache::class)->get($conversation);

        $this->assertNotNull($stored);
        $this->assertSame('Wants Enterprise.', $stored['summary']);
        $this->assertSame('Happy to help with that.', $stored['draft']);
    }

    public function test_a_newer_customer_message_is_a_new_turn_with_nothing_stored(): void
    {
        $conversation = $this->conversation();
        $this->bindAssist(new SupportStaffAssist('Wants Enterprise.', 'Happy to help with that.'));
        $this->runJob($conversation);

        $newer = $this->message($conversation);
        $conversation->update(['last_customer_message_id' => $newer->getKey()]);

        $this->assertNull(app(SupportStaffAssistCache::class)->get($conversation->refresh()));
    }

    public function test_a_contract_exception_is_swallowed_and_stores_nothing(): void
    {
        Log::spy();
        $conversation = $this->conversation();
        $this->app->instance(AssistsSupportStaff::class, new class implements AssistsSupportStaff
        {
            public function assist(SupportConversation $conversation): SupportStaffAssist
            {
                throw new RuntimeException('provider down');
            }
        });

        $this->runJob($conversation);

        $this->assertNull(app(SupportStaffAssistCache::class)->get($conversation));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_failed_run_releases_the_claim_so_staff_can_ask_again(): void
    {
        $conversation = $this->conversation();
        $cache = app(SupportStaffAssistCache::class);
        $this->app->instance(AssistsSupportStaff::class, new class implements AssistsSupportStaff
        {
            public function assist(SupportConversation $conversation): SupportStaffAssist
            {
                throw new RuntimeException('provider down');
            }
        });

        $this->assertTrue($cache->claim($conversation));
        $this->assertFalse($cache->claim($conversation));

        $this->runJob($conversation);

        $this->assertTrue($cache->claim($conversation));
    }

    public function test_it_does_nothing_when_the_contract_is_unbound_or_the_conversation_is_gone(): void
    {
        $conversation = $this->conversation();

        $this->runJob($conversation);
        $this->assertNull(app(SupportStaffAssistCache::class)->get($conversation));

        $this->bindAssist(new SupportStaffAssist('Summary.', 'Draft.'));
        $gone = new PrepareSupportStaffAssist((string) Str::uuid());
        $gone->handle(app(Container::class), app(SupportStaffAssistCache::class), app('log'));

        $this->assertNull(app(SupportStaffAssistCache::class)->get($conversation));
    }

    public function test_the_job_carries_its_ceiling_and_the_configured_queue(): void
    {
        config()->set('magic-starter.support.ai.job_timeout', 40);
        config()->set('magic-starter.support.ai.connection', 'redis');
        config()->set('magic-starter.support.ai.queue', 'support');

        $job = new PrepareSupportStaffAssist('1');

        $this->assertSame(40, $job->timeout);
        $this->assertSame(1, $job->tries);
        $this->assertSame('redis', $job->connection);
        $this->assertSame('support', $job->queue);
        $this->assertGreaterThan(now()->getTimestamp(), $job->retryUntil()->getTimestamp());
        $job->failed(new RuntimeException('killed'));
    }

    public function test_a_busy_queue_has_ten_minutes_to_start_it_and_a_killed_run_is_not_repeated(): void
    {
        $job = new PrepareSupportStaffAssist('1');

        $this->assertEqualsWithDelta(now()->addMinutes(10)->getTimestamp(), $job->retryUntil()->getTimestamp(), 2);
        $this->assertTrue($job->failOnTimeout);
    }

    public function test_the_claim_outlives_a_job_still_waiting_in_a_busy_queue(): void
    {
        $conversation = $this->conversation();
        $cache = app(SupportStaffAssistCache::class);

        $this->assertTrue($cache->claim($conversation));
        $this->travel(5)->minutes();

        $this->assertFalse($cache->claim($conversation));
    }

    public function test_a_host_config_without_the_ai_block_queues_it_on_redis_and_the_support_queue(): void
    {
        config()->set('magic-starter.support', []);

        $job = new PrepareSupportStaffAssist('1');

        $this->assertSame('redis', $job->connection);
        $this->assertSame('support', $job->queue);
    }

    public function test_a_job_that_failed_for_good_logs_that_it_never_finished(): void
    {
        Log::spy();

        (new PrepareSupportStaffAssist('1'))->failed(new RuntimeException('expired'));

        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => $message
                === 'The support staff assist never finished: it timed out or waited past its retry window.'
                && $context['exception'] === RuntimeException::class,
        );
    }

    private function runJob(SupportConversation $conversation): void
    {
        (new PrepareSupportStaffAssist($conversation->getKey()))->handle(
            app(Container::class),
            app(SupportStaffAssistCache::class),
            app('log'),
        );
    }

    private function bindAssist(SupportStaffAssist $assist): void
    {
        $this->app->instance(AssistsSupportStaff::class, new class($assist) implements AssistsSupportStaff
        {
            public function __construct(private readonly SupportStaffAssist $assist) {}

            public function assist(SupportConversation $conversation): SupportStaffAssist
            {
                return $this->assist;
            }
        });
    }

    private function conversation(): SupportConversation
    {
        $conversation = SupportConversation::query()->create([
            'email' => 'ada@example.com',
            'name' => 'Ada',
            'subject' => 'Plan question',
            'source' => SupportConversationSource::PUBLIC_FORM,
            'last_activity_at' => now(),
        ]);

        $conversation->update(['last_customer_message_id' => $this->message($conversation)->getKey()]);

        return $conversation->refresh();
    }

    private function message(SupportConversation $conversation): SupportMessage
    {
        return $conversation->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'Hello',
        ]);
    }
}
