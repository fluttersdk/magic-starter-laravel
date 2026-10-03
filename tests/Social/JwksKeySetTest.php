<?php

namespace FlutterSdk\MagicStarter\Tests\Social;

use Firebase\JWT\JWT;
use FlutterSdk\MagicStarter\Social\JwksKeySet;
use FlutterSdk\MagicStarter\Social\KeySetUnavailableException;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use UnexpectedValueException;

class JwksKeySetTest extends TestCase
{
    private const URL = 'https://keys.example.test/jwks';

    private const CACHE_KEY = 'magic-starter:social:test-jwks';

    private OpenSSLAsymmetricKey $signingKey;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->signingKey = $this->rsaKey();

        config([
            'cache.stores.social' => [
                'driver' => 'array',
            ],
            'magic-starter.social.cache_store' => 'social',
        ]);
    }

    public function test_a_token_signed_by_a_published_key_is_decoded_and_the_set_is_cached(): void
    {
        Http::fake([
            self::URL => Http::response($this->jwks('key-1', $this->signingKey)),
        ]);

        $first = $this->keySet()->decode($this->token(['sub' => 'first'], $this->signingKey, 'key-1'));
        $second = $this->keySet()->decode($this->token(['sub' => 'second'], $this->signingKey, 'key-1'));

        $this->assertSame('first', $first->sub);
        $this->assertSame('second', $second->sub);
        Http::assertSentCount(1);
    }

    public function test_the_set_lives_in_the_configured_social_cache_store(): void
    {
        Http::fake([
            self::URL => Http::response($this->jwks('key-1', $this->signingKey)),
        ]);

        $this->keySet()->decode($this->token([], $this->signingKey, 'key-1'));

        $this->assertTrue(Cache::store('social')->has(self::CACHE_KEY));
        $this->assertFalse(Cache::store()->has(self::CACHE_KEY));
    }

    public function test_an_unknown_key_id_refetches_once_and_finds_a_rotated_key(): void
    {
        $rotated = $this->rsaKey();

        Http::fake([
            self::URL => Http::sequence()
                ->push($this->jwks('old-key', $this->signingKey))
                ->push($this->jwks('new-key', $rotated)),
        ]);

        $this->keySet()->decode($this->token(['sub' => 'warm'], $this->signingKey, 'old-key'));
        $claims = $this->keySet()->decode($this->token(['sub' => 'rotated'], $rotated, 'new-key'));

        $this->assertSame('rotated', $claims->sub);
        Http::assertSentCount(2);
    }

    public function test_unknown_key_ids_refetch_at_most_once_per_cooldown(): void
    {
        Http::fake([
            self::URL => Http::response($this->jwks('key-1', $this->signingKey)),
        ]);

        foreach (['forged-1', 'forged-2', 'forged-3'] as $kid) {
            try {
                $this->keySet()->decode($this->token([], $this->signingKey, $kid));
                $this->fail('A token under an unpublished key id was decoded.');
            } catch (UnexpectedValueException) {
                // Refused, as it must be.
            }
        }

        // The first fetch plus ONE refetch, however many invented key ids follow.
        Http::assertSentCount(2);
    }

    public function test_a_body_without_keys_is_refused_and_not_cached(): void
    {
        Http::fake([
            self::URL => Http::sequence()
                ->push(['error' => 'maintenance'])
                ->push($this->jwks('key-1', $this->signingKey)),
        ]);

        try {
            $this->keySet()->decode($this->token([], $this->signingKey, 'key-1'));
            $this->fail('A key set body without keys was accepted.');
        } catch (KeySetUnavailableException) {
            $this->assertFalse(Cache::store('social')->has(self::CACHE_KEY));
        }

        $this->assertSame('subject', $this->keySet()->decode($this->token([], $this->signingKey, 'key-1'))->sub);
        Http::assertSentCount(2);
    }

    private function keySet(): JwksKeySet
    {
        return new JwksKeySet(self::URL, self::CACHE_KEY);
    }

    private function rsaKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key);

        return $key;
    }

    /**
     * @return array{keys: list<array<string, string>>}
     */
    private function jwks(string $kid, OpenSSLAsymmetricKey $key): array
    {
        $details = openssl_pkey_get_details($key);

        $this->assertIsArray($details);

        return [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'kid' => $kid,
                    'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
                    'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function token(array $claims, OpenSSLAsymmetricKey $key, string $kid): string
    {
        return JWT::encode([
            'sub' => 'subject',
            'iat' => time() - 10,
            'exp' => time() + 600,
            ...$claims,
        ], $key, 'RS256', $kid);
    }
}
