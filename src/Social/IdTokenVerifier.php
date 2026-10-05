<?php

namespace FlutterSdk\MagicStarter\Social;

use DomainException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
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
     * @throws RuntimeException When Google answers the key set request without a key set.
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
            email: Claims::string($claims->email ?? null),
            emailVerified: $verified === true || $verified === 'true',
            name: Claims::string($claims->name ?? null),
            avatar: Claims::string($claims->picture ?? null),
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

        // 2. Signature, issuer, audience and nonce through the Apple provider; verifying signs nothing.
        try {
            $user = $this->apple
                ->verifier($bundleId)
                ->userByIdentityToken($jwt, hash('sha256', $rawNonce));
        } catch (InvalidArgumentException|UnexpectedValueException|DomainException|LcobucciException $failure) {
            throw new InvalidIdentityException('The Apple token failed verification.', $failure);
        }

        // 3. The provider's loose time check passes a token with no `exp`; require one.
        $claims = $user->getRaw();
        $expiresAt = $this->expiry($claims['exp'] ?? null);

        // 4. Single use per nonce, for as long as a token carrying it could be accepted.
        $this->claimOnce('apple-nonce:' . hash('sha256', $rawNonce), $expiresAt, 'The Apple nonce was already used.');

        $email = Claims::string($user->getEmail());
        $verified = $claims['email_verified'] ?? null;

        return new VerifiedIdentity(
            provider: 'apple',
            providerUserId: $this->subject($user->getId()),
            email: $email,
            // The same rule as the web flow in ProviderIdentity: Apple's own claim, never the bare address.
            emailVerified: $email !== null && ($verified === true || $verified === 'true'),
            name: Claims::string($user->getName()),
        );
    }

    /**
     * Decode a Google token against Google's published keys.
     *
     * @throws InvalidIdentityException When the token is malformed, badly signed or outside its time window.
     */
    protected function decodeGoogle(string $jwt): stdClass
    {
        try {
            return (new JwksKeySet(self::GOOGLE_JWKS_URL, self::CACHE_PREFIX . 'google-jwks'))->decode($jwt);
        } catch (UnexpectedValueException|DomainException|InvalidArgumentException $failure) {
            throw new InvalidIdentityException('The Google token failed verification.', $failure);
        }
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
