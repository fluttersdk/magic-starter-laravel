<?php

namespace FlutterSdk\MagicStarter\Tests\Support\SupportAi\Tools;

use FlutterSdk\MagicStarter\Contracts\SupportTool;
use FlutterSdk\MagicStarter\Enums\SupportAudience;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Enums\SupportConversationStatus;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportAi\Tools\SupportToolContext;
use FlutterSdk\MagicStarter\Support\SupportAi\Tools\SupportToolRegistry;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;

/**
 * Which team a support tool may read for, decided fresh on every call from the
 * conversation's opener and team: only while the opener still owns or belongs
 * to that team, never from the raw `team_id` alone.
 */
class SupportToolContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'create_users_table.php',
            'create_teams_table.php',
            'create_team_user_table.php',
            'create_support_conversations_table.php',
        ] as $file) {
            (require __DIR__ . '/../../../../database/migrations/' . $file)->up();
        }
    }

    public function test_a_member_gets_the_team(): void
    {
        $owner = $this->user('owner@example.com');
        $member = $this->user('member@example.com');
        $team = $this->team($owner, 'Acme');
        $member->teams()->attach($team->getKey(), [
            'role' => 'member',
        ]);
        $conversation = $this->conversation($member, $team);

        $context = SupportToolContext::forConversation($conversation);

        $this->assertTrue($team->is($context->team()));
        $this->assertSame($team->getKey(), $context->teamKey());
        $this->assertSame($conversation, $context->conversation());
    }

    public function test_the_owner_gets_the_team(): void
    {
        $owner = $this->user('owner@example.com');
        $team = $this->team($owner, 'Acme');

        $context = SupportToolContext::forConversation($this->conversation($owner, $team));

        $this->assertSame($team->getKey(), $context->teamKey());
    }

    public function test_an_opener_outside_the_team_gets_null(): void
    {
        $owner = $this->user('owner@example.com');
        $stranger = $this->user('stranger@example.com');
        $team = $this->team($owner, 'Acme');

        $context = SupportToolContext::forConversation($this->conversation($stranger, $team));

        $this->assertNull($context->team());
        $this->assertNull($context->teamKey());
    }

    public function test_a_removed_member_gets_null(): void
    {
        $owner = $this->user('owner@example.com');
        $member = $this->user('member@example.com');
        $team = $this->team($owner, 'Acme');
        $member->teams()->attach($team->getKey());
        $member->teams()->detach($team->getKey());

        $context = SupportToolContext::forConversation($this->conversation($member, $team));

        $this->assertNull($context->team());
        $this->assertNull($context->teamKey());
    }

    public function test_a_conversation_without_an_opener_gets_null(): void
    {
        $owner = $this->user('owner@example.com');
        $team = $this->team($owner, 'Acme');
        $conversation = $this->conversation($owner, $team);
        $conversation->forceFill([
            'user_id' => null,
        ])->save();

        $context = SupportToolContext::forConversation($conversation);

        $this->assertNull($context->team());
        $this->assertNull($context->teamKey());
    }

    public function test_a_conversation_without_a_team_gets_null(): void
    {
        $owner = $this->user('owner@example.com');
        $this->team($owner, 'Acme');

        $context = SupportToolContext::forConversation($this->conversation($owner, null));

        $this->assertNull($context->team());
        $this->assertNull($context->teamKey());
    }

    public function test_a_deleted_team_gets_null(): void
    {
        $owner = $this->user('owner@example.com');
        $team = $this->team($owner, 'Acme');
        $conversation = $this->conversation($owner, $team);
        $team->delete();

        $context = SupportToolContext::forConversation($conversation);

        $this->assertNull($context->team());
        $this->assertNull($context->teamKey());
    }

    public function test_a_member_removed_after_the_context_was_built_gets_null_on_the_next_call(): void
    {
        $owner = $this->user('owner@example.com');
        $member = $this->user('member@example.com');
        $team = $this->team($owner, 'Acme');
        $member->teams()->attach($team->getKey());
        $context = SupportToolContext::forConversation($this->conversation($member, $team));
        $this->assertSame($team->getKey(), $context->teamKey());

        $member->teams()->detach($team->getKey());

        $this->assertNull($context->team());
        $this->assertNull($context->teamKey());
    }

    public function test_none_answers_null_for_everything(): void
    {
        $context = SupportToolContext::none();

        $this->assertNull($context->team());
        $this->assertNull($context->teamKey());
        $this->assertNull($context->conversation());
        $this->assertNull($context->opener());
    }

    public function test_a_conversation_context_reads_for_the_customer_unless_told_otherwise(): void
    {
        $owner = $this->user('owner@example.com');
        $conversation = $this->conversation($owner, $this->team($owner, 'Acme'));

        $this->assertSame(SupportAudience::CUSTOMER, SupportToolContext::forConversation($conversation)->audience());
        $this->assertSame(
            SupportAudience::STAFF,
            SupportToolContext::forConversation($conversation, SupportAudience::STAFF)->audience(),
        );
    }

    public function test_a_staff_context_reads_for_staff(): void
    {
        $owner = $this->user('owner@example.com');
        $conversation = $this->conversation($owner, $this->team($owner, 'Acme'));

        $context = SupportToolContext::forStaff($conversation);

        $this->assertSame(SupportAudience::STAFF, $context->audience());
        $this->assertSame($conversation, $context->conversation());
    }

    public function test_none_reads_for_the_public_page_only(): void
    {
        $this->assertSame(SupportAudience::PAGE, SupportToolContext::none()->audience());
    }

    public function test_a_member_is_the_opener(): void
    {
        $owner = $this->user('owner@example.com');
        $member = $this->user('member@example.com');
        $team = $this->team($owner, 'Acme');
        $member->teams()->attach($team->getKey());

        $context = SupportToolContext::forConversation($this->conversation($member, $team));

        $this->assertTrue($member->is($context->opener()));
    }

    public function test_a_removed_member_is_no_opener(): void
    {
        $owner = $this->user('owner@example.com');
        $member = $this->user('member@example.com');
        $team = $this->team($owner, 'Acme');
        $member->teams()->attach($team->getKey());
        $member->teams()->detach($team->getKey());

        $context = SupportToolContext::forConversation($this->conversation($member, $team));

        $this->assertNull($context->opener());
    }

    public function test_a_member_removed_after_the_context_was_built_is_no_opener_on_the_next_call(): void
    {
        $owner = $this->user('owner@example.com');
        $member = $this->user('member@example.com');
        $team = $this->team($owner, 'Acme');
        $member->teams()->attach($team->getKey());
        $context = SupportToolContext::forConversation($this->conversation($member, $team));
        $this->assertTrue($member->is($context->opener()));

        $member->teams()->detach($team->getKey());

        $this->assertNull($context->opener());
    }

    public function test_a_conversation_without_a_team_has_no_opener(): void
    {
        $owner = $this->user('owner@example.com');

        $context = SupportToolContext::forConversation($this->conversation($owner, null));

        $this->assertNull($context->opener());
    }

    public function test_two_contexts_through_one_registry_never_cross(): void
    {
        $ada = $this->user('ada@example.com');
        $grace = $this->user('grace@example.com');
        $adaTeam = $this->team($ada, 'Analytical');
        $graceTeam = $this->team($grace, 'Compiler');
        $adaContext = SupportToolContext::forConversation($this->conversation($ada, $adaTeam));
        $graceContext = SupportToolContext::forConversation($this->conversation($grace, $graceTeam));
        $registry = $this->app->make(SupportToolRegistry::class);
        $registry->register(SupportToolContextTestTeamNameTool::class);

        $this->assertSame('Analytical', $registry->resolve()[0]->handle($adaContext, []));
        $this->assertSame('Compiler', $registry->resolve()[0]->handle($graceContext, []));
        $this->assertSame('Analytical', $registry->resolve()[0]->handle($adaContext, []));
    }

    private function user(string $email): ConcreteUser
    {
        return ConcreteUser::create([
            'name' => 'User',
            'email' => $email,
            'password' => 'secret',
        ]);
    }

    private function team(ConcreteUser $owner, string $name): ConcreteTeam
    {
        return ConcreteTeam::create([
            'user_id' => $owner->getKey(),
            'name' => $name,
            'personal_team' => false,
        ]);
    }

    private function conversation(ConcreteUser $opener, ?ConcreteTeam $team): SupportConversation
    {
        return SupportConversation::create([
            'user_id' => $opener->getKey(),
            'team_id' => $team?->getKey(),
            'email' => $opener->getAttribute('email'),
            'subject' => 'Checks fail',
            'source' => SupportConversationSource::IN_APP,
            'status' => SupportConversationStatus::PENDING,
        ]);
    }
}

class SupportToolContextTestTeamNameTool implements SupportTool
{
    public function name(): string
    {
        return 'team_name';
    }

    public function description(): string
    {
        return 'The name of the team the customer writes for.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function handle(SupportToolContext $context, array $arguments): string
    {
        return (string) $context->team()?->getAttribute('name');
    }
}
