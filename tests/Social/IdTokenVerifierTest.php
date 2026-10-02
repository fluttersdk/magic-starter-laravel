<?php

namespace FlutterSdk\MagicStarter\Tests\Social;

use Firebase\JWT\JWT;
use FlutterSdk\MagicStarter\Social\AppleProviderFactory;
use FlutterSdk\MagicStarter\Social\IdTokenVerifier;
use FlutterSdk\MagicStarter\Social\InvalidIdentityException;
use FlutterSdk\MagicStarter\Social\VerifiedIdentity;
use FlutterSdk\MagicStarter\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;

class IdTokenVerifierTest extends TestCase
{
    private const GOOGLE_CERTS = 'https://www.googleapis.com/oauth2/v3/certs';

    private const GOOGLE_WEB_CLIENT = 'web-client.apps.googleusercontent.com';

    private const GOOGLE_IOS_CLIENT = 'ios-client.apps.googleusercontent.com';

    private const APPLE_BUNDLE_ID = 'com.example.app';

    private const APPLE_SERVICES_ID = 'com.example.web';

    private OpenSSLAsymmetricKey $signingKey;

    /**
     * @var list<array<string, mixed>>
     */
    private array $appleRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->signingKey = $this->rsaKey();

        $ecKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        openssl_pkey_export($ecKey, $ecPem);

        config([
            'magic-starter.social.audiences.google' => [
                self::GOOGLE_WEB_CLIENT,
                self::GOOGLE_IOS_CLIENT,
            ],
            'magic-starter.social.audiences.apple' => [
                self::APPLE_BUNDLE_ID,
                self::APPLE_SERVICES_ID,
            ],
            'magic-starter.social.apple' => [
                'team_id' => 'TEAM123456',
                'key_id' => 'KEY1234567',
                'private_key' => $ecPem,
                'bundle_id' => self::APPLE_BUNDLE_ID,
                'services_id' => self::APPLE_SERVICES_ID,
            ],
            'magic-starter.social.cache_store' => null,
        ]);
    }

    // ---------------------------------------------------------------------
    // Google
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function googleAudiencesAndIssuers(): array
    {
        return [
            'web client, https issuer' => [self::GOOGLE_WEB_CLIENT, 'https://accounts.google.com'],
            'web client, bare issuer' => [self::GOOGLE_WEB_CLIENT, 'accounts.google.com'],
            'ios client, https issuer' => [self::GOOGLE_IOS_CLIENT, 'https://accounts.google.com'],
            'ios client, bare issuer' => [self::GOOGLE_IOS_CLIENT, 'accounts.google.com'],
        ];
    }

    #[DataProvider('googleAudiencesAndIssuers')]
    public function test_google_token_is_accepted_for_each_configured_audience_and_issuer(
        string $audience,
        string $issuer,
    ): void {
        $this->fakeGoogleKeys($this->jwks('google-key', $this->signingKey));

        $identity = $this->verifier()->google($this->googleToken(['aud' => $audience, 'iss' => $issuer]));

        $this->assertInstanceOf(VerifiedIdentity::class, $identity);
        $this->assertSame('google', $identity->provider);
        $this->assertSame('google-sub-1', $identity->providerUserId);
    }

    public function test_google_identity_carries_the_claims_with_a_lower_cased_email(): void
    {
        $this->fakeGoogleKeys($this->jwks('google-key', $this->signingKey));

        $identity = $this->verifier()->google($this->googleToken([
            'email' => 'Jane.Doe@Example.COM',
            'email_verified' => true,
            'name' => 'Jane Doe',
            'picture' => 'https://lh3.googleusercontent.com/a/photo',
        ]));

        $this->assertSame('jane.doe@example.com', $identity->email);
        $this->assertTrue($identity->emailVerified);
        $this->assertSame('Jane Doe', $identity->name);
        $this->assertSame('https://lh3.googleusercontent.com/a/photo', $identity->avatar);
        $this->assertNull($identity->tenantId);
    }

    public function test_google_unverified_email_is_reported_as_unverified(): void
    {
        $this->fakeGoogleKeys($this->jwks('google-key', $this->signingKey));

        $identity = $this->verifier()->google($this->googleToken(['email_verified' => false]));

        $this->assertFalse($identity->emailVerified);
    }

    public function test_google_token_for_a_foreign_audience_is_refused(): void
    {
        $this->fakeGoogleKeys($this->jwks('google-key', $this->signingKey));

        $this->expectException(InvalidIdentityException::class);

        $this->verifier()->google($this->googleToken(['aud' => 'someone-else.apps.googleusercontent.com']));
    }

    public function test_google_token_from_a_foreign_issuer_is_refused(): void
    {
        $this->fakeGoogleKeys($this->jwks('google-key', $this->signingKey));

        $this->expectException(InvalidIdentityException::class);

        $this->verifier()->google($this->googleToken(['iss' => 'https://evil.example.com']));
    }

    public function test_expired_google_token_is_refused(): void
    {
        $this->fakeGoogleKeys($this->jwks('google-key', $this->signingKey));

        $this->expectException(InvalidIdentityException::class);

        $this->verifier()->google($this->googleToken([
            'iat' => time() - 7200,
            'exp' => time() - 3600,
        ]));
    }

    public function test_google_token_without_an_expiry_is_refused(): void
    {
        $this->fakeGoogleKeys($this->jwks('google-key', $this->signingKey));

        $this->expectException(InvalidIdentityException::class);

        $this->verifier()->google($this->googleToken(['exp' => null]));
    }

    public function test_google_token_with_a_bad_signature_is_refused(): void
    {
        $this->fakeGoogleKeys($this->jwks('google-key', $this->signingKey));

        $this->expectException(InvalidIdentityException::class);

        $this->verifier()->google($this->googleToken([], $this->rsaKey()));
    }

    public function test_google_keys_are_cached_between_verifications(): void
    {
        $this->fakeGoogleKeys($this->jwks('google-key', $this->signingKey));

        $this->verifier()->google($this->googleToken(['sub' => 'first']));
        $this->verifier()->google($this->googleToken(['sub' => 'second']));

        Http::assertSentCount(1);
    }

    public function test_google_keys_are_refetched_once_when_the_key_id_is_unknown(): void
    {
        $rotated = $this->rsaKey();

        Http::fake([
            self::GOOGLE_CERTS => Http::sequence()
                ->push($this->jwks('old-key', $this->signingKey))
                ->push($this->jwks('new-key', $rotated)),
        ]);

        $this->verifier()->google($this->googleToken(['sub' => 'warm'], $this->signingKey, 'old-key'));
        $identity = $this->verifier()->google($this->googleToken([], $rotated, 'new-key'));

        $this->assertSame('google-sub-1', $identity->providerUserId);
        Http::assertSentCount(2);
    }

    public function test_google_token_with_a_key_id_still_unknown_after_the_refetch_is_refused(): void
    {
        Http::fake([
            self::GOOGLE_CERTS => Http::sequence()
                ->push($this->jwks('google-key', $this->signingKey))
                ->push($this->jwks('google-key', $this->signingKey)),
        ]);

        try {
            $this->verifier()->google($this->googleToken([], $this->signingKey, 'forged-key'));
            $this->fail('A token signed under an unpublished key id was accepted.');
        } catch (InvalidIdentityException) {
            Http::assertSentCount(2);
        }
    }

    public function test_google_is_refused_without_a_configured_audience(): void
    {
        config(['magic-starter.social.audiences.google' => []]);
        Http::fake();

        try {
            $this->verifier()->google($this->googleToken());
            $this->fail('A Google token was accepted with no configured audience.');
        } catch (InvalidIdentityException) {
            Http::assertNothingSent();
        }
    }

    public function test_replayed_google_token_is_refused(): void
    {
        $this->fakeGoogleKeys($this->jwks('google-key', $this->signingKey));
        $token = $this->googleToken();

        $this->verifier()->google($token);

        $this->expectException(InvalidIdentityException::class);

        $this->verifier()->google($token);
    }

    public function test_malformed_google_token_is_refused(): void
    {
        $this->fakeGoogleKeys($this->jwks('google-key', $this->signingKey));

        $this->expectException(InvalidIdentityException::class);

        $this->verifier()->google('not-a-jwt');
    }

    // ---------------------------------------------------------------------
    // Apple
    // ---------------------------------------------------------------------

    public function test_apple_token_with_the_matching_hashed_nonce_is_accepted(): void
    {
        $this->fakeAppleKeys();

        $identity = $this->verifier()->apple(
            $this->appleToken(['nonce' => hash('sha256', 'raw-nonce'), 'email' => 'Relay@PrivateRelay.AppleID.com']),
            'raw-nonce',
        );

        $this->assertSame('apple', $identity->provider);
        $this->assertSame('apple-sub-1', $identity->providerUserId);
        $this->assertSame('relay@privaterelay.appleid.com', $identity->email);
        $this->assertTrue($identity->emailVerified);
    }

    public function test_apple_token_for_a_configured_services_id_is_accepted(): void
    {
        $this->fakeAppleKeys();

        $identity = $this->verifier()->apple(
            $this->appleToken(['aud' => self::APPLE_SERVICES_ID, 'nonce' => hash('sha256', 'raw-nonce')]),
            'raw-nonce',
        );

        $this->assertSame('apple-sub-1', $identity->providerUserId);
    }

    public function test_apple_token_without_an_email_is_not_reported_as_verified(): void
    {
        $this->fakeAppleKeys();

        $identity = $this->verifier()->apple(
            $this->appleToken(['nonce' => hash('sha256', 'raw-nonce'), 'email' => null]),
            'raw-nonce',
        );

        $this->assertNull($identity->email);
        $this->assertFalse($identity->emailVerified);
    }

    public function test_apple_is_refused_without_a_nonce(): void
    {
        $this->fakeAppleKeys();

        $this->expectException(InvalidIdentityException::class);

        $this->verifier()->apple($this->appleToken(['nonce' => hash('sha256', '')]), '');
    }

    public function test_apple_token_carrying_no_nonce_claim_is_refused(): void
    {
        $this->fakeAppleKeys();

        $this->expectException(InvalidIdentityException::class);

        $this->verifier()->apple($this->appleToken(['nonce' => null]), 'raw-nonce');
    }

    public function test_apple_token_with_a_wrong_nonce_is_refused(): void
    {
        $this->fakeAppleKeys();

        $this->expectException(InvalidIdentityException::class);

        $this->verifier()->apple($this->appleToken(['nonce' => hash('sha256', 'other-nonce')]), 'raw-nonce');
    }

    public function test_apple_token_sent_with_the_raw_nonce_in_the_claim_is_refused(): void
    {
        $this->fakeAppleKeys();

        $this->expectException(InvalidIdentityException::class);

        $this->verifier()->apple($this->appleToken(['nonce' => 'raw-nonce']), 'raw-nonce');
    }

    public function test_reused_apple_nonce_is_refused(): void
    {
        $this->fakeAppleKeys();
        $nonce = hash('sha256', 'raw-nonce');

        $this->verifier()->apple($this->appleToken(['nonce' => $nonce, 'sub' => 'first']), 'raw-nonce');

        $this->expectException(InvalidIdentityException::class);

        $this->verifier()->apple($this->appleToken(['nonce' => $nonce, 'sub' => 'second']), 'raw-nonce');
    }

    public function test_apple_token_for_a_foreign_audience_is_refused(): void
    {
        $this->fakeAppleKeys();

        $this->expectException(InvalidIdentityException::class);

        $this->verifier()->apple(
            $this->appleToken(['aud' => 'com.someone.else', 'nonce' => hash('sha256', 'raw-nonce')]),
            'raw-nonce',
        );
    }

    public function test_apple_token_without_an_expiry_is_refused(): void
    {
        $this->fakeAppleKeys();

        $this->expectException(InvalidIdentityException::class);

        $this->verifier()->apple(
            $this->appleToken(['exp' => null, 'nonce' => hash('sha256', 'raw-nonce')]),
            'raw-nonce',
        );
    }

    public function test_apple_token_with_a_bad_signature_is_refused(): void
    {
        $this->fakeAppleKeys();

        $this->expectException(InvalidIdentityException::class);

        $this->verifier()->apple(
            $this->appleToken(['nonce' => hash('sha256', 'raw-nonce')], $this->rsaKey()),
            'raw-nonce',
        );
    }

    public function test_apple_verification_does_not_touch_the_global_apple_client_secret(): void
    {
        config(['services.apple.client_secret' => 'untouched']);
        $this->fakeAppleKeys();

        $this->verifier()->apple($this->appleToken(['nonce' => hash('sha256', 'raw-nonce')]), 'raw-nonce');

        $this->assertSame('untouched', config('services.apple.client_secret'));
        $this->assertSame('https://appleid.apple.com/auth/keys', (string) $this->appleRequests[0]['request']->getUri());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function verifier(): IdTokenVerifier
    {
        return $this->app->make(IdTokenVerifier::class);
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
     * @param  array{keys: list<array<string, string>>}  $jwks
     */
    private function fakeGoogleKeys(array $jwks): void
    {
        Http::fake([
            self::GOOGLE_CERTS => Http::response($jwks),
        ]);
    }

    /**
     * Route the Apple provider's key fetch through a Guzzle mock on the
     * container-bound factory.
     */
    private function fakeAppleKeys(): void
    {
        $this->appleRequests = [];

        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], (string) json_encode($this->jwks('apple-key', $this->signingKey))),
        ]));
        $stack->push(Middleware::history($this->appleRequests));

        $this->app->make(AppleProviderFactory::class)->setHttpClient(new Client(['handler' => $stack]));
    }

    /**
     * @param  array<string, mixed>  $overrides  a null value drops the claim
     */
    private function googleToken(
        array $overrides = [],
        ?OpenSSLAsymmetricKey $key = null,
        string $kid = 'google-key',
    ): string {
        return $this->sign([
            'iss' => 'https://accounts.google.com',
            'aud' => self::GOOGLE_WEB_CLIENT,
            'sub' => 'google-sub-1',
            'email' => 'jane@example.com',
            'email_verified' => true,
            'iat' => time() - 10,
            'exp' => time() + 3600,
            ...$overrides,
        ], $key ?? $this->signingKey, $kid);
    }

    /**
     * @param  array<string, mixed>  $overrides  a null value drops the claim
     */
    private function appleToken(array $overrides = [], ?OpenSSLAsymmetricKey $key = null): string
    {
        return $this->sign([
            'iss' => 'https://appleid.apple.com',
            'aud' => self::APPLE_BUNDLE_ID,
            'sub' => 'apple-sub-1',
            'email' => 'jane@example.com',
            'email_verified' => 'true',
            'iat' => time() - 10,
            'exp' => time() + 600,
            ...$overrides,
        ], $key ?? $this->signingKey, 'apple-key');
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function sign(array $claims, OpenSSLAsymmetricKey $key, string $kid): string
    {
        return JWT::encode(
            array_filter($claims, fn (mixed $value): bool => $value !== null),
            $key,
            'RS256',
            $kid,
        );
    }
}
