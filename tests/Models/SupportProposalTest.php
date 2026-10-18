<?php

namespace FlutterSdk\MagicStarter\Tests\Models;

use FlutterSdk\MagicStarter\Console\InstallCommand;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportProposalAction;
use FlutterSdk\MagicStarter\Enums\SupportProposalStatus;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Models\SupportMessage;
use FlutterSdk\MagicStarter\Models\SupportProposal;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClassConstant;

/**
 * Locks the `support_proposals` table, the model over it, and the flag and
 * config the proposal flow reads: what the row stores, when it still counts as
 * open, and how a drafted row becomes visible to the customer.
 */
class SupportProposalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-18 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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
    public function test_the_migration_creates_the_table_with_its_columns_and_indexes(bool $useUuids): void
    {
        $this->migrateSupportTables($useUuids);

        $this->assertTrue(Schema::hasColumns('support_proposals', [
            'id',
            'conversation_id',
            'billable_type',
            'billable_id',
            'requested_by',
            'action',
            'params',
            'rationale',
            'trigger_message_id',
            'status',
            'expires_at',
            'decided_by',
            'decided_at',
            'decision_reason',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasIndex('support_proposals', ['conversation_id', 'status']));
        $this->assertTrue(Schema::hasIndex('support_proposals', ['status']));
        $this->assertTrue(Schema::hasIndex('support_proposals', ['billable_type', 'billable_id']));
        $this->assertSame($useUuids, Str::isUuid((string) $this->proposal()->getKey()));
    }

    public function test_the_foreign_keys_cascade_with_the_conversation_and_null_with_the_rest(): void
    {
        $this->migrateSupportTables(true);

        $foreignKeys = [];

        foreach (Schema::getForeignKeys('support_proposals') as $foreignKey) {
            $foreignKeys[$foreignKey['columns'][0]] = [
                $foreignKey['foreign_table'],
                $foreignKey['on_delete'],
            ];
        }

        ksort($foreignKeys);

        $this->assertSame([
            'conversation_id' => ['support_conversations', 'cascade'],
            'decided_by' => ['users', 'set null'],
            'requested_by' => ['users', 'set null'],
            'trigger_message_id' => ['support_messages', 'set null'],
        ], $foreignKeys);
    }

    public function test_deleting_the_conversation_removes_its_proposals_and_deleting_a_user_keeps_them(): void
    {
        $this->migrateSupportTables(true);
        Schema::enableForeignKeyConstraints();
        $staff = $this->createUser('staff@example.com');
        $kept = $this->proposal(attributes: [
            'requested_by' => $staff->getKey(),
            'decided_by' => $staff->getKey(),
        ]);
        $this->proposal($this->conversation());

        $staff->delete();

        $kept->refresh();
        $this->assertNull($kept->requested_by);
        $this->assertNull($kept->decided_by);

        $kept->conversation->delete();

        $this->assertSame(1, SupportProposal::query()->count());
    }

    public function test_the_migration_reverses_cleanly_and_a_second_run_is_a_no_op(): void
    {
        $this->migrateSupportTables(true);

        (require $this->migrationPath('create_support_proposals_table.php'))->up();
        $this->assertTrue(Schema::hasTable('support_proposals'));

        (require $this->migrationPath('create_support_proposals_table.php'))->down();

        $this->assertFalse(Schema::hasTable('support_proposals'));
        $this->assertTrue(Schema::hasTable('support_conversations'));
        $this->assertTrue(Schema::hasTable('support_messages'));
    }

    #[DataProvider('keyModes')]
    public function test_the_model_casts_and_relates_in_either_key_mode(bool $useUuids): void
    {
        $this->migrateSupportTables($useUuids);
        $customer = $this->createUser();
        $staff = $this->createUser('staff@example.com');
        $conversation = $this->conversation();
        $trigger = $this->message($conversation);

        $proposal = SupportProposal::create([
            'conversation_id' => $conversation->getKey(),
            'billable_type' => $customer->getMorphClass(),
            'billable_id' => $customer->getKey(),
            'requested_by' => $staff->getKey(),
            'action' => SupportProposalAction::EXTEND_TRIAL,
            'params' => [
                'days' => 7,
            ],
            'rationale' => 'The customer lost a week to an outage.',
            'trigger_message_id' => $trigger->getKey(),
            'status' => SupportProposalStatus::PENDING,
            'expires_at' => now()->addHours(72),
            'decided_by' => $customer->getKey(),
            'decided_at' => now(),
            'decision_reason' => 'Approved.',
        ]);

        $proposal = SupportProposal::query()->sole();

        $this->assertSame(['days' => 7], $proposal->params);
        $this->assertSame(SupportProposalAction::EXTEND_TRIAL, $proposal->action);
        $this->assertSame(SupportProposalStatus::PENDING, $proposal->status);
        $this->assertInstanceOf(Carbon::class, $proposal->expires_at);
        $this->assertInstanceOf(Carbon::class, $proposal->decided_at);
        $this->assertTrue($proposal->expires_at->equalTo(now()->addHours(72)));

        $this->assertSame($conversation->getKey(), $proposal->conversation->getKey());
        $this->assertSame($trigger->getKey(), $proposal->triggerMessage?->getKey());
        $this->assertSame((string) $staff->getKey(), (string) $proposal->requester?->getKey());
        $this->assertSame((string) $customer->getKey(), (string) $proposal->decider?->getKey());
        $this->assertSame($proposal->getKey(), $conversation->proposals()->sole()->getKey());
    }

    public function test_a_proposal_may_leave_the_optional_columns_empty(): void
    {
        $this->migrateSupportTables(true);

        $proposal = $this->proposal()->refresh();

        $this->assertNull($proposal->requested_by);
        $this->assertNull($proposal->requester);
        $this->assertNull($proposal->rationale);
        $this->assertNull($proposal->trigger_message_id);
        $this->assertNull($proposal->triggerMessage);
        $this->assertNull($proposal->decided_by);
        $this->assertNull($proposal->decider);
        $this->assertNull($proposal->decided_at);
        $this->assertNull($proposal->decision_reason);
    }

    public function test_is_expired_is_true_for_an_open_row_past_its_deadline_and_false_for_a_decided_one(): void
    {
        $this->migrateSupportTables(true);

        $this->assertTrue($this->proposal(attributes: [
            'status' => SupportProposalStatus::PENDING,
            'expires_at' => now()->subSecond(),
        ])->isExpired());
        $this->assertTrue($this->proposal(attributes: [
            'status' => SupportProposalStatus::DRAFT,
            'expires_at' => now(),
        ])->isExpired());
        $this->assertFalse($this->proposal(attributes: [
            'status' => SupportProposalStatus::PENDING,
            'expires_at' => now()->addSecond(),
        ])->isExpired());
        $this->assertFalse($this->proposal(attributes: [
            'status' => SupportProposalStatus::APPROVED,
            'expires_at' => now()->subDay(),
        ])->isExpired());
    }

    public function test_open_keeps_live_drafts_and_pending_rows_and_drops_expired_and_decided_ones(): void
    {
        $this->migrateSupportTables(true);
        $future = now()->addHour();

        $live = [
            $this->proposal(attributes: ['status' => SupportProposalStatus::DRAFT, 'expires_at' => $future]),
            $this->proposal(attributes: ['status' => SupportProposalStatus::PENDING, 'expires_at' => $future]),
        ];
        $this->proposal(attributes: [
            'status' => SupportProposalStatus::PENDING,
            'expires_at' => now()->subMinute(),
        ]);
        $this->proposal(attributes: [
            'status' => SupportProposalStatus::DRAFT,
            'expires_at' => now(),
        ]);

        foreach ([
            SupportProposalStatus::EXECUTING,
            SupportProposalStatus::APPROVED,
            SupportProposalStatus::REJECTED,
            SupportProposalStatus::FAILED,
            SupportProposalStatus::EXPIRED,
        ] as $status) {
            $this->proposal(attributes: ['status' => $status, 'expires_at' => $future]);
        }

        $this->assertEqualsCanonicalizing(
            array_map(static fn (SupportProposal $proposal): string|int => $proposal->getKey(), $live),
            SupportProposal::query()->open()->pluck('id')->all(),
        );
    }

    public function test_promote_drafts_flips_only_that_conversations_drafts_for_that_trigger(): void
    {
        $this->migrateSupportTables(true);
        $conversation = $this->conversation();
        $other = $this->conversation();
        $trigger = $this->message($conversation);
        $laterTrigger = $this->message($conversation);
        $otherTrigger = $this->message($other);

        $first = $this->proposal($conversation, [
            'status' => SupportProposalStatus::DRAFT,
            'trigger_message_id' => $trigger->getKey(),
        ]);
        $second = $this->proposal($conversation, [
            'status' => SupportProposalStatus::DRAFT,
            'trigger_message_id' => $trigger->getKey(),
        ]);
        $laterTurn = $this->proposal($conversation, [
            'status' => SupportProposalStatus::DRAFT,
            'trigger_message_id' => $laterTrigger->getKey(),
        ]);
        $decided = $this->proposal($conversation, [
            'status' => SupportProposalStatus::REJECTED,
            'trigger_message_id' => $trigger->getKey(),
        ]);
        $foreign = $this->proposal($other, [
            'status' => SupportProposalStatus::DRAFT,
            'trigger_message_id' => $otherTrigger->getKey(),
        ]);

        $promoted = SupportProposal::promoteDrafts($conversation, $trigger->getKey());

        $this->assertSame(2, $promoted);
        $this->assertSame(SupportProposalStatus::PENDING, $first->refresh()->status);
        $this->assertSame(SupportProposalStatus::PENDING, $second->refresh()->status);
        $this->assertSame(SupportProposalStatus::DRAFT, $laterTurn->refresh()->status);
        $this->assertSame(SupportProposalStatus::REJECTED, $decided->refresh()->status);
        $this->assertSame(SupportProposalStatus::DRAFT, $foreign->refresh()->status);
        $this->assertSame(0, SupportProposal::promoteDrafts($conversation, $trigger->getKey()));
    }

    public function test_the_status_enum_carries_the_stored_values_and_knows_which_are_open(): void
    {
        $this->assertSame(
            ['draft', 'pending', 'executing', 'approved', 'rejected', 'failed', 'expired'],
            array_column(SupportProposalStatus::cases(), 'value'),
        );

        foreach (SupportProposalStatus::cases() as $status) {
            $this->assertSame(
                in_array($status, [SupportProposalStatus::DRAFT, SupportProposalStatus::PENDING], true),
                $status->isOpen(),
                "{$status->value} open state.",
            );
        }
    }

    public function test_the_action_enum_carries_the_stored_values_and_resolves_its_labels(): void
    {
        $this->assertSame(
            ['extend_trial', 'cancel_at_period_end', 'resume'],
            array_column(SupportProposalAction::cases(), 'value'),
        );

        $this->assertSame('Extend the trial', SupportProposalAction::EXTEND_TRIAL->label());
        $this->assertSame('Cancel at the end of the period', SupportProposalAction::CANCEL_AT_PERIOD_END->label());
        $this->assertSame('Resume the subscription', SupportProposalAction::RESUME->label());

        $this->app->setLocale('tr');

        foreach (SupportProposalAction::cases() as $action) {
            $key = 'magic-starter::admin_support.proposals.actions.' . $action->value;

            $this->assertNotSame($key, $action->label(), "{$action->value} has a Turkish label.");
            $this->assertNotSame(
                (string) __($key, [], 'en'),
                $action->label(),
                "{$action->value} is translated, not copied from English.",
            );
        }
    }

    public function test_the_proposals_flag_is_off_until_listed_and_needs_support_and_billing(): void
    {
        $this->assertSame('support-proposals', Features::supportProposals());

        config(['magic-starter.features' => [Features::support(), Features::billing()]]);
        $this->assertFalse(Features::hasSupportProposalFeatures(), 'Off until listed.');

        config(['magic-starter.features' => [Features::supportProposals()]]);
        $this->assertFalse(Features::hasSupportProposalFeatures(), 'Needs the support desk and billing.');

        config(['magic-starter.features' => [Features::supportProposals(), Features::support()]]);
        $this->assertFalse(Features::hasSupportProposalFeatures(), 'Needs billing: the owner gate is billing only.');

        config(['magic-starter.features' => [Features::supportProposals(), Features::billing()]]);
        $this->assertFalse(Features::hasSupportProposalFeatures(), 'Needs the support desk.');

        config(['magic-starter.features' => [
            Features::supportProposals(),
            Features::support(),
            Features::billing(),
        ]]);
        $this->assertTrue(Features::hasSupportProposalFeatures());
    }

    public function test_the_shipped_config_carries_the_proposal_defaults(): void
    {
        $this->assertSame([
            'expires_hours' => 72,
            'max_trial_extension_days' => 14,
        ], config('magic-starter.support.proposals'));
    }

    public function test_the_install_command_lists_the_migration_under_support_after_the_tables_it_points_at(): void
    {
        $migrations = (new ReflectionClassConstant(InstallCommand::class, 'FEATURE_MIGRATIONS'))->getValue();
        $support = $migrations['support'];
        $file = 'create_support_proposals_table.php';

        $this->assertContains($file, $support);
        $this->assertFileExists($this->migrationPath($file));

        foreach ([
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
            'create_support_feedback_table.php',
        ] as $parent) {
            $this->assertLessThan(
                array_search($file, $support, true),
                array_search($parent, $support, true),
                "{$parent} must be stamped before {$file}.",
            );
        }
    }

    private function migrateSupportTables(bool $useUuids): void
    {
        config()->set('magic-starter.use_uuids', $useUuids);

        foreach ([
            'create_users_table.php',
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
            'create_support_proposals_table.php',
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

    private function conversation(): SupportConversation
    {
        return SupportConversation::create([
            'email' => 'ada@example.com',
            'subject' => 'Trial ended too soon',
            'source' => SupportConversationSource::IN_APP,
        ]);
    }

    private function message(SupportConversation $conversation): SupportMessage
    {
        return $conversation->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'Can you extend my trial?',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function proposal(?SupportConversation $conversation = null, array $attributes = []): SupportProposal
    {
        $conversation ??= $this->conversation();

        return SupportProposal::create([
            'conversation_id' => $conversation->getKey(),
            'billable_type' => 'user',
            'billable_id' => $conversation->getKey(),
            'action' => SupportProposalAction::EXTEND_TRIAL,
            'params' => [
                'days' => 7,
            ],
            'status' => SupportProposalStatus::PENDING,
            'expires_at' => now()->addHours(72),
            ...$attributes,
        ]);
    }
}
