<?php

namespace FlutterSdk\MagicStarter\Handoff;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * The short-lived, single-use codes a sign-in handoff is carried by.
 *
 * A signed-in session mints a code for its own user; another origin redeems it
 * once for a sign-in. The redeem is atomic across processes: a sentinel is
 * claimed with the store's `add` (SET NX on redis, an insert on the database
 * store) before the record is read, so two concurrent consumers can never both
 * win. `Cache::pull` reads and then forgets, which lets both of them through.
 *
 * Keys are sha256 digests of the code, so the cache never holds a redeemable
 * code and a cache dump replays nothing. The record does hold the user id and
 * the caller's `next` path for the code's lifetime. The store is
 * `magic-starter.handoff.cache_store`; it has to be shared by every web node,
 * since the exchange can land on a different one than the mint. Records are
 * plain arrays, so a cache that refuses to unserialise objects still reads them.
 */
class HandoffStore
{
    protected const KEY_PREFIX = 'magic-starter:handoff:';

    public function __construct(
        protected CacheFactory $cache,
    ) {}

    /**
     * Mint a code that signs in the given user, once.
     *
     * @param  string|null  $next  a same-app path, already validated by {@see \FlutterSdk\MagicStarter\Rules\SameAppPath}
     * @return string the opaque 64 character code, valid for {@see self::ttl()} seconds
     */
    public function mint(Authenticatable $user, ?string $next): string
    {
        $code = Str::random(64);

        $this->store()->put($this->key($code), [
            'user_id' => (string) $user->getAuthIdentifier(),
            'next' => $next,
        ], $this->ttl());

        return $code;
    }

    /**
     * Redeem a code, once, atomically across processes.
     *
     * The code is spent before its record is read, so a caller that is refused
     * further on (the user is gone) cannot present it again.
     *
     * @return array{user_id: string, next: string|null}|null null when unknown, expired or already redeemed
     */
    public function pull(string $code): ?array
    {
        $key = $this->key($code);

        // 1. Claim the redemption first; only one caller's `add` can succeed.
        if (! $this->store()->add($key . ':spent', true, $this->ttl())) {
            return null;
        }

        // 2. Read and drop the record. Absent means it expired or never existed.
        $record = $this->store()->get($key);
        $this->store()->forget($key);

        if (! is_array($record) || ! is_string($record['user_id'] ?? null)) {
            return null;
        }

        return [
            'user_id' => $record['user_id'],
            'next' => is_string($record['next'] ?? null) ? $record['next'] : null,
        ];
    }

    /**
     * How long a code lives, in seconds.
     */
    public function ttl(): int
    {
        return (int) config('magic-starter.handoff.code_ttl');
    }

    protected function key(string $code): string
    {
        return self::KEY_PREFIX . hash('sha256', $code);
    }

    protected function store(): Repository
    {
        return $this->cache->store(config('magic-starter.handoff.cache_store'));
    }
}
