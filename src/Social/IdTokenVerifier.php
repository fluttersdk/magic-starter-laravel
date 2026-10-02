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
use Lcobucci\JWT\Exception as LcobucciException;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

/**
 * Turns a Google or Apple ID token from a native or web client into a
 * VerifiedIdentity, or refuses it.
 *
 * Acceptance is all-or-nothing: signature against the provider's published
 * keys, issuer, an audience this deployment configured, a present and future
 * expiry, and a single use. Any failed check throws InvalidIdentityException.
 * A provider key endpoint that cannot be reached is not a verdict on the
 * token, so that failure propagates as the HTTP error it is.
 */
class IdTokenVerifier
{
    /**
     * Google's published OAuth 2.0 signing keys.
     */
    public const GOOGLE_JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    /**
     * Google documents both forms as valid `iss` values.
     */
    protected const GOOGLE_ISSUERS = [
        'https://accounts.google.com',
        'accounts.google.com',
    ];

    /**
     * How long Google's key set is trusted before it is fetched again, in seconds.
     */
    protected const GOOGLE_JWKS_TTL = 3600;

    /**
     * Minimum seconds between two unknown-kid refetches, so a stream of tokens
     * carrying invented key ids cannot turn into a stream of requests to Google.
     */
    protected const GOOGLE_REFETCH_COOLDOWN = 60;

    protected const CACHE_PREFIX = 'magic-starter:social:';

    public function __construct(
        protected AppleProviderFactory $apple,
    ) {}

    /**
     * Verify a Google ID token.
     *
     * @throws InvalidIdentityException When the token fails any check or was already used.
     * @throws ConnectionException When Google's key set cannot be reached.
     * @throws RequestException When Google answers the key set request with an error.
     */
    public function google(string $jwt): VerifiedIdentity
    {
        // 1. No configured audience means no client of ours can hold a valid token.
        $audiences = $this->audiences('google');

        if ($audiences === []) {
            throw new InvalidIdentityException('No Google audience is configured.');
        }

        // 2. Signature and time window against Google's published keys.
        $claims = $this->decodeGoogle($jwt);

        // 3. The claims php-jwt does not check: who issued it, for whom, and that it expires at all.
        if (! in_array($claims->iss ?? null, self::GOOGLE_ISSUERS, true)) {
            throw new InvalidIdentityException('The Google token has a foreign issuer.');
        }

        if (! is_string($claims->aud ?? null) || ! in_array($claims->aud, $audiences, true)) {
            throw new InvalidIdentityException('The Google token was issued for a foreign audience.');
        }

        $expiresAt = $this->expiry($claims->exp ?? null);
        $subject = $this->subject($claims->sub ?? null);

        // 4. Single use: a token seen before is a replay, however valid it still is.
        $this->claimOnce('google-token:' . hash('sha256', $jwt), $expiresAt, 'The Google token was already used.');

        $verified = $claims->email_verified ?? false;

        return new VerifiedIdentity(
            provider: 'google',
            providerUserId: $subject,
            email: $this->optionalString($claims->email ?? null),
            emailVerified: $verified === true || $verified === 'true',
            name: $this->optionalString($claims->name ?? null),
            avatar: $this->optionalString($claims->picture ?? null),
        );
    }

    /**
     * Verify an Apple identity token against the nonce the client generated.
     *
     * The client sends `sha256($rawNonce)` to Apple and the raw nonce here, so
     * only the party that generated the nonce can present the token.
     *
     * @param  string  $rawNonce  the unhashed nonce; required and accepted once
     *
     * @throws InvalidIdentityException When the token fails any check or the nonce was already used.
     * @throws RuntimeException When Sign in with Apple is not configured.
     */
    public function apple(string $jwt, string $rawNonce): VerifiedIdentity
    {
        // 1. Without a nonce the token could be replayed by anyone holding it.
        if ($rawNonce === '') {
            throw new InvalidIdentityException('The Apple sign-in carried no nonce.');
        }

        $bundleId = config('magic-starter.social.apple.bundle_id');

        if (! is_string($bundleId) || $bundleId === '') {
            throw new InvalidIdentityException('No Apple bundle id is configured.');
        }

        // 2. Signature, issuer, audience and nonce through the Apple provider.
        try {
            $user = $this->apple
                ->make($bundleId)
                ->userByIdentityToken($jwt, hash('sha256', $rawNonce));
        } catch (InvalidArgumentException|UnexpectedValueException|DomainException|LcobucciException $failure) {
            throw new InvalidIdentityException('The Apple token failed verification.', $failure);
        }

        // 3. The provider's loose time check passes a token with no `exp`; require one.
        $claims = $user->getRaw();
        $expiresAt = $this->expiry($claims['exp'] ?? null);

        // 4. Single use per nonce, for as long as a token carrying it could be accepted.
        $this->claimOnce('apple-nonce:' . hash('sha256', $rawNonce), $expiresAt, 'The Apple nonce was already used.');

        $email = $this->optionalString($user->getEmail());

        return new VerifiedIdentity(
            provider: 'apple',
            providerUserId: $this->subject($user->getId()),
            email: $email,
            // Apple only releases addresses it has verified, private relay ones included.
            emailVerified: $email !== null,
            name: $this->optionalString($user->getName()),
        );
    }

    /**
     * Decode a Google token, refetching the key set once when it names a key id we have not seen.
     *
     * @throws InvalidIdentityException When the token is malformed, badly signed or outside its time window.
     */
    protected function decodeGoogle(string $jwt): stdClass
    {
        try {
            $keys = JWK::parseKeySet($this->googleKeySet());
            $kid = $this->keyId($jwt);

            if ($kid !== null && ! isset($keys[$kid]) && $this->mayRefetchGoogleKeys()) {
                $keys = JWK::parseKeySet($this->googleKeySet(fresh: true));
            }

            return JWT::decode($jwt, $keys);
        } catch (UnexpectedValueException|DomainException|InvalidArgumentException $failure) {
            throw new InvalidIdentityException('The Google token failed verification.', $failure);
        }
    }

    /**
     * Google's key set, from the cache unless a fresh copy is asked for.
     *
     * @return array<string, mixed>
     */
    protected function googleKeySet(bool $fresh = false): array
    {
        $key = self::CACHE_PREFIX . 'google-jwks';

        if ($fresh) {
            $this->cache()->forget($key);
        }

        return $this->cache()->remember($key, self::GOOGLE_JWKS_TTL, function (): array {
            $keySet = Http::acceptJson()->get(self::GOOGLE_JWKS_URL)->throw()->json();

            // A body without keys is not cached: an hour of refusals would follow it.
            if (! is_array($keySet) || ! is_array($keySet['keys'] ?? null)) {
                throw new RuntimeException('Google answered the key set request without a key set.');
            }

            return $keySet;
        });
    }

    /**
     * Whether an unknown key id may trigger a refetch now; at most one per cooldown.
     */
    protected function mayRefetchGoogleKeys(): bool
    {
        return $this->cache()->add(self::CACHE_PREFIX . 'google-jwks-refetch', true, self::GOOGLE_REFETCH_COOLDOWN);
    }

    /**
     * The `kid` header of a compact JWT, read before verification only to pick a key.
     */
    protected function keyId(string $jwt): ?string
    {
        $header = JWT::jsonDecode(JWT::urlsafeB64Decode(explode('.', $jwt)[0]));
        $kid = $header->kid ?? null;

        return is_string($kid) ? $kid : null;
    }

    /**
     * Record a key that may be seen once, until the token it guards expires.
     *
     * @throws InvalidIdentityException When the key was already recorded.
     */
    protected function claimOnce(string $key, int $expiresAt, string $reason): void
    {
        if (! $this->cache()->add(self::CACHE_PREFIX . $key, true, $expiresAt - time())) {
            throw new InvalidIdentityException($reason);
        }
    }

    /**
     * @throws InvalidIdentityException When the token carries no numeric expiry.
     */
    protected function expiry(mixed $exp): int
    {
        if (! is_numeric($exp)) {
            throw new InvalidIdentityException('The token carries no expiry.');
        }

        return (int) ceil((float) $exp);
    }

    /**
     * @throws InvalidIdentityException When the token names no subject.
     */
    protected function subject(mixed $sub): string
    {
        if (! is_string($sub) || $sub === '') {
            throw new InvalidIdentityException('The token names no subject.');
        }

        return $sub;
    }

    protected function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The client ids this deployment accepts tokens for.
     *
     * @return list<string>
     */
    protected function audiences(string $provider): array
    {
        return array_values(array_filter(
            (array) config("magic-starter.social.audiences.{$provider}", []),
            fn (mixed $audience): bool => is_string($audience) && $audience !== '',
        ));
    }

    protected function cache(): Repository
    {
        return Cache::store(config('magic-starter.social.cache_store'));
    }
}
