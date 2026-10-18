<?php

namespace FlutterSdk\MagicStarter\Tests\Database;

use FlutterSdk\MagicStarter\Audit\Redactor;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class SupportAiSummaryMigrationTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../database/migrations/add_ai_summary_to_support_conversations_table.php';

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
    public function test_the_summary_columns_are_added_in_either_key_mode(bool $useUuids): void
    {
        config()->set('magic-starter.use_uuids', $useUuids);
        $this->migrateSupportTables();

        $this->assertFalse(Schema::hasColumn('support_conversations', 'ai_summary'));
        $this->assertFalse(Schema::hasColumn('support_conversations', 'ai_summary_through_id'));

        (require self::MIGRATION)->up();

        $this->assertTrue(Schema::hasColumns('support_conversations', [
            'ai_summary',
            'ai_summary_through_id',
        ]));
    }

    public function test_the_new_columns_are_nullable_and_round_trip(): void
    {
        $this->migrateSupportTables();
        (require self::MIGRATION)->up();

        $conversation = SupportConversation::create([
            'email' => 'visitor@example.com',
            'subject' => 'Billing question',
            'source' => SupportConversationSource::IN_APP,
        ])->refresh();

        $this->assertNull($conversation->ai_summary);
        $this->assertNull($conversation->ai_summary_through_id);

        $conversation->update([
            'ai_summary' => 'The customer asked about a double charge.',
            'ai_summary_through_id' => 7,
        ]);
        $conversation->refresh();

        $this->assertSame('The customer asked about a double charge.', $conversation->ai_summary);
        $this->assertSame('7', (string) $conversation->ai_summary_through_id);
    }

    public function test_running_up_again_is_a_no_op(): void
    {
        $this->migrateSupportTables();
        $migration = require self::MIGRATION;

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('support_conversations', [
            'ai_summary',
            'ai_summary_through_id',
        ]));
    }

    public function test_up_without_the_conversations_table_does_not_fail(): void
    {
        $this->assertFalse(Schema::hasTable('support_conversations'));

        (require self::MIGRATION)->up();

        $this->assertFalse(Schema::hasTable('support_conversations'));
    }

    public function test_down_removes_both_columns(): void
    {
        $this->migrateSupportTables();
        $migration = require self::MIGRATION;
        $migration->up();

        $migration->down();

        $this->assertFalse(Schema::hasColumn('support_conversations', 'ai_summary'));
        $this->assertFalse(Schema::hasColumn('support_conversations', 'ai_summary_through_id'));
        $this->assertTrue(Schema::hasColumn('support_conversations', 'answered_message_id'));
    }

    public function test_the_summary_is_kept_out_of_the_audit_rows(): void
    {
        $sensitive = Redactor::sensitiveKeys(new SupportConversation);

        $this->assertContains('ai_summary', $sensitive);
        $this->assertNotContains('ai_summary_through_id', $sensitive);
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
}
