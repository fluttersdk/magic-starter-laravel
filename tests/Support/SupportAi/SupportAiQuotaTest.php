<?php

namespace FlutterSdk\MagicStarter\Tests\Support\SupportAi;

use FlutterSdk\MagicStarter\Enums\SupportAiPool;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAiQuota;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportUsage;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * Locks the AI quota ledger: who may spend at all, the four daily ceilings,
 * that a retried turn is charged once, and that the token pool follows the
 * tokens a turn really used.
 */
class SupportAiQuotaTest extends TestCase
{
    private int $sequence = 0;

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
                '--path' => __DIR__ . '/../../../database/migrations/' . $file,
                '--realpath' => true,
            ]);
        }

        config()->set('magic-starter.support.ai.max_tokens_per_turn', 1200);
        config()->set('magic-starter.support.ai.quotas', [
            'user_daily' => 20,
            'team_daily' => 40,
            'new_account_daily' => 5,
            'new_account_hours' => 24,
            'pools' => [
                'free' => 200000,
                'paid' => 1000000,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_verified_non_guest_is_eligible(): void
    {
        $this->assertTrue($this->quota()->eligible($this->createUser()));
    }

    public function test_a_guest_is_not_eligible(): void
    {
        $this->assertFalse($this->quota()->eligible($this->createUser(guest: true)));
    }

    public function test_an_unverified_user_is_not_eligible(): void
    {
        $this->assertFalse($this->quota()->eligible($this->createUser(verified: false)));
    }

    public function test_the_twenty_first_turn_of_a_day_is_refused(): void
    {
        $user = $this->createUser();
        $conversation = $this->createConversation($user);
        $quota = $this->quota();

        for ($turn = 1; $turn <= 20; $turn++) {
            $this->assertNotNull(
                $quota->reserve($conversation, 'turn-' . $turn, SupportAiPool::FREE),
                'turn ' . $turn . ' should be granted',
            );
        }

        $this->assertNull($quota->reserve($conversation, 'turn-21', SupportAiPool::FREE));
    }

    public function test_the_next_day_starts_from_zero(): void
    {
        $this->setQuotas(userDaily: 1);
        $conversation = $this->createConversation($this->createUser());
        $quota = $this->quota();

        $this->assertNotNull($quota->reserve($conversation, 'turn-1', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));

        $this->travelTo(now()->addDay());

        $this->assertNotNull($quota->reserve($conversation, 'turn-3', SupportAiPool::FREE));
    }

    public function test_the_same_turn_reserved_twice_charges_once(): void
    {
        $this->setQuotas(userDaily: 2);
        $conversation = $this->createConversation($this->createUser());
        $quota = $this->quota();

        $first = $quota->reserve($conversation, 'turn-1', SupportAiPool::FREE);
        $again = $quota->reserve($conversation, 'turn-1', SupportAiPool::FREE);

        $this->assertNotNull($first);
        $this->assertEquals($first, $again);
        $this->assertNotNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($conversation, 'turn-3', SupportAiPool::FREE));
    }

    public function test_a_retry_of_a_refused_turn_stays_refused_and_charges_nothing(): void
    {
        $this->setQuotas(userDaily: 1);
        $user = $this->createUser();
        $conversation = $this->createConversation($user);
        $quota = $this->quota();

        $this->assertNotNull($quota->reserve($conversation, 'turn-1', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));

        $this->setQuotas(userDaily: 2);

        $this->assertNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));
        $this->assertNotNull($quota->reserve($conversation, 'turn-3', SupportAiPool::FREE));
    }

    public function test_one_turn_id_in_two_conversations_is_two_turns(): void
    {
        $this->setQuotas(userDaily: 2);
        $user = $this->createUser();
        $quota = $this->quota();

        $this->assertNotNull($quota->reserve($this->createConversation($user), 'turn-1', SupportAiPool::FREE));
        $this->assertNotNull($quota->reserve($this->createConversation($user), 'turn-1', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($this->createConversation($user), 'turn-2', SupportAiPool::FREE));
    }

    public function test_the_team_ceiling_is_shared_by_its_members(): void
    {
        $this->setQuotas(teamDaily: 3);
        $quota = $this->quota();
        $first = $this->createConversation($this->createUser(), teamId: 'team-1');
        $second = $this->createConversation($this->createUser(), teamId: 'team-1');
        $outsider = $this->createConversation($this->createUser(), teamId: 'team-2');

        $this->assertNotNull($quota->reserve($first, 'turn-1', SupportAiPool::FREE));
        $this->assertNotNull($quota->reserve($second, 'turn-2', SupportAiPool::FREE));
        $this->assertNotNull($quota->reserve($first, 'turn-3', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($second, 'turn-4', SupportAiPool::FREE));
        $this->assertNotNull($quota->reserve($outsider, 'turn-5', SupportAiPool::FREE));
    }

    public function test_a_conversation_without_a_team_skips_the_team_ceiling(): void
    {
        $this->setQuotas(teamDaily: 0);

        $this->assertNotNull(
            $this->quota()->reserve($this->createConversation($this->createUser()), 'turn-1', SupportAiPool::FREE),
        );
    }

    public function test_an_account_verified_two_hours_ago_is_capped_at_five(): void
    {
        $conversation = $this->createConversation($this->createUser(verifiedAt: now()->subHours(2)));
        $quota = $this->quota();

        for ($turn = 1; $turn <= 5; $turn++) {
            $this->assertNotNull(
                $quota->reserve($conversation, 'turn-' . $turn, SupportAiPool::FREE),
                'turn ' . $turn . ' should be granted',
            );
        }

        $this->assertNull($quota->reserve($conversation, 'turn-6', SupportAiPool::FREE));
    }

    public function test_an_account_verified_past_the_new_account_window_is_not_capped_at_five(): void
    {
        $conversation = $this->createConversation($this->createUser(verifiedAt: now()->subHours(25)));
        $quota = $this->quota();

        for ($turn = 1; $turn <= 6; $turn++) {
            $this->assertNotNull(
                $quota->reserve($conversation, 'turn-' . $turn, SupportAiPool::FREE),
                'turn ' . $turn . ' should be granted',
            );
        }
    }

    public function test_a_guest_or_an_unverified_user_is_never_granted_a_turn(): void
    {
        $quota = $this->quota();

        $this->assertNull($quota->reserve(
            $this->createConversation($this->createUser(guest: true)),
            'turn-1',
            SupportAiPool::FREE,
        ));
        $this->assertNull($quota->reserve(
            $this->createConversation($this->createUser(verified: false)),
            'turn-1',
            SupportAiPool::FREE,
        ));
    }

    public function test_a_conversation_without_a_user_is_never_granted_a_turn(): void
    {
        $conversation = SupportConversation::create([
            'email' => 'visitor@example.com',
            'subject' => 'Public question',
            'source' => SupportConversationSource::PUBLIC_FORM,
        ]);

        $this->assertNull($this->quota()->reserve($conversation, 'turn-1', SupportAiPool::FREE));
    }

    public function test_a_refusal_hands_back_what_the_earlier_ceilings_were_charged(): void
    {
        $this->setQuotas(userDaily: 1, teamDaily: 1);
        $quota = $this->quota();
        $member = $this->createUser();
        $other = $this->createUser();

        $otherInTeam = $this->createConversation($other, 'team-1');
        $memberInTeam = $this->createConversation($member, 'team-1');

        $this->assertNotNull($quota->reserve($otherInTeam, 'turn-1', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($memberInTeam, 'turn-2', SupportAiPool::FREE));

        $this->assertNotNull(
            $quota->reserve($this->createConversation($member), 'turn-3', SupportAiPool::FREE),
            'the refused turn must not have used up the member\'s own turn',
        );
    }

    public function test_the_pool_refuses_when_its_tokens_are_spent_and_pools_are_separate(): void
    {
        $this->setPools(free: 2400, paid: 2400);
        $conversation = $this->createConversation($this->createUser());
        $quota = $this->quota();

        $this->assertNotNull($quota->reserve($conversation, 'turn-1', SupportAiPool::FREE));
        $this->assertNotNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($conversation, 'turn-3', SupportAiPool::FREE));
        $this->assertNotNull($quota->reserve($conversation, 'turn-4', SupportAiPool::PAID));
    }

    public function test_a_refusal_by_the_pool_hands_back_the_user_turn(): void
    {
        $this->setPools(free: 1200);
        $this->setQuotas(userDaily: 2);
        $conversation = $this->createConversation($this->createUser());
        $quota = $this->quota();

        $this->assertNotNull($quota->reserve($conversation, 'turn-1', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));
        $this->assertNotNull($quota->reserve($conversation, 'turn-3', SupportAiPool::PAID));
    }

    public function test_reconcile_gives_back_the_tokens_a_turn_did_not_use(): void
    {
        $this->setPools(free: 2400);
        $conversation = $this->createConversation($this->createUser());
        $quota = $this->quota();

        $first = $quota->reserve($conversation, 'turn-1', SupportAiPool::FREE);
        $second = $quota->reserve($conversation, 'turn-2', SupportAiPool::FREE);
        $this->assertNotNull($first);
        $this->assertNotNull($second);

        $quota->reconcile($first, new SupportUsage(promptTokens: 60, completionTokens: 40));
        $quota->reconcile($second, new SupportUsage(promptTokens: 60, completionTokens: 40));

        // The pool now holds 200 tokens: one more turn fits (1400), a second does not (2600).
        $this->assertNotNull($quota->reserve($conversation, 'turn-3', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($conversation, 'turn-4', SupportAiPool::FREE));
    }

    public function test_reconcile_charges_the_tokens_a_turn_used_beyond_its_reservation(): void
    {
        $this->setPools(free: 2400);
        $conversation = $this->createConversation($this->createUser());
        $quota = $this->quota();

        $first = $quota->reserve($conversation, 'turn-1', SupportAiPool::FREE);
        $this->assertNotNull($first);

        $quota->reconcile($first, new SupportUsage(promptTokens: 1500, completionTokens: 500));

        // The pool now holds 2000 tokens: another 1200 no longer fits.
        $this->assertNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));
    }

    public function test_reconciling_the_same_reservation_twice_adjusts_the_pool_once(): void
    {
        $this->setPools(free: 2400);
        $conversation = $this->createConversation($this->createUser());
        $quota = $this->quota();

        $first = $quota->reserve($conversation, 'turn-1', SupportAiPool::FREE);
        $this->assertNotNull($first);

        $quota->reconcile($first, new SupportUsage(promptTokens: 60, completionTokens: 40));
        $quota->reconcile($first, new SupportUsage(promptTokens: 60, completionTokens: 40));

        // Once: the pool holds 100, so turn 2 (1300) fits and turn 3 (2500) does not.
        $this->assertNotNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($conversation, 'turn-3', SupportAiPool::FREE));
    }

    public function test_reserving_a_reconciled_turn_again_does_not_reopen_its_reservation(): void
    {
        $this->setPools(free: 2400);
        $conversation = $this->createConversation($this->createUser());
        $quota = $this->quota();

        $first = $quota->reserve($conversation, 'turn-1', SupportAiPool::FREE);
        $this->assertNotNull($first);
        $quota->reconcile($first, new SupportUsage(promptTokens: 60, completionTokens: 40));

        $again = $quota->reserve($conversation, 'turn-1', SupportAiPool::FREE);
        $this->assertNotNull($again);
        $quota->reconcile($again, new SupportUsage(promptTokens: 600, completionTokens: 400));

        // Neither the second reserve nor the second reconcile moved the pool off 100.
        $this->assertNotNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($conversation, 'turn-3', SupportAiPool::FREE));
    }

    public function test_reconcile_adjusts_the_day_the_tokens_were_reserved_on(): void
    {
        $this->setPools(free: 1200);
        $conversation = $this->createConversation($this->createUser());
        $quota = $this->quota();

        $first = $quota->reserve($conversation, 'turn-1', SupportAiPool::FREE);
        $this->assertNotNull($first);

        $this->travelTo(now()->addDay());
        $quota->reconcile($first, new SupportUsage(promptTokens: 0, completionTokens: 0));

        // Today's pool was never touched by the old turn: exactly one turn fits.
        $this->assertNotNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($conversation, 'turn-3', SupportAiPool::FREE));
    }

    private function quota(): SupportAiQuota
    {
        return $this->app->make(SupportAiQuota::class);
    }

    private function setQuotas(?int $userDaily = null, ?int $teamDaily = null): void
    {
        config()->set('magic-starter.support.ai.quotas.user_daily', $userDaily ?? 20);
        config()->set('magic-starter.support.ai.quotas.team_daily', $teamDaily ?? 40);
    }

    private function setPools(int $free = 200000, int $paid = 1000000): void
    {
        config()->set('magic-starter.support.ai.quotas.pools', [
            'free' => $free,
            'paid' => $paid,
        ]);
    }

    private function createUser(
        bool $guest = false,
        bool $verified = true,
        ?Carbon $verifiedAt = null,
    ): ConcreteUser {
        $this->sequence++;

        return ConcreteUser::create([
            'name' => 'user' . $this->sequence,
            'email' => 'user' . $this->sequence . '@example.com',
            'password' => 'secret',
            'email_verified_at' => $verified ? ($verifiedAt ?? now()->subDays(10)) : null,
            'is_guest' => $guest,
        ]);
    }

    private function createConversation(ConcreteUser $user, ?string $teamId = null): SupportConversation
    {
        return SupportConversation::create([
            'user_id' => $user->getKey(),
            'team_id' => $teamId,
            'email' => $user->getAttribute('email'),
            'subject' => 'Checks fail',
            'source' => SupportConversationSource::IN_APP,
        ]);
    }
}
