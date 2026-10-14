<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Audit\Audit;
use FlutterSdk\MagicStarter\Contracts\DescribesSupportCustomer;
use FlutterSdk\MagicStarter\Enums\SupportAttachmentOrigin;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportCsatRating;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportFeedback;
use FlutterSdk\MagicStarter\Models\SupportMessageAttachment;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The schema, models, flags and config the CSAT, feedback, attachment and
 * diagnostics work stands on, and the one audit rule that keeps customer text
 * and file rows out of the trail.
 */
class SupportFeedbackSchemaTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('magic-starter.features', [
            Features::support(),
            Features::audit(),
        ]);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function keyModes(): array
    {
        return [
            'uuid keys' => [
                true,
            ],
            'integer keys' => [
                false,
            ],
        ];
    }

    #[DataProvider('keyModes')]
    public function test_the_migrations_create_the_feedback_tables_and_columns(bool $useUuids): void
    {
        $this->migrateSupportTables($useUuids);

        $this->assertTrue(Schema::hasColumns('support_conversations', [
            'csat_rating',
            'csat_comment',
            'csat_rated_at',
            'csat_assignee_id',
            'csat_alerted_at',
            'client_context',
        ]));
        $this->assertTrue(Schema::hasColumns('support_message_attachments', [
            'id',
            'conversation_id',
            'message_id',
            'origin',
            'disk',
            'path',
            'original_name',
            'mime',
            'size',
            'width',
            'height',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('support_feedback', [
            'id',
            'user_id',
            'team_id',
            'score',
            'comment',
            'conversation_id',
            'created_at',
            'updated_at',
        ]));

        // A second run of the additive migration changes nothing.
        (require $this->migrationPath('add_feedback_columns_to_support_conversations_table.php'))->up();
        $this->assertTrue(Schema::hasColumn('support_conversations', 'csat_rating'));
        $this->assertSame($useUuids, Str::isUuid((string) $this->conversation()->getKey()));
    }

    public function test_the_migrations_reverse_cleanly(): void
    {
        $this->migrateSupportTables(true);

        foreach ([
            'create_support_feedback_table.php',
            'create_support_message_attachments_table.php',
            'add_feedback_columns_to_support_conversations_table.php',
        ] as $file) {
            (require $this->migrationPath($file))->down();
        }

        $this->assertFalse(Schema::hasTable('support_feedback'));
        $this->assertFalse(Schema::hasTable('support_message_attachments'));
        $this->assertFalse(Schema::hasColumn('support_conversations', 'csat_rating'));
        $this->assertFalse(Schema::hasColumn('support_conversations', 'csat_assignee_id'));
        $this->assertFalse(Schema::hasColumn('support_conversations', 'client_context'));
        $this->assertTrue(Schema::hasColumn('support_conversations', 'topic'));
    }

    #[DataProvider('keyModes')]
    public function test_the_models_cast_and_relate_in_either_key_mode(bool $useUuids): void
    {
        $this->migrateSupportTables($useUuids);
        $user = $this->createUser();
        $staff = $this->createUser('staff@example.com');

        $conversation = $this->conversation($user);
        $conversation->update([
            'csat_rating' => SupportCsatRating::DOWN,
            'csat_comment' => 'Too slow.',
            'csat_rated_at' => now(),
            'csat_assignee_id' => $staff->getKey(),
            'csat_alerted_at' => now(),
            'client_context' => [
                'route' => '/monitors',
                'platform' => 'ios',
            ],
        ]);
        $message = $conversation->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'sender_id' => $user->getKey(),
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'See the screenshot.',
        ]);
        $attachment = SupportMessageAttachment::create([
            'conversation_id' => $conversation->getKey(),
            'message_id' => $message->getKey(),
            'origin' => SupportAttachmentOrigin::AUTO_SCREENSHOT,
            'disk' => 'local',
            'path' => 'support/abc.png',
            'original_name' => 'screenshot.png',
            'mime' => 'image/png',
            'size' => 1024,
            'width' => 390,
            'height' => 844,
        ]);
        $feedback = SupportFeedback::create([
            'user_id' => $user->getKey(),
            'score' => 3,
            'comment' => 'Hard to find things.',
            'conversation_id' => $conversation->getKey(),
        ]);

        $conversation = SupportConversation::query()->sole();
        $this->assertSame(SupportCsatRating::DOWN, $conversation->csat_rating);
        $this->assertSame('Too slow.', $conversation->csat_comment);
        $this->assertNotNull($conversation->csat_rated_at);
        $this->assertNotNull($conversation->csat_alerted_at);
        $this->assertSame((string) $staff->getKey(), (string) $conversation->csat_assignee_id);
        $this->assertSame('/monitors', $conversation->client_context['route']);

        $this->assertSame($attachment->getKey(), $conversation->attachments()->sole()->getKey());
        $this->assertSame($attachment->getKey(), $message->attachments()->sole()->getKey());
        $this->assertSame(SupportAttachmentOrigin::AUTO_SCREENSHOT, $attachment->origin);
        $this->assertSame(1024, $attachment->size);
        $this->assertSame(390, $attachment->width);
        $this->assertSame($message->getKey(), $attachment->message->getKey());
        $this->assertSame($conversation->getKey(), $attachment->conversation->getKey());

        $this->assertSame(3, $feedback->refresh()->score);
        $this->assertSame((string) $user->getKey(), (string) $feedback->user->getKey());
        $this->assertSame($conversation->getKey(), $feedback->conversation?->getKey());
    }

    public function test_a_dismissed_feedback_row_has_a_null_score(): void
    {
        $this->migrateSupportTables(true);
        $user = $this->createUser();

        $feedback = SupportFeedback::create([
            'user_id' => $user->getKey(),
        ]);

        $this->assertNull($feedback->refresh()->score);
        $this->assertNull($feedback->conversation_id);
        $this->assertNull($feedback->conversation);
    }

    public function test_deleting_a_conversation_cascades_to_its_attachments_and_clears_the_feedback_pointer(): void
    {
        $this->migrateSupportTables(true);
        Schema::enableForeignKeyConstraints();
        $user = $this->createUser();
        $conversation = $this->conversation($user);
        $message = $conversation->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'Hello',
        ]);
        SupportMessageAttachment::create([
            'conversation_id' => $conversation->getKey(),
            'message_id' => $message->getKey(),
            'origin' => SupportAttachmentOrigin::UPLOAD,
            'disk' => 'local',
            'path' => 'support/a.pdf',
            'original_name' => 'a.pdf',
            'mime' => 'application/pdf',
            'size' => 10,
        ]);
        $feedback = SupportFeedback::create([
            'user_id' => $user->getKey(),
            'score' => 2,
            'conversation_id' => $conversation->getKey(),
        ]);

        $conversation->delete();

        $this->assertSame(0, SupportMessageAttachment::query()->count());
        $this->assertNull($feedback->refresh()->conversation_id);
    }

    public function test_the_four_features_are_off_by_default_and_on_when_listed(): void
    {
        $checks = [
            'support-csat' => [Features::supportCsat(), Features::hasSupportCsatFeatures(...)],
            'support-feedback' => [Features::supportFeedback(), Features::hasSupportFeedbackFeatures(...)],
            'support-attachments' => [Features::supportAttachments(), Features::hasSupportAttachmentsFeatures(...)],
            'support-diagnostics' => [Features::supportDiagnostics(), Features::hasSupportDiagnosticsFeatures(...)],
        ];

        foreach ($checks as $value => [$flag, $has]) {
            $this->assertSame($value, $flag);

            config(['magic-starter.features' => [Features::support()]]);
            $this->assertFalse($has(), "{$value} must be off until listed.");

            config(['magic-starter.features' => [Features::support(), $flag]]);
            $this->assertTrue($has(), "{$value} must be on when listed.");
        }
    }

    public function test_the_shipped_config_carries_the_attachment_and_feedback_defaults(): void
    {
        $this->assertSame([
            'disk' => 'local',
            'max_files' => 3,
            'max_kb' => 5120,
            'types' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
            'max_edge' => 8000,
            'max_pixels' => 40000000,
            'stored_max_edge' => 2048,
            'daily_files' => 30,
            'daily_kb' => 102400,
            'link_ttl_seconds' => 60,
        ], config('magic-starter.support.attachments'));

        $this->assertSame([
            'min_account_days' => 14,
            'interval_days' => 90,
            'low_score_max' => 6,
            'response_hours' => 48,
            'low_score_daily' => 1,
        ], config('magic-starter.support.feedback'));
    }

    public function test_the_enums_carry_the_stored_values(): void
    {
        $this->assertSame(['up', 'down'], array_column(SupportCsatRating::cases(), 'value'));
        $this->assertSame(
            ['upload', 'auto_screenshot', 'staff'],
            array_column(SupportAttachmentOrigin::cases(), 'value'),
        );
    }

    public function test_the_customer_context_contract_is_unbound_by_default(): void
    {
        $this->assertTrue(interface_exists(DescribesSupportCustomer::class));
        $this->assertFalse($this->app->bound(DescribesSupportCustomer::class));
    }

    public function test_an_attachment_and_a_feedback_row_write_no_audit_row(): void
    {
        $this->migrateSupportTables(true);
        $user = $this->createUser();
        $conversation = $this->conversation($user);

        // 1. Positive control: the conversation itself is audited, so an empty trail below proves a skip.
        $this->assertSame(
            1,
            Audit::query()->where('auditable_type', $conversation->getMorphClass())->count(),
        );
        $audited = Audit::query()->count();

        // 2. Saving either new model writes no row, on create and on update.
        $message = $conversation->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'Hello',
        ]);
        $attachment = SupportMessageAttachment::create([
            'conversation_id' => $conversation->getKey(),
            'message_id' => $message->getKey(),
            'origin' => SupportAttachmentOrigin::UPLOAD,
            'disk' => 'local',
            'path' => 'support/a.png',
            'original_name' => 'a.png',
            'mime' => 'image/png',
            'size' => 10,
        ]);
        $attachment->update(['size' => 11]);
        $feedback = SupportFeedback::create([
            'user_id' => $user->getKey(),
            'score' => 4,
            'comment' => 'Private words.',
        ]);
        $feedback->update(['comment' => 'Other private words.']);

        $this->assertSame(0, Audit::query()->where('auditable_type', $attachment->getMorphClass())->count());
        $this->assertSame(0, Audit::query()->where('auditable_type', $feedback->getMorphClass())->count());
        $this->assertSame($audited + 1, Audit::query()->count(), 'Only the message is audited.');
    }

    private function migrateSupportTables(bool $useUuids): void
    {
        config()->set('magic-starter.use_uuids', $useUuids);

        foreach ([
            'create_users_table.php',
            'create_magic_starter_audits_table.php',
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
            'add_ai_columns_to_support_tables.php',
            'add_feedback_columns_to_support_conversations_table.php',
            'create_support_message_attachments_table.php',
            'create_support_feedback_table.php',
        ] as $file) {
            (require $this->migrationPath($file))->up();
        }
    }

    private function migrationPath(string $file): string
    {
        return __DIR__ . '/../../database/migrations/' . $file;
    }

    private function createUser(string $email = 'ada@example.com'): ConcreteUser
    {
        return ConcreteUser::create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => 'secret',
        ]);
    }

    private function conversation(?ConcreteUser $user = null): SupportConversation
    {
        return SupportConversation::create([
            'user_id' => $user?->getKey(),
            'email' => 'ada@example.com',
            'subject' => 'Checks fail',
            'source' => SupportConversationSource::IN_APP,
        ]);
    }
}
