<?php

namespace FlutterSdk\MagicStarter\Tests\Support\SupportAi;

use Closure;
use FlutterSdk\MagicStarter\Contracts\RespondsToSupport;
use FlutterSdk\MagicStarter\Enums\SupportAiPool;
use FlutterSdk\MagicStarter\Enums\SupportAttachmentOrigin;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportHandoffReason;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Events\SupportConversationNeedsStaff;
use FlutterSdk\MagicStarter\Events\SupportConversationUpdated;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Jobs\RespondToSupportConversation;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Models\SupportMessageAttachment;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAiQuota;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAnswer;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportTurn;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportUsage;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;

/**
 * What the AI desk hands a responder about the files on the trigger message.
 *
 * The job is run by hand against rows written straight to the tables, so the
 * cases measure the turn the job builds and nothing upstream of it: only the
 * trigger's customer uploads that are images reach the responder, as a disk
 * and a path and never as bytes.
 */
class SupportAiImagesTest extends TestCase
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
            Features::supportAttachments(),
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
            'add_feedback_columns_to_support_conversations_table.php',
            'create_support_message_attachments_table.php',
        ] as $file) {
            (require __DIR__ . '/../../../database/migrations/' . $file)->up();
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

    public function test_an_uploaded_image_on_the_trigger_reaches_the_responder_as_a_disk_and_a_path(): void
    {
        $responder = $this->bindResponder();
        [$conversation, $trigger] = $this->pendingTurn('What does this chart mean?');
        $this->attach($trigger, SupportAttachmentOrigin::UPLOAD, 'image/jpeg', 'support/chart.jpg');
        $this->attach($trigger, SupportAttachmentOrigin::UPLOAD, 'image/png', 'support/error.png');

        $this->runJob($conversation, $trigger);

        $this->assertSame(1, $responder->calls);
        $this->assertSame([
            [
                'disk' => 'local',
                'path' => 'support/chart.jpg',
                'mime' => 'image/jpeg',
            ],
            [
                'disk' => 'local',
                'path' => 'support/error.png',
                'mime' => 'image/png',
            ],
        ], $responder->turns[0]->images);
        $this->assertSame(0, $responder->turns[0]->pdfCount);
    }

    public function test_an_auto_screenshot_a_staff_file_and_a_pdf_are_not_images_for_the_model(): void
    {
        $responder = $this->bindResponder();
        [$conversation, $trigger] = $this->pendingTurn('The page looks broken.');
        $this->attach($trigger, SupportAttachmentOrigin::AUTO_SCREENSHOT, 'image/png', 'support/screenshot.png');
        $this->attach($trigger, SupportAttachmentOrigin::STAFF, 'image/png', 'support/staff.png');
        $this->attach($trigger, SupportAttachmentOrigin::UPLOAD, 'application/pdf', 'support/invoice.pdf');
        $this->attach($trigger, SupportAttachmentOrigin::UPLOAD, 'application/pdf', 'support/receipt.pdf');

        $this->runJob($conversation, $trigger);

        $this->assertSame(1, $responder->calls);
        $this->assertSame([], $responder->turns[0]->images);
        $this->assertSame(2, $responder->turns[0]->pdfCount);
    }

    public function test_an_image_on_an_earlier_message_is_not_sent_again(): void
    {
        $responder = $this->bindResponder();
        $user = $this->customer();
        $conversation = $this->conversation($user);
        $earlier = $this->customerMessage($conversation, 'Here is the chart.', now()->subMinutes(5));
        $this->attach($earlier, SupportAttachmentOrigin::UPLOAD, 'image/jpeg', 'support/earlier.jpg');
        $trigger = $this->customerMessage($conversation, 'Any update?', now());

        $this->runJob($conversation, $trigger);

        $this->assertSame(1, $responder->calls);
        $this->assertSame([], $responder->turns[0]->images);
        $this->assertCount(1, $responder->turns[0]->history);
    }

    public function test_an_image_with_no_text_hands_off_without_calling_the_responder(): void
    {
        $responder = $this->bindResponder();
        [$conversation, $trigger] = $this->pendingTurn('');
        $this->attach($trigger, SupportAttachmentOrigin::UPLOAD, 'image/png', 'support/only.png');

        $this->runJob($conversation, $trigger);

        $conversation->refresh();
        $this->assertSame(0, $responder->calls);
        $this->assertSame(SupportConversationStatus::OPEN, $conversation->status);
        $this->assertSame(SupportHandoffReason::LOW_CONFIDENCE, $conversation->handoff_reason);
        $this->assertSame(0, SupportMessage::query()->where('sender_type', SupportSenderType::AI->value)->count());
    }

    public function test_a_pdf_with_no_text_hands_off_without_calling_the_responder(): void
    {
        $responder = $this->bindResponder();
        [$conversation, $trigger] = $this->pendingTurn('');
        $this->attach($trigger, SupportAttachmentOrigin::UPLOAD, 'application/pdf', 'support/only.pdf');

        $this->runJob($conversation, $trigger);

        $conversation->refresh();
        $this->assertSame(0, $responder->calls);
        $this->assertSame(SupportHandoffReason::LOW_CONFIDENCE, $conversation->handoff_reason);
    }

    public function test_the_reservation_grows_by_the_image_estimate_per_image(): void
    {
        config()->set('magic-starter.support.ai.pool_reservation_tokens', 6000);
        config()->set('magic-starter.support.ai.image_reservation_tokens', 1600);
        $conversation = $this->conversation($this->customer());
        $quota = $this->app->make(SupportAiQuota::class);

        $this->assertSame(6000, $quota->reserve($conversation, 'turn-0', SupportAiPool::FREE)?->tokens);
        $this->assertSame(7600, $quota->reserve($conversation, 'turn-1', SupportAiPool::FREE, images: 1)?->tokens);
        $this->assertSame(9200, $quota->reserve($conversation, 'turn-2', SupportAiPool::FREE, images: 2)?->tokens);
    }

    public function test_the_estimate_falls_back_to_its_default_in_a_config_that_predates_it(): void
    {
        config()->set('magic-starter.support.ai', [
            ...Arr::except(config('magic-starter.support.ai'), 'image_reservation_tokens'),
            'pool_reservation_tokens' => 6000,
        ]);
        $conversation = $this->conversation($this->customer());

        $reservation = $this->app->make(SupportAiQuota::class)
            ->reserve($conversation, 'turn-1', SupportAiPool::FREE, images: 3);

        $this->assertSame(6000 + 3 * 1600, $reservation?->tokens);
    }

    public function test_the_job_reserves_the_image_estimate_before_it_asks_the_model(): void
    {
        config()->set('magic-starter.support.ai.pool_reservation_tokens', 6000);
        config()->set('magic-starter.support.ai.image_reservation_tokens', 1600);
        $reserved = null;
        $user = $this->customer();
        $this->bindResponder(function () use (&$reserved, $user): SupportAnswer {
            $reserved = (int) Cache::get(sprintf(
                'magic-starter:support-ai:tokens:%s:%s',
                $user->getKey(),
                now()->format('Y-m-d'),
            ));

            return new SupportAnswer('It is the chart legend.', 0.9, new SupportUsage(300, 100));
        });
        $conversation = $this->conversation($user);
        $trigger = $this->customerMessage($conversation, 'What is this?', now());
        $this->attach($trigger, SupportAttachmentOrigin::UPLOAD, 'image/jpeg', 'support/a.jpg');
        $this->attach($trigger, SupportAttachmentOrigin::UPLOAD, 'image/webp', 'support/b.webp');

        $this->runJob($conversation, $trigger);

        $this->assertSame(6000 + 2 * 1600, $reserved);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Bind a responder that records each turn and answers through `$respond`, or with a fixed safe answer.
     */
    private function bindResponder(?Closure $respond = null): RecordingResponder
    {
        $responder = new RecordingResponder($respond);
        $this->app->instance(RespondsToSupport::class, $responder);

        return $responder;
    }

    /**
     * A pending conversation and the one customer message it waits on.
     *
     * @return array{0: SupportConversation, 1: SupportMessage}
     */
    private function pendingTurn(string $body): array
    {
        $conversation = $this->conversation($this->customer());

        return [
            $conversation,
            $this->customerMessage($conversation, $body, now()),
        ];
    }

    private function runJob(SupportConversation $conversation, SupportMessage $trigger): void
    {
        $this->app->call([
            new RespondToSupportConversation($conversation->getKey(), $trigger->getKey()),
            'handle',
        ]);
    }

    private function customer(): ConcreteUser
    {
        return ConcreteUser::create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'secret',
            'email_verified_at' => now()->subDays(10),
            'is_guest' => false,
        ]);
    }

    private function conversation(ConcreteUser $user): SupportConversation
    {
        return SupportConversation::create([
            'user_id' => $user->getKey(),
            'email' => $user->getAttribute('email'),
            'subject' => 'Checks fail',
            'source' => SupportConversationSource::IN_APP,
            'status' => SupportConversationStatus::PENDING,
        ]);
    }

    /**
     * Write a customer message and make it the one the desk owes an answer to.
     */
    private function customerMessage(SupportConversation $conversation, string $body, Carbon $at): SupportMessage
    {
        $message = (new SupportMessage)->forceFill([
            'conversation_id' => $conversation->getKey(),
            'sender_type' => SupportSenderType::CUSTOMER,
            'sender_id' => $conversation->user_id,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => $body,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $message->save();

        $conversation->forceFill(['last_customer_message_id' => $message->getKey()])->save();

        return $message;
    }

    private function attach(
        SupportMessage $message,
        SupportAttachmentOrigin $origin,
        string $mime,
        string $path,
    ): SupportMessageAttachment {
        return SupportMessageAttachment::create([
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->getKey(),
            'origin' => $origin,
            'disk' => 'local',
            'path' => $path,
            'original_name' => basename($path),
            'mime' => $mime,
            'size' => 1024,
            'width' => $mime === 'application/pdf' ? null : 640,
            'height' => $mime === 'application/pdf' ? null : 480,
        ]);
    }
}

/**
 * A responder that records every turn and answers through a closure, never a model.
 */
class RecordingResponder implements RespondsToSupport
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
