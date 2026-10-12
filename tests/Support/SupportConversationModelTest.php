<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Enums\SupportMessageKind;
use FlutterSdk\MagicStarter\Enums\SupportPriority;
use FlutterSdk\MagicStarter\Enums\SupportSenderType;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Locks the support tables under both key modes and the one rule the customer
 * side reads everything through: a staff note is never customer-visible.
 */
class SupportConversationModelTest extends TestCase
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
    public function test_the_migrations_store_a_conversation_and_its_messages_in_either_key_mode(bool $useUuids): void
    {
        // 1. The key mode is read at migration time, so it is set before the tables exist.
        config()->set('magic-starter.use_uuids', $useUuids);
        $this->migrateSupportTables();
        $this->assertTrue(Schema::hasIndex('support_conversations', ['user_id', 'last_activity_at']));
        $this->assertTrue(Schema::hasIndex('support_conversations', ['team_id']));
        $this->assertTrue(Schema::hasIndex('support_conversations', ['status', 'last_activity_at']));
        $this->assertTrue(Schema::hasIndex('support_conversations', ['email']));

        // 2. A signed-in opener inside a team, answered by a staff member.
        $user = $this->createUser('ada@example.com');
        $staff = $this->createUser('staff@example.com');
        $team = ConcreteTeam::create([
            'user_id' => $user->getKey(),
            'name' => 'Ada Team',
            'personal_team' => true,
        ]);
        $conversation = SupportConversation::create([
            'team_id' => $team->getKey(),
            'user_id' => $user->getKey(),
            'email' => 'ada@example.com',
            'name' => 'Ada',
            'subject' => 'Checks fail',
            'source' => SupportConversationSource::IN_APP,
            'assignee_id' => $staff->getKey(),
        ]);
        $conversation->messages()->create([
            'sender_type' => SupportSenderType::CUSTOMER,
            'sender_id' => $user->getKey(),
            'kind' => SupportMessageKind::MESSAGE,
            'body' => 'My checks fail.',
        ]);

        // 3. The keys follow the mode, the defaults hold, and the relations resolve.
        $conversation = SupportConversation::query()->sole();
        $this->assertSame($useUuids, Str::isUuid((string) $conversation->getKey()));
        $this->assertSame(SupportConversationStatus::OPEN, $conversation->status);
        $this->assertSame(SupportPriority::NORMAL, $conversation->priority);
        $this->assertSame(SupportConversationSource::IN_APP, $conversation->source);
        $this->assertSame(0, $conversation->thread_token_version);
        $this->assertSame((string) $user->getKey(), (string) $conversation->user?->getKey());
        $this->assertSame((string) $team->getKey(), (string) $conversation->team?->getKey());
        $this->assertSame((string) $staff->getKey(), (string) $conversation->assignee?->getKey());
        $message = $conversation->messages()->sole();
        $this->assertSame((string) $user->getKey(), (string) $message->sender?->getKey());
        $this->assertSame($conversation->getKey(), $message->conversation->getKey());
    }

    public function test_a_public_conversation_has_no_opener_and_no_team(): void
    {
        $this->migrateSupportTables();

        $conversation = SupportConversation::create([
            'email' => 'visitor@example.com',
            'name' => 'Visitor',
            'subject' => 'Pricing',
            'source' => SupportConversationSource::CONTACT_SALES,
        ]);

        $conversation->refresh();
        $this->assertNull($conversation->user_id);
        $this->assertNull($conversation->team_id);
        $this->assertNull($conversation->handoff_reason);
    }

    public function test_each_conversation_gets_the_next_display_number(): void
    {
        $this->migrateSupportTables();

        $first = $this->createPublicConversation();
        $second = $this->createPublicConversation();

        $this->assertSame(1, $first->refresh()->number);
        $this->assertSame(2, $second->refresh()->number);
    }

    public function test_customer_visible_drops_a_note(): void
    {
        $this->migrateSupportTables();
        $conversation = $this->createPublicConversation();
        foreach ([
            SupportMessageKind::MESSAGE,
            SupportMessageKind::NOTE,
            SupportMessageKind::ACTIVITY,
        ] as $kind) {
            $conversation->messages()->create([
                'sender_type' => SupportSenderType::STAFF,
                'kind' => $kind,
                'body' => $kind->value,
            ]);
        }

        $visible = $conversation->messages()->customerVisible()->pluck('body')->sort()->values()->all();

        $this->assertSame(
            [
                'activity',
                'message',
            ],
            $visible,
        );
        $this->assertSame(3, $conversation->messages()->count());
    }

    public function test_has_support_features_is_false_until_the_flag_is_listed(): void
    {
        config(['magic-starter.features' => []]);
        $this->assertFalse(Features::hasSupportFeatures());

        config(['magic-starter.features' => [Features::teams()]]);
        $this->assertFalse(Features::hasSupportFeatures());

        config(['magic-starter.features' => [Features::support()]]);
        $this->assertTrue(Features::hasSupportFeatures());
    }

    public function test_the_shipped_config_carries_the_support_defaults(): void
    {
        $this->assertSame(2000, config('magic-starter.support.message_max_length'));
        $this->assertSame(14, config('magic-starter.support.public.link_ttl_days'));
        $this->assertSame([], config('magic-starter.support.public.extra_rules'));
    }

    private function migrateSupportTables(): void
    {
        foreach ([
            'create_users_table.php',
            'create_teams_table.php',
            'create_support_conversations_table.php',
            'create_support_messages_table.php',
        ] as $file) {
            $this->artisan('migrate', [
                '--path' => __DIR__ . '/../../database/migrations/' . $file,
                '--realpath' => true,
            ]);
        }
    }

    private function createUser(string $email): ConcreteUser
    {
        return ConcreteUser::create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => 'secret',
        ]);
    }

    private function createPublicConversation(): SupportConversation
    {
        return SupportConversation::create([
            'email' => 'visitor@example.com',
            'subject' => 'Question',
            'source' => SupportConversationSource::PUBLIC_FORM,
        ]);
    }
}
