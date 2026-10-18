<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi;

use FlutterSdk\MagicStarter\Enums\SupportAiPool;
use FlutterSdk\MagicStarter\Models\SupportConversation;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The AI desk's spend ledger: who may use it at all, and how much of the day is left.
 *
 * A turn is allowed only when five counters have room, each a dated key kept
 * with the atomic {@see RateLimiter::increment()} (the same shape as a daily
 * budget guard): the opener's turns, their team's turns, a tighter turn count
 * while the account is younger than `new_account_hours` since it verified its
 * email, the opener's own tokens (`user_daily_tokens`, so a few accounts cannot
 * drain a shared pool) and the tokens of the day's pool the host picked for
 * the conversation. Both token counters are charged
 * `pool_reservation_tokens` up front, sized for prompt plus completion, and
 * `image_reservation_tokens` more for each image the turn sends, then
 * {@see self::reconcile()}d to the real tokens afterwards.
 *
 * Every counter decides on its own post-increment value, never on a prior read,
 * so two workers racing the last unit cannot both win; a refusal hands back
 * whatever the earlier counters were charged. Limits come from
 * `support.ai.quotas`; a turn, team or pool limit that is missing reads as
 * zero, which refuses. The two keys added after the first release,
 * `pool_reservation_tokens` and `quotas.user_daily_tokens`, fall back to
 * {@see self::DEFAULT_POOL_RESERVATION_TOKENS} and
 * {@see self::DEFAULT_USER_DAILY_TOKENS}, as does `image_reservation_tokens`
 * to {@see self::DEFAULT_IMAGE_RESERVATION_TOKENS} and `cache_weights.read|write` to
 * {@see self::DEFAULT_CACHE_READ_WEIGHT} and {@see self::DEFAULT_CACHE_WRITE_WEIGHT},
 * because a host's published config replaces the whole `support` block and would
 * otherwise refuse every turn.
 *
 * One turn is charged once however often it is reserved: the first outcome
 * (granted or refused) is recorded under the conversation and turn id and every
 * retry reads it back. The record lives past midnight so a late reconcile still
 * adjusts the day the tokens were reserved on.
 *
 * It calls no model and sends nothing; the responder job owns what a refusal means.
 */
class SupportAiQuota
{
    /**
     * Slightly over a day, so no key expires before the day it counts; the date in the key starts the next day.
     */
    private const DECAY_SECONDS = 90_000;

    private const LOCK_SECONDS = 10;

    /**
     * Tokens a turn reserves when the host's config predates `support.ai.pool_reservation_tokens`.
     */
    public const DEFAULT_POOL_RESERVATION_TOKENS = 6000;

    /**
     * Tokens one image adds to a reservation when the host's config predates `support.ai.image_reservation_tokens`.
     */
    public const DEFAULT_IMAGE_RESERVATION_TOKENS = 1600;

    /**
     * One opener's daily tokens when the host's config predates `support.ai.quotas.user_daily_tokens`.
     */
    public const DEFAULT_USER_DAILY_TOKENS = 60000;

    /**
     * Share of a cache read token charged when the host's config predates `support.ai.cache_weights.read`.
     */
    public const DEFAULT_CACHE_READ_WEIGHT = 0.0;

    /**
     * Share of a cache write token charged when the host's config predates `support.ai.cache_weights.write`.
     */
    public const DEFAULT_CACHE_WRITE_WEIGHT = 1.0;

    public function __construct(
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * Whether this account may use the AI desk at all: a signed-in, non-guest user with a verified email.
     */
    public function eligible(Model $user): bool
    {
        if (method_exists($user, 'isGuest') && $user->isGuest()) {
            return false;
        }

        return $user->getAttribute('email_verified_at') !== null;
    }

    /**
     * Charge one turn against every ceiling, or refuse it.
     *
     * Refuses an opener who is missing or not {@see self::eligible()}, so a
     * caller that forgot to check still spends nothing.
     *
     * @param  string  $turnId  Stable for the turn across retries, e.g. the customer message id.
     * @param  SupportAiPool  $pool  The pool the host resolved for this conversation.
     * @param  int  $images  Images the turn sends to the model; each raises the reservation by
     *                       `support.ai.image_reservation_tokens`.
     * @return Reservation|null Null when the turn is refused; the same reservation on every retry of a granted turn.
     */
    public function reserve(
        SupportConversation $conversation,
        string $turnId,
        SupportAiPool $pool,
        int $images = 0,
    ): ?Reservation {
        $conversationId = (string) $conversation->getKey();
        $recordKey = $this->recordKey($conversationId, $turnId);

        // 1. One decision per turn: a retry reads it back instead of charging again.
        return Cache::lock($recordKey . ':lock', self::LOCK_SECONDS)->block(
            self::LOCK_SECONDS,
            function () use ($conversation, $conversationId, $turnId, $pool, $images, $recordKey): ?Reservation {
                $record = Cache::get($recordKey);

                if (is_array($record)) {
                    return $record['granted'] === true
                        ? new Reservation(
                            conversationId: $conversationId,
                            turnId: $turnId,
                            pool: SupportAiPool::from($record['pool']),
                            tokens: $record['tokens'],
                            day: $record['day'],
                            userId: $record['user'],
                        )
                        : null;
                }

                $reservation = $this->charge($conversation, $conversationId, $turnId, $pool, $images);

                Cache::put($recordKey, [
                    'granted' => $reservation !== null,
                    'pool' => $pool->value,
                    'tokens' => $reservation->tokens ?? 0,
                    'day' => $reservation->day ?? '',
                    'user' => $reservation->userId ?? '',
                    'reconciled' => false,
                ], self::DECAY_SECONDS);

                return $reservation;
            },
        );
    }

    /**
     * Settle a turn's token charges, the pool's and the opener's, to the tokens it really used.
     *
     * Adjusts the day the tokens were reserved on, once: a second call for the
     * same reservation changes nothing. A failed turn reports zero usage to
     * give the whole reservation back.
     */
    public function reconcile(Reservation $reservation, SupportUsage $usage): void
    {
        $recordKey = $this->recordKey($reservation->conversationId, $reservation->turnId);

        Cache::lock($recordKey . ':lock', self::LOCK_SECONDS)->block(
            self::LOCK_SECONDS,
            function () use ($reservation, $usage, $recordKey): void {
                $record = Cache::get($recordKey);

                if (! is_array($record) || $record['granted'] !== true || $record['reconciled'] === true) {
                    return;
                }

                $delta = $this->chargedTokens($usage) - $reservation->tokens;

                if ($delta !== 0) {
                    $this->limiter->increment(
                        $this->poolKey($reservation->pool, $reservation->day),
                        self::DECAY_SECONDS,
                        $delta,
                    );
                    $this->limiter->increment(
                        $this->key('tokens', $reservation->userId, $reservation->day),
                        self::DECAY_SECONDS,
                        $delta,
                    );
                }

                $record['reconciled'] = true;
                Cache::put($recordKey, $record, self::DECAY_SECONDS);
            },
        );
    }

    /**
     * The tokens a turn costs the quota: uncached input and completion in full, the cache buckets by weight.
     *
     * A cached prefix (the knowledge base and tools) is re-read on every turn and costs the provider a
     * fraction of fresh input, so charging it at face value would drain the pool on the turns that cost
     * least. The default bills a cache write as processed input and a cache read as free; a host tunes
     * both under `support.ai.cache_weights`. A bucket with a weight above zero is never charged zero.
     */
    public function chargedTokens(SupportUsage $usage): int
    {
        return $usage->promptTokens
            + $usage->completionTokens
            + $this->weighted($usage->cacheWriteTokens, $this->weight('write', self::DEFAULT_CACHE_WRITE_WEIGHT))
            + $this->weighted($usage->cacheReadTokens, $this->weight('read', self::DEFAULT_CACHE_READ_WEIGHT));
    }

    /**
     * One bucket's configured weight; a negative one reads as zero.
     */
    private function weight(string $bucket, float $default): float
    {
        return max(0.0, (float) config('magic-starter.support.ai.cache_weights.' . $bucket, $default));
    }

    /**
     * Round up so a weighted bucket is never free; the inner round strips float noise (10 * 1.1 is
     * 11.000000000000002) that would otherwise charge one token too many, and the floor of one keeps a
     * tiny weight from rounding a non-empty bucket away.
     */
    private function weighted(int $tokens, float $weight): int
    {
        if ($tokens === 0 || $weight === 0.0) {
            return 0;
        }

        return max(1, (int) ceil(round($tokens * $weight, 6)));
    }

    /**
     * Increment every counter; on the first one over its limit, hand back the ones already charged.
     */
    private function charge(
        SupportConversation $conversation,
        string $conversationId,
        string $turnId,
        SupportAiPool $pool,
        int $images,
    ): ?Reservation {
        $user = $conversation->user;

        if ($user === null || ! $this->eligible($user)) {
            return null;
        }

        $day = $this->today();
        $userId = (string) $user->getKey();
        $tokens = (int) config(
            'magic-starter.support.ai.pool_reservation_tokens',
            self::DEFAULT_POOL_RESERVATION_TOKENS,
        ) + $images * (int) config(
            'magic-starter.support.ai.image_reservation_tokens',
            self::DEFAULT_IMAGE_RESERVATION_TOKENS,
        );

        // 1. The ceilings in the order they are cheapest to explain to a customer.
        $ceilings = [
            [
                $this->key('user', $userId, $day),
                1,
                $this->limit('user_daily'),
            ],
        ];

        if ($conversation->team_id !== null) {
            $ceilings[] = [
                $this->key('team', $conversation->team_id, $day),
                1,
                $this->limit('team_daily'),
            ];
        }

        if ($this->isNewAccount($user)) {
            $ceilings[] = [
                $this->key('new', $userId, $day),
                1,
                $this->limit('new_account_daily'),
            ];
        }

        $ceilings[] = [
            $this->key('tokens', $userId, $day),
            $tokens,
            $this->limit('user_daily_tokens', self::DEFAULT_USER_DAILY_TOKENS),
        ];
        $ceilings[] = [
            $this->poolKey($pool, $day),
            $tokens,
            $this->limit('pools.' . $pool->value),
        ];

        // 2. Each counter decides on its own post-increment value.
        $charged = [];

        foreach ($ceilings as [$key, $amount, $limit]) {
            $count = $this->limiter->increment($key, self::DECAY_SECONDS, $amount);
            $charged[] = [
                $key,
                $amount,
            ];

            if ($count > $limit) {
                // 3. A refused turn costs nothing anywhere.
                foreach ($charged as [$chargedKey, $chargedAmount]) {
                    $this->limiter->decrement($chargedKey, self::DECAY_SECONDS, $chargedAmount);
                }

                return null;
            }
        }

        return new Reservation(
            conversationId: $conversationId,
            turnId: $turnId,
            pool: $pool,
            tokens: $tokens,
            day: $day,
            userId: $userId,
        );
    }

    /**
     * Whether the account verified its email recently enough to be held to the tighter turn cap.
     */
    private function isNewAccount(Model $user): bool
    {
        $verifiedAt = $user->getAttribute('email_verified_at');
        $hours = (int) config('magic-starter.support.ai.quotas.new_account_hours', 0);

        return $verifiedAt !== null && Carbon::parse($verifiedAt)->gt(now()->subHours($hours));
    }

    /**
     * @param  int  $default  What a host config without this key reads as; zero refuses.
     */
    private function limit(string $name, int $default = 0): int
    {
        return (int) config('magic-starter.support.ai.quotas.' . $name, $default);
    }

    private function today(): string
    {
        return now()->format('Y-m-d');
    }

    private function key(string $scope, mixed $id, string $day): string
    {
        return sprintf('magic-starter:support-ai:%s:%s:%s', $scope, $id, $day);
    }

    private function poolKey(SupportAiPool $pool, string $day): string
    {
        return $this->key('pool', $pool->value, $day);
    }

    private function recordKey(string $conversationId, string $turnId): string
    {
        return sprintf('magic-starter:support-ai:turn:%s:%s', $conversationId, $turnId);
    }
}
