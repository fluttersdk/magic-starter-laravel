<?php

namespace FlutterSdk\MagicStarter\Social;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Str;

/**
 * The short-lived, single-use records a backend-hosted social flow is made of.
 *
 * Three records carry a flow from the app to the provider and back: the link
 * ticket an authenticated app takes before a connect, the state the redirect
 * leaves for the callback, and the one-time code the callback hands the app
 * for the exchange. Each is redeemable exactly once, and each consume is
 * atomic across processes: a sentinel is claimed with the store's `add` (SET
 * NX on redis, an insert on the database store) before the record is read, so
 * two concurrent consumers can never both win. `Cache::pull` reads and then
 * forgets, which lets both of them through.
 *
 * Keys are sha256 digests of the token, so the cache never holds a redeemable
 * value and a cache dump replays nothing. The store is
 * `magic-starter.social.cache_store`; it has to be shared by every web node,
 * since the callback can land on a different one than the redirect.
 *
 * Records are plain arrays: a cache that refuses to unserialise objects
 * (Laravel's `serializable_classes`) still round-trips them.
 */
class SocialFlowStore
{
    /**
     * Sign in as the account the identity is linked to, or a new one.
     */
    public const INTENT_SIGNIN = 'signin';

    /**
     * Link the identity to the account a link ticket names.
     */
    public const INTENT_CONNECT = 'connect';

    /**
     * Prove, for a step-up, that the caller still controls an identity linked to their account.
     */
    public const INTENT_CONFIRM = 'confirm';

    /**
     * The platforms a flow can return to, each with a configured target.
     */
    public const PLATFORMS = [
        'native',
        'web',
    ];

    protected const KEY_PREFIX = 'magic-starter:social:flow:';

    public function __construct(
        protected CacheFactory $cache,
        protected StringEncrypter $encrypter,
    ) {}

    /**
     * Mint the ticket an authenticated app redeems by starting a connect flow.
     *
     * @param  string  $challenge  the S256 challenge of the app's verifier; the redirect must carry the same one
     * @param  string  $purpose  the intent the ticket authorises, normally {@see self::INTENT_CONNECT}
     * @return string the opaque ticket, valid for `magic-starter.social.link_ticket_ttl` seconds
     */
    public function mintTicket(Authenticatable $user, string $provider, string $challenge, string $purpose): string
    {
        return $this->put('ticket', [
            'user_id' => (string) $user->getAuthIdentifier(),
            'provider' => $provider,
            'challenge' => $challenge,
            'purpose' => $purpose,
        ], $this->ttl('link_ticket_ttl', 300));
    }

    /**
     * Redeem a link ticket, once; null when it is unknown, expired or already redeemed.
     *
     * @return array{user_id: string, provider: string, challenge: string, purpose: string}|null
     */
    public function pullTicket(string $ticket): ?array
    {
        /** @var array{user_id: string, provider: string, challenge: string, purpose: string}|null $record */
        $record = $this->pull('ticket', $ticket, $this->ttl('link_ticket_ttl', 300));

        return $record;
    }

    /**
     * Redeem a ticket for the flow it was taken for, and name the user it authorises.
     *
     * The ticket is spent whether or not it matches, so a ticket presented with
     * the wrong provider or challenge cannot be retried with the right one.
     *
     * @return string|null the ticket's user id, or null when the ticket does not authorise this flow
     */
    public function redeemTicket(string $ticket, string $provider, string $challenge, string $purpose): ?string
    {
        $record = $this->pullTicket($ticket);

        if ($record === null
            || $record['purpose'] !== $purpose
            || $record['provider'] !== $provider
            || ! hash_equals($record['challenge'], $challenge)
        ) {
            return null;
        }

        return $record['user_id'];
    }

    /**
     * Record what the callback needs to finish a flow, under a fresh state.
     *
     * The state carries the platform in the clear ahead of its random part, so
     * a callback whose record is gone (expired, replayed) can still send the
     * browser back to the app with an error rather than strand it on a JSON
     * page. Trusting it is harmless: it only picks one of the configured targets.
     *
     * @param  array{provider: string, platform: string, challenge: string, intent: string, user_id: string|null, code_verifier: string|null, nonce: string|null}  $flow
     * @return string the state to send to the provider
     */
    public function putState(array $flow): string
    {
        $state = $flow['platform'] . '.' . Str::random(40);

        $this->write('state', $state, $flow, $this->ttl('state_ttl', 600));

        return $state;
    }

    /**
     * Redeem a state, once.
     *
     * @return array{provider: string, platform: string, challenge: string, intent: string, user_id: string|null, code_verifier: string|null, nonce: string|null}|null
     */
    public function pullState(string $state): ?array
    {
        /** @var array{provider: string, platform: string, challenge: string, intent: string, user_id: string|null, code_verifier: string|null, nonce: string|null}|null $flow */
        $flow = $this->pull('state', $state, $this->ttl('state_ttl', 600));

        return $flow;
    }

    /**
     * The platform a state was minted for, read from the state itself.
     */
    public function platformOf(string $state): ?string
    {
        $platform = Str::before($state, '.');

        return in_array($platform, self::PLATFORMS, true) ? $platform : null;
    }

    /**
     * Mint the one-time code the callback hands the app, bound to the app's challenge.
     *
     * The Apple refresh token is the one provider secret a flow carries, and it
     * is encrypted for the seconds it sits in the cache.
     *
     * @param  string  $challenge  the S256 challenge the exchange's verifier must answer
     * @param  string  $intent  one of the `INTENT_*` constants
     * @param  string|null  $userId  the user signing in (signin) or being linked (connect)
     * @param  VerifiedIdentity|null  $identity  the staged identity, for connect and confirm
     * @param  string|null  $refreshToken  the Apple refresh token, for connect
     * @param  string|null  $clientId  the client the credential was issued to, for connect
     */
    public function mintCode(
        string $challenge,
        string $intent,
        ?string $userId = null,
        ?VerifiedIdentity $identity = null,
        ?string $refreshToken = null,
        ?string $clientId = null,
    ): string {
        return $this->put('code', [
            'challenge' => $challenge,
            'intent' => $intent,
            'user_id' => $userId,
            'identity' => $identity === null ? null : $this->identityToArray($identity),
            'refresh_token' => $refreshToken === null ? null : $this->encrypter->encryptString($refreshToken),
            'client_id' => $clientId,
        ], $this->ttl('code_ttl', 60));
    }

    /**
     * Redeem a one-time code with the verifier behind its challenge, once.
     *
     * Null when the code is unknown, expired or spent, or the verifier does not
     * answer its challenge. The code is spent before the verifier is compared,
     * so a wrong verifier burns it: an intercepted code gets one guess, not a
     * brute-force budget.
     *
     * @param  string  $verifier  the app's PKCE verifier
     * @return array{intent: string, user_id: string|null, identity: VerifiedIdentity|null, refresh_token: string|null, client_id: string|null}|null
     */
    public function pullCode(string $code, string $verifier): ?array
    {
        $outcome = $this->pull('code', $code, $this->ttl('code_ttl', 60));

        if ($outcome === null || ! hash_equals((string) $outcome['challenge'], self::challenge($verifier))) {
            return null;
        }

        return [
            'intent' => (string) $outcome['intent'],
            'user_id' => $outcome['user_id'] === null ? null : (string) $outcome['user_id'],
            'identity' => is_array($outcome['identity']) ? $this->identityFromArray($outcome['identity']) : null,
            'refresh_token' => is_string($outcome['refresh_token'])
                ? $this->encrypter->decryptString($outcome['refresh_token'])
                : null,
            'client_id' => is_string($outcome['client_id']) ? $outcome['client_id'] : null,
        ];
    }

    /**
     * Record a payload under a fresh opaque token.
     *
     * @param  string  $kind  the record family, which namespaces the key
     * @param  array<string, mixed>  $payload
     * @param  int  $ttl  seconds
     */
    public function put(string $kind, array $payload, int $ttl): string
    {
        $token = Str::random(64);

        $this->write($kind, $token, $payload, $ttl);

        return $token;
    }

    /**
     * Redeem a token, once, atomically across processes.
     *
     * @param  int  $ttl  seconds; at least the record's own lifetime, so the sentinel outlives it
     * @return array<string, mixed>|null null when unknown, expired or already redeemed
     */
    public function pull(string $kind, string $token, int $ttl): ?array
    {
        $key = $this->key($kind, $token);

        // 1. Claim the redemption first; only one caller's `add` can succeed.
        if (! $this->store()->add($key . ':spent', true, $ttl)) {
            return null;
        }

        // 2. Read and drop the record. Absent means it expired or never existed.
        $payload = $this->store()->get($key);
        $this->store()->forget($key);

        return is_array($payload) ? $payload : null;
    }

    /**
     * The configured target for a platform, with the given query appended, or
     * null when the platform has no target.
     *
     * Targets come only from `magic-starter.social.redirects`; nothing the
     * request carries can choose where a code is sent.
     *
     * @param  array<string, string>  $query
     */
    public function target(string $platform, array $query = []): ?string
    {
        $target = in_array($platform, self::PLATFORMS, true)
            ? config("magic-starter.social.redirects.{$platform}")
            : null;

        if (! is_string($target) || $target === '') {
            return null;
        }

        if ($query === []) {
            return $target;
        }

        $fragment = str_contains($target, '#') ? '#' . Str::after($target, '#') : '';
        $base = Str::before($target, '#');

        return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($query) . $fragment;
    }

    /**
     * The RFC 7636 S256 challenge of a verifier: base64url(sha256(verifier)), unpadded.
     */
    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function write(string $kind, string $token, array $payload, int $ttl): void
    {
        $this->store()->put($this->key($kind, $token), $payload, $ttl);
    }

    protected function key(string $kind, string $token): string
    {
        return self::KEY_PREFIX . $kind . ':' . hash('sha256', $token);
    }

    protected function ttl(string $setting, int $default): int
    {
        return (int) config("magic-starter.social.{$setting}", $default);
    }

    protected function store(): Repository
    {
        return $this->cache->store(config('magic-starter.social.cache_store'));
    }

    /**
     * @return array<string, string|bool|null>
     */
    protected function identityToArray(VerifiedIdentity $identity): array
    {
        return [
            'provider' => $identity->provider,
            'provider_user_id' => $identity->providerUserId,
            'email' => $identity->email,
            'email_verified' => $identity->emailVerified,
            'name' => $identity->name,
            'avatar' => $identity->avatar,
            'tenant_id' => $identity->tenantId,
        ];
    }

    /**
     * Rebuild an identity the callback verified and staged; never fed from request input.
     *
     * @param  array<string, mixed>  $identity
     */
    protected function identityFromArray(array $identity): VerifiedIdentity
    {
        return new VerifiedIdentity(
            provider: (string) $identity['provider'],
            providerUserId: (string) $identity['provider_user_id'],
            email: is_string($identity['email']) ? $identity['email'] : null,
            emailVerified: (bool) $identity['email_verified'],
            name: is_string($identity['name']) ? $identity['name'] : null,
            avatar: is_string($identity['avatar']) ? $identity['avatar'] : null,
            tenantId: is_string($identity['tenant_id']) ? $identity['tenant_id'] : null,
        );
    }
}
