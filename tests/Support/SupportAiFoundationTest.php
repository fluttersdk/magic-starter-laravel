<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Contracts\AssistsSupportStaff;
use FlutterSdk\MagicStarter\Contracts\RespondsToSupport;
use FlutterSdk\MagicStarter\Enums\SupportAiPool;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportHandoffReason;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAnswer;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportStaffAssist;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportTurn;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportUsage;
use FlutterSdk\MagicStarter\Support\SupportRules;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Locks the foundation the AI support desk builds on: the additive columns,
 * their casts, the value objects' invariants, the one rule source and the
 * unbound contracts.
 */
class SupportAiFoundationTest extends TestCase
{
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
    public function test_the_ai_columns_exist_after_migrate_in_either_key_mode(bool $useUuids): void
    {
        config()->set('magic-starter.use_uuids', $useUuids);
        $this->migrateSupportTables();

        $this->assertTrue(Schema::hasColumns('support_conversations', [
            'topic',
            'last_customer_message_id',
            'answered_message_id',
            'ai_failures',
        ]));
        $this->assertTrue(Schema::hasColumn('support_messages', 'meta'));
    }

    public function test_the_ai_migration_is_idempotent(): void
    {
        $this->migrateSupportTables();

        $migration = require __DIR__ . '/../../database/migrations/add_ai_columns_to_support_tables.php';
        $migration->up();

        $this->assertTrue(Schema::hasColumn('support_conversations', 'topic'));
    }

    public function test_the_new_columns_round_trip_through_their_casts(): void
    {
        $this->migrateSupportTables();
        $conversation = SupportConversation::create([
            'email' => 'visitor@example.com',
            'subject' => 'Billing question',
            'source' => SupportConversationSource::IN_APP,
            'topic' => 'billing',
        ]);
        $message = $conversation->messages()->create([
            'sender_type' => SupportSenderType::AI,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'Your plan renews on the 1st.',
            'meta' => [
                'confidence' => 0.9,
                'tokens' => 120,
            ],
        ]);

        // 1. A fresh conversation reads the column defaults back.
        $conversation->refresh();
        $this->assertSame(0, $conversation->ai_failures);
        $this->assertNull($conversation->handoff_reason);
        $this->assertNull($conversation->last_customer_message_id);
        $this->assertNull($conversation->answered_message_id);

        // 2. The written values come back typed.
        $conversation->update([
            'handoff_reason' => SupportHandoffReason::LOW_CONFIDENCE,
            'ai_failures' => 2,
            'last_customer_message_id' => $message->getKey(),
            'answered_message_id' => $message->getKey(),
        ]);
        $conversation->refresh();
        $this->assertSame('billing', $conversation->topic);
        $this->assertSame(SupportHandoffReason::LOW_CONFIDENCE, $conversation->handoff_reason);
        $this->assertSame(2, $conversation->ai_failures);
        $this->assertSame((string) $message->getKey(), (string) $conversation->last_customer_message_id);
        $this->assertSame((string) $message->getKey(), (string) $conversation->answered_message_id);
        $this->assertSame(
            [
                'confidence' => 0.9,
                'tokens' => 120,
            ],
            $message->refresh()->meta,
        );
    }

    public function test_the_handoff_reasons_are_the_stored_vocabulary(): void
    {
        $this->assertSame(
            [
                'human_requested',
                'billing',
                'security',
                'outage',
                'low_confidence',
                'failures',
                'anger',
                'quota',
                'unavailable',
            ],
            array_column(SupportHandoffReason::cases(), 'value'),
        );
        $this->assertSame(
            [
                'free',
                'paid',
            ],
            array_column(SupportAiPool::cases(), 'value'),
        );
    }

    public function test_an_answer_carries_its_reply_handoff_confidence_and_usage(): void
    {
        $answer = new SupportAnswer(
            reply: 'A person will take it from here.',
            confidence: 0.4,
            usage: new SupportUsage(promptTokens: 100, completionTokens: 20),
            handoffReason: SupportHandoffReason::BILLING,
            handoffPriority: SupportPriority::HIGH,
        );

        $this->assertTrue($answer->handsOff());
        $this->assertSame(120, $answer->usage->total());
        $this->assertFalse((new SupportAnswer(
            reply: 'Done.',
            confidence: 1.0,
            usage: new SupportUsage(promptTokens: 0, completionTokens: 0),
        ))->handsOff());
    }

    /**
     * @return array<string, array{float}>
     */
    public static function outOfRangeConfidences(): array
    {
        return [
            'below zero' => [
                -0.01,
            ],
            'above one' => [
                1.01,
            ],
            'not a number' => [
                NAN,
            ],
        ];
    }

    #[DataProvider('outOfRangeConfidences')]
    public function test_an_answer_rejects_a_confidence_outside_zero_to_one(float $confidence): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SupportAnswer(
            reply: 'Hello.',
            confidence: $confidence,
            usage: new SupportUsage(promptTokens: 1, completionTokens: 1),
        );
    }

    public function test_an_answer_rejects_an_empty_reply(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SupportAnswer(
            reply: "  \n ",
            confidence: 0.5,
            usage: new SupportUsage(promptTokens: 1, completionTokens: 1),
        );
    }

    public function test_an_answer_rejects_a_handoff_reason_without_a_priority(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SupportAnswer(
            reply: 'Passing this on.',
            confidence: 0.5,
            usage: new SupportUsage(promptTokens: 1, completionTokens: 1),
            handoffReason: SupportHandoffReason::SECURITY,
        );
    }

    public function test_usage_sums_legs_and_rejects_negative_counts(): void
    {
        $total = (new SupportUsage(promptTokens: 10, completionTokens: 5))
            ->plus(new SupportUsage(promptTokens: 7, completionTokens: 3));

        $this->assertSame(17, $total->promptTokens);
        $this->assertSame(8, $total->completionTokens);
        $this->assertSame(25, $total->total());

        $this->expectException(InvalidArgumentException::class);
        new SupportUsage(promptTokens: -1, completionTokens: 0);
    }

    public function test_a_staff_assist_rejects_an_empty_summary_or_draft(): void
    {
        $assist = new SupportStaffAssist(summary: 'Customer asks about refunds.', draft: 'Hi, ...');
        $this->assertSame('Customer asks about refunds.', $assist->summary);

        $this->expectException(InvalidArgumentException::class);
        new SupportStaffAssist(summary: 'Summary.', draft: ' ');
    }

    public function test_a_turn_carries_customer_visible_history_of_its_own_conversation(): void
    {
        $this->migrateSupportTables();
        $conversation = $this->createConversation();
        $earlier = $this->addMessage($conversation, SupportSenderType::CUSTOMER, SupportMessageKind::MESSAGE);
        $trigger = $this->addMessage($conversation, SupportSenderType::CUSTOMER, SupportMessageKind::MESSAGE);

        $turn = new SupportTurn(
            conversation: $conversation,
            trigger: $trigger,
            history: [
                $earlier,
            ],
            locale: 'tr',
            deadlineSeconds: 75,
        );

        $this->assertSame(75, $turn->deadlineSeconds);
        $this->assertCount(1, $turn->history);
    }

    public function test_a_turn_rejects_a_staff_note_in_its_history(): void
    {
        $this->migrateSupportTables();
        $conversation = $this->createConversation();
        $note = $this->addMessage($conversation, SupportSenderType::STAFF, SupportMessageKind::NOTE);
        $trigger = $this->addMessage($conversation, SupportSenderType::CUSTOMER, SupportMessageKind::MESSAGE);

        $this->expectException(InvalidArgumentException::class);

        new SupportTurn(
            conversation: $conversation,
            trigger: $trigger,
            history: [
                $note,
            ],
            locale: 'en',
            deadlineSeconds: 75,
        );
    }

    public function test_a_turn_rejects_a_message_from_another_conversation(): void
    {
        $this->migrateSupportTables();
        $conversation = $this->createConversation();
        $foreign = $this->addMessage($this->createConversation(), SupportSenderType::CUSTOMER, SupportMessageKind::MESSAGE);
        $trigger = $this->addMessage($conversation, SupportSenderType::CUSTOMER, SupportMessageKind::MESSAGE);

        $this->expectException(InvalidArgumentException::class);

        new SupportTurn(
            conversation: $conversation,
            trigger: $trigger,
            history: [
                $foreign,
            ],
            locale: 'en',
            deadlineSeconds: 75,
        );
    }

    public function test_a_turn_rejects_a_trigger_the_customer_did_not_write(): void
    {
        $this->migrateSupportTables();
        $conversation = $this->createConversation();
        $staffReply = $this->addMessage($conversation, SupportSenderType::STAFF, SupportMessageKind::MESSAGE);

        $this->expectException(InvalidArgumentException::class);

        new SupportTurn(
            conversation: $conversation,
            trigger: $staffReply,
            history: [],
            locale: 'en',
            deadlineSeconds: 75,
        );
    }

    public function test_support_rules_reject_a_body_over_the_configured_maximum(): void
    {
        config()->set('magic-starter.support.message_max_length', 10);

        $this->assertTrue(Validator::make(
            [
                'body' => str_repeat('a', 10),
            ],
            [
                'body' => SupportRules::body(),
            ],
        )->passes());
        $this->assertTrue(Validator::make(
            [
                'body' => str_repeat('a', 11),
            ],
            [
                'body' => SupportRules::body(),
            ],
        )->fails());
    }

    public function test_support_rules_validate_a_conversation_against_the_door_and_the_topics(): void
    {
        $rules = SupportRules::conversation([
            SupportConversationSource::IN_APP,
        ]);
        $valid = [
            'subject' => 'Checks fail',
            'body' => 'My checks fail.',
            'topic' => 'technical',
            'source' => 'in_app',
        ];

        $this->assertTrue(Validator::make($valid, $rules)->passes());
        $this->assertTrue(Validator::make([...$valid, 'topic' => null], $rules)->passes());
        $this->assertTrue(Validator::make([...$valid, 'topic' => 'gossip'], $rules)->fails());
        $this->assertTrue(Validator::make([...$valid, 'source' => 'public_form'], $rules)->fails());
        $this->assertTrue(Validator::make([...$valid, 'subject' => str_repeat('a', 256)], $rules)->fails());
    }

    public function test_a_published_support_block_without_topics_still_accepts_the_default_topics(): void
    {
        // A host that published the config before topics existed replaces the
        // whole `support` block (the merge is shallow), so the key is absent.
        $support = (array) config('magic-starter.support');
        unset($support['topics']);
        config(['magic-starter.support' => $support]);

        $rules = SupportRules::conversation([
            SupportConversationSource::IN_APP,
        ], topicRequired: true);
        $valid = [
            'body' => 'My checks fail.',
            'topic' => 'technical',
        ];

        $this->assertTrue(Validator::make($valid, $rules)->passes());
        $this->assertTrue(Validator::make([...$valid, 'topic' => 'gossip'], $rules)->fails());
        $this->assertSame(SupportRules::DEFAULT_TOPICS, SupportRules::topics());
    }

    public function test_neither_ai_contract_is_bound_by_the_package(): void
    {
        config(['magic-starter.features' => [Features::support()]]);

        $this->assertFalse($this->app->bound(RespondsToSupport::class));
        $this->assertFalse($this->app->bound(AssistsSupportStaff::class));
    }

    public function test_the_shipped_config_carries_the_ai_defaults(): void
    {
        $this->assertSame(
            [
                'billing',
                'technical',
                'account',
                'sales',
                'other',
            ],
            config('magic-starter.support.topics'),
        );
        $this->assertSame('/support/{id}', config('magic-starter.support.client_thread_path'));
        $this->assertSame('magic-starter::support.expectation', config('magic-starter.support.expectation'));
        $this->assertNull(config('magic-starter.support.ai.name'));
        $this->assertSame('redis', config('magic-starter.support.ai.connection'));
        $this->assertSame('support', config('magic-starter.support.ai.queue'));
        $this->assertSame(90, config('magic-starter.support.ai.job_timeout'));
        $this->assertSame(120, config('magic-starter.support.ai.lock_seconds'));
        $this->assertSame(75, config('magic-starter.support.ai.deadline_seconds'));
        $this->assertSame(12, config('magic-starter.support.ai.history_messages'));
        $this->assertSame(1500, config('magic-starter.support.ai.reply_max_length'));
        $this->assertSame(1200, config('magic-starter.support.ai.max_tokens_per_turn'));
        $this->assertSame(2, config('magic-starter.support.ai.failures_before_handoff'));
        $this->assertSame(5, config('magic-starter.support.ai.sweep_after_minutes'));
        $this->assertSame([], config('magic-starter.support.ai.allowed_hosts'));
        $this->assertSame(
            [
                'user_daily' => 20,
                'team_daily' => 40,
                'new_account_daily' => 5,
                'new_account_hours' => 24,
                'pools' => [
                    'free' => 200000,
                    'paid' => 1000000,
                ],
            ],
            config('magic-starter.support.ai.quotas'),
        );
    }

    public function test_the_customer_facing_lines_exist_in_english_and_turkish(): void
    {
        foreach ([
            'en',
            'tr',
        ] as $locale) {
            foreach ([
                'support.topics.billing',
                'support.topics.technical',
                'support.topics.account',
                'support.topics.sales',
                'support.topics.other',
                'support.ai.name',
                'support.ai.handoff',
                'support.expectation',
            ] as $key) {
                $this->assertTrue(
                    Lang::hasForLocale('magic-starter::' . $key, $locale),
                    "Missing {$locale} line magic-starter::{$key}.",
                );
            }
        }

        $this->assertSame('Acme AI', Lang::get('magic-starter::support.ai.name', ['app' => 'Acme'], 'en'));
    }

    private function migrateSupportTables(): void
    {
        foreach ([
            'create_users_table.php',
            'create_teams_table.php',
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

    private function createConversation(): SupportConversation
    {
        return SupportConversation::create([
            'email' => 'ada@example.com',
            'subject' => 'Checks fail',
            'source' => SupportConversationSource::IN_APP,
        ]);
    }

    private function addMessage(
        SupportConversation $conversation,
        SupportSenderType $sender,
        SupportMessageKind $kind,
    ): SupportMessage {
        return $conversation->messages()->create([
            'sender_type' => $sender,
            'kind' => $kind,
            'body' => $kind->value,
        ]);
    }
}
