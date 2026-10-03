<?php

namespace FlutterSdk\MagicStarter\Social;

use DomainException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

/**
 * Verifies the JWS Apple posts to the server-to-server notification endpoint
 * and reads the one event it carries.
 *
 * The endpoint carries no other credential, so nothing in the payload is read
 * before the signature, the issuer and an audience this deployment configured
 * have all passed. A notification is a different token from an identity token
 * (no nonce, an `events` claim), so it is verified here with php-jwt rather
 * than through the Apple Socialite provider, against the same key set.
 *
 * Replay protection is split from verification on purpose: the caller claims
 * the `jti` only once it is about to act, and releases it when acting fails, so
 * Apple's retry of a failed delivery is processed instead of being a no-op.
 */
class AppleNotificationVerifier
{
    /**
     * Apple's published Sign in with Apple signing keys.
     */
    public const APPLE_JWKS_URL = 'https://appleid.apple.com/auth/keys';

    protected const APPLE_ISSUER = 'https://appleid.apple.com';

    /**
     * How long a seen `jti` is remembered, in seconds. A notification carries no
     * `exp`, so the window is sized to outlast Apple's delivery retries.
     */
    protected const JTI_TTL = 7 * 86400;

    protected const CACHE_PREFIX = 'magic-starter:social:';

    /**
     * Verify a notification payload and read its event.
     *
     * The event fields are null when a verified payload does not carry them;
     * a verified payload is answered, never refused, so the caller decides
     * what an incomplete event means.
     *
     * @return array{jti: string, type: string|null, subject: string|null, email: string|null}
     *
     * @throws InvalidIdentityException When the payload fails any check.
     * @throws ConnectionException When Apple's key set cannot be reached.
     * @throws RequestException When Apple answers the key set request with an error.
     * @throws RuntimeException When Apple answers the key set request without a key set.
     */
    public function verify(string $jws): array
    {
        // 1. No configured audience means no client of ours can be the subject of a notification.
        $audiences = $this->audiences();

        if ($audiences === []) {
            throw new InvalidIdentityException('No Apple audience is configured.');
        }

        // 2. Signature and any time claim against Apple's published keys.
        $claims = $this->decode($jws);

        // 3. The claims php-jwt does not check: who issued it, for whom, and its replay id and age.
        if (($claims->iss ?? null) !== self::APPLE_ISSUER) {
            throw new InvalidIdentityException('The Apple notification has a foreign issuer.');
        }

        if (! is_string($claims->aud ?? null) || ! in_array($claims->aud, $audiences, true)) {
            throw new InvalidIdentityException('The Apple notification was issued for a foreign audience.');
        }

        $jti = $claims->jti ?? null;

        if (! is_string($jti) || $jti === '') {
            throw new InvalidIdentityException('The Apple notification carries no jti.');
        }

        // 4. A jti is remembered for JTI_TTL, so anything older could be a replay nothing would catch.
        $issuedAt = $claims->iat ?? null;

        if (! is_numeric($issuedAt) || $issuedAt < time() - self::JTI_TTL) {
            throw new InvalidIdentityException('The Apple notification has no issue time inside its replay window.');
        }

        // 5. Only now is the body trusted enough to read.
        $event = $this->event($claims->events ?? null);

        return [
            'jti' => $jti,
            'type' => Claims::string($event['type'] ?? null),
            'subject' => Claims::string($event['sub'] ?? null),
            'email' => Claims::string($event['email'] ?? null),
        ];
    }

    /**
     * Record a verified notification id; false when it was already recorded.
     */
    public function claim(string $jti): bool
    {
        return $this->cache()->add($this->jtiKey($jti), true, self::JTI_TTL);
    }

    /**
     * Forget a claimed id, so Apple's retry of a delivery that failed is processed.
     */
    public function release(string $jti): void
    {
        $this->cache()->forget($this->jtiKey($jti));
    }

    /**
     * Decode the payload against Apple's published keys.
     *
     * @throws InvalidIdentityException When the payload is malformed, badly signed or outside its time window.
     */
    protected function decode(string $jws): stdClass
    {
        try {
            return (new JwksKeySet(self::APPLE_JWKS_URL, self::CACHE_PREFIX . 'apple-jwks'))->decode($jws);
        } catch (UnexpectedValueException|DomainException|InvalidArgumentException $failure) {
            throw new InvalidIdentityException('The Apple notification failed verification.', $failure);
        }
    }

    /**
     * The `events` claim as an array; Apple has sent it both as an object and as a JSON string.
     *
     * @return array<array-key, mixed> empty when the claim is neither
     */
    protected function event(mixed $events): array
    {
        if (is_string($events)) {
            $decoded = json_decode($events, true);

            return is_array($decoded) ? $decoded : [];
        }

        if ($events instanceof stdClass) {
            return (array) $events;
        }

        return [];
    }

    protected function jtiKey(string $jti): string
    {
        return self::CACHE_PREFIX . 'apple-notification:' . hash('sha256', $jti);
    }

    /**
     * The client ids this deployment accepts notifications for.
     *
     * @return list<string>
     */
    protected function audiences(): array
    {
        return array_values(array_filter(
            (array) config('magic-starter.social.audiences.apple', []),
            fn (mixed $audience): bool => is_string($audience) && $audience !== '',
        ));
    }

    protected function cache(): Repository
    {
        return Cache::store(config('magic-starter.social.cache_store'));
    }
}
