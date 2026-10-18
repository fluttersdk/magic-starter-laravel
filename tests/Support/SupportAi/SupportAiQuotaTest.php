<?php

namespace FlutterSdk\MagicStarter\Tests\Support\SupportAi;

use FlutterSdk\MagicStarter\Enums\SupportAiPool;
use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use FlutterSdk\MagicStarter\Support\SupportAi\Reservation;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportAiQuota;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportUsage;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Carbon;
use ReflectionClass;

/**
 * Locks the AI quota ledger: who may spend at all, the four daily ceilings,
 * that a retried turn is charged once, and that the token pool follows the
 * tokens a turn really used.
 */
class SupportAiQuotaTest extends TestCase
{
    private const TEAM_A = '0194f1c2-0000-7000-8000-00000000000a';

    private const TEAM_B = '0194f1c2-0000-7000-8000-00000000000b';

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

        config()->set('magic-starter.support.ai.pool_reservation_tokens', 1200);
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
        $first = $this->createConversation($this->createUser(), teamId: self::TEAM_A);
        $second = $this->createConversation($this->createUser(), teamId: self::TEAM_A);
        $outsider = $this->createConversation($this->createUser(), teamId: self::TEAM_B);

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

        $otherInTeam = $this->createConversation($other, self::TEAM_A);
        $memberInTeam = $this->createConversation($member, self::TEAM_A);

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

    public function test_reconcile_with_only_cache_reads_charges_prompt_and_completion_under_default_weights(): void
    {
        $this->setPools(free: 2400);
        $conversation = $this->createConversation($this->createUser());
        $quota = $this->quota();

        $first = $quota->reserve($conversation, 'turn-1', SupportAiPool::FREE);
        $this->assertNotNull($first);

        $quota->reconcile($first, new SupportUsage(
            promptTokens: 60,
            completionTokens: 40,
            cacheReadTokens: 50000,
        ));

        // The pool holds 100, not 50100: turn 2 (1300) fits and turn 3 (2500) does not.
        $this->assertNotNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($conversation, 'turn-3', SupportAiPool::FREE));
    }

    public function test_reconcile_charges_a_cache_write_in_full_under_default_weights(): void
    {
        $this->setPools(free: 2400);
        $conversation = $this->createConversation($this->createUser());
        $quota = $this->quota();

        $first = $quota->reserve($conversation, 'turn-1', SupportAiPool::FREE);
        $this->assertNotNull($first);

        $quota->reconcile($first, new SupportUsage(
            promptTokens: 60,
            completionTokens: 40,
            cacheWriteTokens: 1500,
        ));

        $this->assertSame(1600, $quota->chargedTokens(new SupportUsage(60, 40, 0, 1500)));
        // The pool holds 1600: another 1200 no longer fits.
        $this->assertNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));
    }

    /**
     * A weight so small that rounding would wipe a non-empty bucket still charges one token.
     */
    public function test_a_tiny_positive_weight_never_charges_a_non_empty_bucket_zero(): void
    {
        config()->set('magic-starter.support.ai.cache_weights', [
            'read' => 0.0000001,
            'write' => 1.0,
        ]);

        $this->assertSame(101, $this->quota()->chargedTokens(new SupportUsage(60, 40, 1, 0)));
        $this->assertSame(100, $this->quota()->chargedTokens(new SupportUsage(60, 40, 0, 0)));
    }

    public function test_reconcile_weighs_cache_reads_by_the_configured_read_weight_rounded_up(): void
    {
        config()->set('magic-starter.support.ai.cache_weights', [
            'read' => 0.1,
            'write' => 1.0,
        ]);
        $this->setPools(free: 2600);
        $conversation = $this->createConversation($this->createUser());
        $quota = $this->quota();

        $first = $quota->reserve($conversation, 'turn-1', SupportAiPool::FREE);
        $this->assertNotNull($first);

        // 1005 reads at 0.1 charge 101: the pool holds 60 + 40 + 101 = 201.
        $quota->reconcile($first, new SupportUsage(
            promptTokens: 60,
            completionTokens: 40,
            cacheReadTokens: 1005,
        ));

        $this->assertSame(101, $quota->chargedTokens(new SupportUsage(0, 0, 1005, 0)));
        // 201 + 1200 = 1401 fits under 2600; a third 1200 (2601) is refused only because the reads were
        // charged: at weight 0.0 the pool would hold 100 + 1200 + 1200 = 2500 and grant it.
        $this->assertNotNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($conversation, 'turn-3', SupportAiPool::FREE));
    }

    public function test_a_published_config_without_cache_weights_charges_writes_in_full_and_reads_nothing(): void
    {
        /** @var array<string, mixed> $ai */
        $ai = config('magic-starter.support.ai');
        unset($ai['cache_weights']);
        config()->set('magic-starter.support.ai', $ai);

        $this->assertNull(config('magic-starter.support.ai.cache_weights'));
        $this->assertSame(
            100 + 700,
            $this->quota()->chargedTokens(new SupportUsage(60, 40, 90000, 700)),
        );
    }

    public function test_a_negative_configured_weight_reads_as_zero(): void
    {
        config()->set('magic-starter.support.ai.cache_weights', [
            'read' => -0.5,
            'write' => -2.0,
        ]);

        $this->assertSame(100, $this->quota()->chargedTokens(new SupportUsage(60, 40, 1000, 1000)));
    }

    public function test_float_noise_does_not_overcharge_a_token(): void
    {
        config()->set('magic-starter.support.ai.cache_weights', [
            'read' => 0.0,
            'write' => 1.1,
        ]);

        // 10 * 1.1 is 11.000000000000002 in floating point: it charges 11, not 12.
        $this->assertSame(11, $this->quota()->chargedTokens(new SupportUsage(0, 0, 0, 10)));
    }

    public function test_a_usage_without_cache_tokens_settles_exactly_as_before(): void
    {
        $this->setPools(free: 2400);
        $conversation = $this->createConversation($this->createUser());
        $quota = $this->quota();

        $first = $quota->reserve($conversation, 'turn-1', SupportAiPool::FREE);
        $this->assertNotNull($first);

        $quota->reconcile($first, new SupportUsage(promptTokens: 60, completionTokens: 40));

        $this->assertSame(100, $quota->chargedTokens(new SupportUsage(60, 40)));
        $this->assertNotNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($conversation, 'turn-3', SupportAiPool::FREE));
    }

    public function test_the_pool_is_reserved_at_pool_reservation_tokens_not_the_completion_cap(): void
    {
        config()->set('magic-starter.support.ai.max_tokens_per_turn', 1200);
        config()->set('magic-starter.support.ai.pool_reservation_tokens', 3000);
        $this->setPools(free: 6000);
        $conversation = $this->createConversation($this->createUser());
        $quota = $this->quota();

        $this->assertSame(3000, $quota->reserve($conversation, 'turn-1', SupportAiPool::FREE)?->tokens);
        $this->assertNotNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($conversation, 'turn-3', SupportAiPool::FREE));
    }

    public function test_a_host_config_without_the_new_keys_reserves_6000_and_caps_a_user_at_60000(): void
    {
        config()->set('magic-starter.support.ai', [
            'quotas' => [
                'user_daily' => 20,
                'team_daily' => 40,
                'new_account_daily' => 5,
                'new_account_hours' => 24,
                'pools' => [
                    'free' => 1000000,
                    'paid' => 1000000,
                ],
            ],
        ]);
        $conversation = $this->createConversation($this->createUser());
        $quota = $this->quota();

        for ($turn = 1; $turn <= 10; $turn++) {
            $this->assertSame(
                6000,
                $quota->reserve($conversation, 'turn-' . $turn, SupportAiPool::FREE)?->tokens,
                'turn ' . $turn . ' should be granted',
            );
        }

        $this->assertNull($quota->reserve($conversation, 'turn-11', SupportAiPool::FREE));
    }

    public function test_one_user_cannot_drain_the_shared_pool_past_user_daily_tokens(): void
    {
        config()->set('magic-starter.support.ai.quotas.user_daily_tokens', 2500);
        $quota = $this->quota();
        $heavy = $this->createConversation($this->createUser());
        $other = $this->createConversation($this->createUser());

        $first = $quota->reserve($heavy, 'turn-1', SupportAiPool::FREE);
        $this->assertNotNull($first);
        $this->assertNotNull($quota->reserve($heavy, 'turn-2', SupportAiPool::FREE));
        $this->assertNull($quota->reserve($heavy, 'turn-3', SupportAiPool::FREE));
        $this->assertNotNull($quota->reserve($other, 'turn-1', SupportAiPool::FREE));

        // Settling the first turn at 100 tokens gives 1100 back to the user's own ceiling too: 1300 + 1200 fits.
        $quota->reconcile($first, new SupportUsage(promptTokens: 60, completionTokens: 40));

        $this->assertNotNull($quota->reserve($heavy, 'turn-4', SupportAiPool::FREE));
    }

    public function test_a_refusal_by_the_user_token_ceiling_hands_back_the_user_turn(): void
    {
        config()->set('magic-starter.support.ai.quotas.user_daily_tokens', 1200);
        $this->setQuotas(userDaily: 2);
        $conversation = $this->createConversation($this->createUser());
        $quota = $this->quota();

        $first = $quota->reserve($conversation, 'turn-1', SupportAiPool::FREE);
        $this->assertNotNull($first);
        $this->assertNull($quota->reserve($conversation, 'turn-2', SupportAiPool::FREE));

        $quota->reconcile($first, new SupportUsage(promptTokens: 0, completionTokens: 0));

        $this->assertNotNull($quota->reserve($conversation, 'turn-3', SupportAiPool::FREE));
    }

    public function test_the_reservation_lives_in_its_own_file_for_the_authoritative_classmap(): void
    {
        $file = (new ReflectionClass(Reservation::class))->getFileName();

        $this->assertIsString($file);
        $this->assertSame('Reservation.php', basename($file));
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
