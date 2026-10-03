<?php

namespace FlutterSdk\MagicStarter\Social;

use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use stdClass;
use UnexpectedValueException;

/**
 * A provider's published JWKS, and the signature check of a compact JWT against it.
 *
 * The set is cached in `magic-starter.social.cache_store` for an hour. A token
 * naming a key id the cached set lacks triggers one refetch, since providers
 * rotate keys without notice; at most one per cooldown, so a stream of tokens
 * carrying invented key ids cannot become a stream of requests to the provider.
 * A body without `keys` is refused rather than cached, or an hour of refusals
 * would follow it.
 *
 * Only the signature and php-jwt's time claims are checked here; issuer,
 * audience and every other claim are the caller's to judge.
 */
class JwksKeySet
{
    /**
     * How long a fetched key set is trusted, in seconds.
     */
    protected const TTL = 3600;

    /**
     * Minimum seconds between two unknown-kid refetches.
     */
    protected const REFETCH_COOLDOWN = 60;

    /**
     * @param  string  $url  where the provider publishes its key set
     * @param  string  $cacheKey  the cache key the set lives under; the refetch cooldown is derived from it
     */
    public function __construct(
        protected string $url,
        protected string $cacheKey,
    ) {}

    /**
     * Verify the token's signature and time claims, and return its claims.
     *
     * @throws UnexpectedValueException When the token is malformed, badly signed, outside its time window or
     *                                  names a key the set does not publish.
     * @throws DomainException When the token's header or a key is not usable.
     * @throws InvalidArgumentException When the key set cannot be parsed.
     * @throws KeySetUnavailableException When the provider answers without a key set.
     * @throws ConnectionException When the key set cannot be reached.
     * @throws RequestException When the provider answers the key set request with an error.
     */
    public function decode(string $jwt): stdClass
    {
        $keys = JWK::parseKeySet($this->keySet());
        $kid = $this->keyId($jwt);

        if ($kid !== null && ! isset($keys[$kid]) && $this->mayRefetch()) {
            $keys = JWK::parseKeySet($this->keySet(fresh: true));
        }

        return JWT::decode($jwt, $keys);
    }

    /**
     * The key set, from the cache unless a fresh copy is asked for.
     *
     * @return array<string, mixed>
     */
    protected function keySet(bool $fresh = false): array
    {
        if ($fresh) {
            $this->cache()->forget($this->cacheKey);
        }

        return $this->cache()->remember($this->cacheKey, self::TTL, function (): array {
            $keySet = Http::acceptJson()->get($this->url)->throw()->json();

            if (! is_array($keySet) || ! is_array($keySet['keys'] ?? null)) {
                throw new KeySetUnavailableException("[{$this->url}] answered the key set request without a key set.");
            }

            return $keySet;
        });
    }

    /**
     * Whether an unknown key id may trigger a refetch now; at most one per cooldown.
     */
    protected function mayRefetch(): bool
    {
        return $this->cache()->add($this->cacheKey . '-refetch', true, self::REFETCH_COOLDOWN);
    }

    /**
     * The `kid` header of a compact JWT, read before verification only to pick a key.
     *
     * @throws DomainException When the header is not JSON.
     */
    protected function keyId(string $jwt): ?string
    {
        $header = JWT::jsonDecode(JWT::urlsafeB64Decode(explode('.', $jwt)[0]));
        $kid = $header->kid ?? null;

        return is_string($kid) ? $kid : null;
    }

    protected function cache(): Repository
    {
        return Cache::store(config('magic-starter.social.cache_store'));
    }
}
