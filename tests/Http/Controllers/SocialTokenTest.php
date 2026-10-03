<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use Firebase\JWT\JWT;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\SocialAccount;
use FlutterSdk\MagicStarter\Social\AppleProviderFactory;
use FlutterSdk\MagicStarter\Social\StepUpConfirmations;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasSocialAccounts;
use FlutterSdk\MagicStarter\Traits\TwoFactorAuthenticatable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\SanctumServiceProvider;
use Laravel\Socialite\SocialiteServiceProvider;
use OpenSSLAsymmetricKey;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Drives the native ID-token endpoint with locally signed Google and Apple tokens.
 *
 * Google's key set is served through `Http::fake`; Apple's key set and token
 * endpoint through a Guzzle mock on the container-bound factory. The signing
 * helpers are copies of the private ones in `IdTokenVerifierTest`.
 *
 * Every refusal asserts that no token came back, because a refused sign-in
 * that still hands out a bearer is the failure this suite exists to catch.
 */
class SocialTokenTest extends TestCase
{
    private const GOOGLE_CERTS = 'https://www.googleapis.com/oauth2/v3/certs';

    private const GOOGLE_CLIENT = 'ios-client.apps.googleusercontent.com';

    private const APPLE_BUNDLE_ID = 'com.example.app';

    private const APPLE_SERVICES_ID = 'com.example.web';

    private OpenSSLAsymmetricKey $signingKey;

    /**
     * @var list<array{request: Request, response: Response|null}>
     */
    private array $appleRequests = [];

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            SocialiteServiceProvider::class,
            MagicStarterServiceProvider::class,
        ];
    }

    /**
     * The routes are gated on the social feature when the provider boots.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The Apple refresh token is an encrypted cast and the 2FA secret is encrypted.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('magic-starter.features', [
            Features::socialLogin(),
            Features::twoFactorAuthentication(),
        ]);
        $app['config']->set('auth.providers.users.model', SocialTokenTestUser::class);
        $app['config']->set('magic-starter.models.user', SocialTokenTestUser::class);
        $app['config']->set('magic-starter.social.providers', [
            'google',
            'apple',
            'github',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(SocialTokenTestUser::class);
        Http::preventStrayRequests();

        $this->signingKey = $this->rsaKey();

        $ecKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        openssl_pkey_export($ecKey, $ecPem);

        config([
            'magic-starter.social.audiences.google' => [
                self::GOOGLE_CLIENT,
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

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        $migration = require __DIR__ . '/../../../database/migrations/create_social_accounts_table.php';
        $migration->up();
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Sign-in
    // ---------------------------------------------------------------------

    public function test_a_google_signin_creates_a_linked_user_and_a_token(): void
    {
        $this->fakeGoogleKeys();

        $response = $this->postToken('google', [
            'id_token' => $this->googleToken([
                'email' => 'Jane@Example.test',
                'name' => 'Jane Doe',
            ]),
        ]);

        $response->assertOk()->assertJsonPath('data.user.email', 'jane@example.test');
        $this->assertNotEmpty($response->json('data.token'));
        $user = SocialTokenTestUser::query()->sole();
        $this->assertSame('jane@example.test', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(1, $user->tokens()->count());
        $account = SocialAccount::query()->sole();
        $this->assertSame('google', $account->provider);
        $this->assertSame('google-sub-1', $account->provider_user_id);
        $this->assertSame((string) $user->getKey(), (string) $account->user_id);
        $this->assertNull($account->refresh_token);
    }

    public function test_a_replayed_google_token_is_refused(): void
    {
        $this->fakeGoogleKeys();
        $token = $this->googleToken();

        $this->postToken('google', ['id_token' => $token])->assertOk();
        $replay = $this->postToken('google', ['id_token' => $token]);

        $replay->assertStatus(401)
            ->assertJsonPath('code', 'invalid_identity')
            ->assertJsonMissingPath('data.token');
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_a_google_token_with_a_bad_signature_is_refused(): void
    {
        $this->fakeGoogleKeys();

        $this->postToken('google', ['id_token' => $this->googleToken([], $this->rsaKey())])
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_identity')
            ->assertJsonMissingPath('data.token');
        $this->assertSame(0, SocialTokenTestUser::query()->count());
    }

    public function test_a_google_key_set_answer_without_keys_is_a_service_failure_not_a_verdict(): void
    {
        Http::fake([
            self::GOOGLE_CERTS => Http::response(['unexpected' => true], 200),
        ]);

        $this->postToken('google', ['id_token' => $this->googleToken()])
            ->assertStatus(503)
            ->assertJsonPath('code', 'provider_unavailable')
            ->assertJsonMissingPath('data.token');
    }

    public function test_an_unreachable_google_key_set_is_a_service_failure_not_a_verdict(): void
    {
        Http::fake([
            self::GOOGLE_CERTS => Http::response('', 500),
        ]);

        $response = $this->postToken('google', ['id_token' => $this->googleToken()]);

        $response->assertStatus(503)
            ->assertJsonPath('code', 'provider_unavailable')
            ->assertJsonMissingPath('data.token');
        $this->assertNotSame('invalid_identity', $response->json('code'));
        $this->assertSame(0, SocialTokenTestUser::query()->count());
    }

    public function test_an_address_held_by_an_unlinked_account_is_refused_without_a_token(): void
    {
        $this->fakeGoogleKeys();
        $this->makeUser('jane@example.com');

        $this->postToken('google', ['id_token' => $this->googleToken()])
            ->assertStatus(409)
            ->assertJsonPath('code', 'social_email_taken')
            ->assertJsonPath('message', __('magic-starter::social.social_email_taken'))
            ->assertJsonMissingPath('data.token');
        $this->assertSame(0, SocialAccount::query()->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_a_user_with_confirmed_two_factor_gets_a_challenge_instead_of_a_token(): void
    {
        $this->fakeGoogleKeys();
        $user = $this->makeUser('jane@example.com', [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->link($user, 'google', 'google-sub-1');

        $response = $this->postToken('google', ['id_token' => $this->googleToken()]);

        $response->assertOk()
            ->assertJsonPath('two_factor', true)
            ->assertJsonMissingPath('data.token');
        $payload = json_decode(decrypt($response->json('two_factor_token')), true);
        $this->assertSame((string) $user->getKey(), (string) $payload['user_id']);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_an_apple_signin_without_a_nonce_is_refused_before_verification(): void
    {
        $this->fakeApple([]);

        $this->postToken('apple', ['id_token' => $this->appleToken()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nonce')
            ->assertJsonMissingPath('data.token');
        $this->assertSame([], $this->appleRequests);
        $this->assertSame(0, SocialTokenTestUser::query()->count());
    }

    public function test_an_apple_signin_with_a_code_keeps_the_encrypted_refresh_token_for_the_bundle_id(): void
    {
        $this->fakeApple([
            $this->appleKeysResponse(),
            new Response(200, [], (string) json_encode([
                'access_token' => 'apple-access',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'refresh_token' => 'apple-refresh-1',
                'id_token' => 'ignored',
            ])),
        ]);

        $response = $this->postToken('apple', [
            'id_token' => $this->appleToken(['nonce' => hash('sha256', 'raw-nonce')]),
            'nonce' => 'raw-nonce',
            'authorization_code' => 'apple-code-1',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.token'));
        $account = SocialAccount::query()->sole();
        $this->assertSame('apple', $account->provider);
        $this->assertSame('apple-sub-1', $account->provider_user_id);
        $this->assertSame('apple-refresh-1', $account->refresh_token);
        $this->assertSame(self::APPLE_BUNDLE_ID, $account->client_id);
        // Encrypted at rest: the column never holds the token in the clear.
        $stored = (string) DB::table('social_accounts')->value('refresh_token');
        $this->assertNotSame('apple-refresh-1', $stored);
        $this->assertSame('apple-refresh-1', decrypt($stored, false));

        $exchange = $this->appleRequests[1]['request'];
        parse_str((string) $exchange->getBody(), $fields);
        $this->assertSame('https://appleid.apple.com/auth/token', (string) $exchange->getUri());
        $this->assertSame('authorization_code', $fields['grant_type']);
        $this->assertSame('apple-code-1', $fields['code']);
        $this->assertSame(self::APPLE_BUNDLE_ID, $fields['client_id']);
    }

    public function test_a_failed_apple_code_exchange_does_not_block_the_signin(): void
    {
        $this->fakeApple([
            $this->appleKeysResponse(),
            new Response(400, [], (string) json_encode([
                'error' => 'invalid_grant',
            ])),
        ]);

        $response = $this->postToken('apple', [
            'id_token' => $this->appleToken(['nonce' => hash('sha256', 'raw-nonce')]),
            'nonce' => 'raw-nonce',
            'authorization_code' => 'apple-code-spent',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.token'));
        $account = SocialAccount::query()->sole();
        $this->assertNull($account->refresh_token);
        $this->assertNull($account->client_id);
        $this->assertCount(2, $this->appleRequests);
    }

    public function test_a_refused_apple_signin_never_redeems_the_authorization_code(): void
    {
        $this->fakeApple([
            $this->appleKeysResponse(),
            new Response(200, [], (string) json_encode([
                'refresh_token' => 'apple-refresh-orphan',
            ])),
        ]);
        $this->makeUser('jane@privaterelay.appleid.com');

        $this->postToken('apple', [
            'id_token' => $this->appleToken(['nonce' => hash('sha256', 'raw-nonce')]),
            'nonce' => 'raw-nonce',
            'authorization_code' => 'apple-code-refused',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'social_email_taken')
            ->assertJsonMissingPath('data.token');

        // Only the key set was fetched: no refresh token was minted that nobody stores.
        $this->assertCount(1, $this->appleRequests);
        $this->assertSame(0, SocialAccount::query()->count());
    }

    public function test_a_configuration_error_during_verification_is_not_reported_as_an_outage(): void
    {
        $this->fakeApple([]);
        config([
            'magic-starter.social.apple.team_id' => '',
        ]);

        $response = $this->postToken('apple', [
            'id_token' => $this->appleToken(['nonce' => hash('sha256', 'raw-nonce')]),
            'nonce' => 'raw-nonce',
        ]);

        $response->assertStatus(500)->assertJsonMissingPath('data.token');
        $this->assertNotSame('provider_unavailable', $response->json('code'));
        $this->assertSame([], $this->appleRequests);
    }

    public function test_an_apple_signin_with_a_user_field_that_is_not_json_still_signs_in(): void
    {
        $this->fakeApple([
            $this->appleKeysResponse(),
        ]);

        $response = $this->postToken('apple', [
            'id_token' => $this->appleToken(['nonce' => hash('sha256', 'raw-nonce')]),
            'nonce' => 'raw-nonce',
            'user' => 'not-json',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame('apple-sub-1', SocialAccount::query()->sole()->provider_user_id);
    }

    public function test_a_provider_without_a_native_token_flow_is_not_routed(): void
    {
        $this->postToken('github', ['id_token' => 'anything'])
            ->assertNotFound()
            ->assertJsonMissingPath('data.token');
    }

    public function test_a_provider_outside_the_allowlist_is_refused(): void
    {
        config(['magic-starter.social.providers' => ['apple']]);
        Http::fake();

        $this->postToken('google', ['id_token' => $this->googleToken()])
            ->assertNotFound()
            ->assertJsonPath('code', 'provider_not_supported')
            ->assertJsonMissingPath('data.token');
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // Connect
    // ---------------------------------------------------------------------

    public function test_a_connect_without_a_bearer_is_refused(): void
    {
        Http::fake();

        $this->postToken('google', [
            'id_token' => $this->googleToken(),
            'intent' => 'connect',
        ])->assertStatus(401)->assertJsonMissingPath('data.token');
        $this->assertSame(0, SocialAccount::query()->count());
        $this->assertSame(0, SocialTokenTestUser::query()->count());
        // Refused before verification, so the token was not spent either.
        Http::assertNothingSent();
    }

    public function test_a_connect_with_a_bearer_links_the_identity_to_the_bearer(): void
    {
        $this->fakeGoogleKeys();
        $user = $this->makeUser('owner@example.test');

        $response = $this->postToken('google', [
            'id_token' => $this->googleToken(),
            'intent' => 'connect',
            'password' => 'Password123',
        ], $user->createToken('t')->plainTextToken);

        $response->assertOk()
            ->assertJsonPath('data.provider', 'google')
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonMissingPath('data.token');
        $account = SocialAccount::query()->sole();
        $this->assertSame((string) $user->getKey(), (string) $account->user_id);
        $this->assertSame('google-sub-1', $account->provider_user_id);
        $this->assertSame(1, SocialTokenTestUser::query()->count());
    }

    public function test_an_apple_connect_with_a_code_keeps_the_refresh_token(): void
    {
        $this->fakeApple([
            $this->appleKeysResponse(),
            new Response(200, [], (string) json_encode([
                'refresh_token' => 'apple-refresh-2',
            ])),
        ]);
        $user = $this->makeUser('owner@example.test');

        $this->postToken('apple', [
            'id_token' => $this->appleToken(['nonce' => hash('sha256', 'raw-nonce')]),
            'nonce' => 'raw-nonce',
            'authorization_code' => 'apple-code-2',
            'intent' => 'connect',
            'password' => 'Password123',
        ], $user->createToken('t')->plainTextToken)->assertOk()->assertJsonPath('data.provider', 'apple');

        $account = SocialAccount::query()->sole();
        $this->assertSame((string) $user->getKey(), (string) $account->user_id);
        $this->assertSame('apple-refresh-2', $account->refresh_token);
        $this->assertSame(self::APPLE_BUNDLE_ID, $account->client_id);
    }

    public function test_a_connect_of_an_identity_another_user_holds_is_refused(): void
    {
        $this->fakeGoogleKeys();
        $owner = $this->makeUser('owner@example.test');
        $this->link($owner, 'google', 'google-sub-1');
        $user = $this->makeUser('other@example.test');

        $this->postToken('google', [
            'id_token' => $this->googleToken(),
            'intent' => 'connect',
            'password' => 'Password123',
        ], $user->createToken('t')->plainTextToken)
            ->assertStatus(409)
            ->assertJsonPath('code', 'social_account_taken')
            ->assertJsonMissingPath('data.token');
        $this->assertSame((string) $owner->getKey(), (string) SocialAccount::query()->sole()->user_id);
    }

    public function test_a_connect_without_proof_must_step_up_before_the_token_is_spent(): void
    {
        Http::fake();
        $user = $this->makeUser('owner@example.test', [
            'password' => null,
        ]);

        $this->postToken('google', [
            'id_token' => $this->googleToken(),
            'intent' => 'connect',
        ], $user->createToken('t')->plainTextToken)
            ->assertStatus(422)
            ->assertJsonPath('code', 'step_up_required')
            ->assertJsonPath('accepts', [
                'confirmation_token',
            ]);

        $this->assertSame(0, SocialAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_password_user_connecting_with_a_wrong_password_is_refused(): void
    {
        Http::fake();
        $user = $this->makeUser('owner@example.test');

        $this->postToken('google', [
            'id_token' => $this->googleToken(),
            'intent' => 'connect',
            'password' => 'wrong-password',
        ], $user->createToken('t')->plainTextToken)
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertSame(0, SocialAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_passwordless_connect_with_a_confirmation_token_links_the_identity(): void
    {
        $this->fakeGoogleKeys();
        $user = $this->makeUser('owner@example.test', [
            'password' => null,
        ]);

        $this->postToken('google', [
            'id_token' => $this->googleToken(),
            'intent' => 'connect',
            'confirmation_token' => app(StepUpConfirmations::class)->mint($user),
        ], $user->createToken('t')->plainTextToken)
            ->assertOk()
            ->assertJsonPath('data.provider', 'google');

        $this->assertSame((string) $user->getKey(), (string) SocialAccount::query()->sole()->user_id);
    }

    // ---------------------------------------------------------------------
    // Confirm
    // ---------------------------------------------------------------------

    public function test_a_confirm_returns_a_step_up_token_for_an_identity_linked_to_the_bearer(): void
    {
        $this->fakeGoogleKeys();
        $user = $this->makeUser('jane@example.com');
        $this->link($user, 'google', 'google-sub-1');

        $response = $this->postToken('google', [
            'id_token' => $this->googleToken(),
            'intent' => 'confirm',
        ], $user->createToken('t')->plainTextToken);

        $response->assertOk()->assertJsonMissingPath('data.token');
        $confirmation = (string) $response->json('data.confirmation_token');
        $this->assertTrue(app(StepUpConfirmations::class)->consume($user, $confirmation));
    }

    public function test_a_confirm_for_an_identity_linked_to_someone_else_is_refused(): void
    {
        $this->fakeGoogleKeys();
        $owner = $this->makeUser('jane@example.com');
        $this->link($owner, 'google', 'google-sub-1');
        $user = $this->makeUser('other@example.test');

        $this->postToken('google', [
            'id_token' => $this->googleToken(),
            'intent' => 'confirm',
        ], $user->createToken('t')->plainTextToken)
            ->assertStatus(403)
            ->assertJsonPath('code', 'invalid_identity')
            ->assertJsonMissingPath('data.confirmation_token')
            ->assertJsonMissingPath('data.token');
    }

    public function test_a_confirm_through_a_revoked_link_is_refused(): void
    {
        $this->fakeGoogleKeys();
        $user = $this->makeUser('jane@example.com');
        $this->link($user, 'google', 'google-sub-1');
        SocialAccount::query()->update(['revoked_at' => now()]);

        $this->postToken('google', [
            'id_token' => $this->googleToken(),
            'intent' => 'confirm',
        ], $user->createToken('t')->plainTextToken)
            ->assertStatus(403)
            ->assertJsonMissingPath('data.confirmation_token');
    }

    public function test_a_confirm_without_a_bearer_is_refused(): void
    {
        Http::fake();

        $this->postToken('google', [
            'id_token' => $this->googleToken(),
            'intent' => 'confirm',
        ])->assertStatus(401)->assertJsonMissingPath('data.confirmation_token');
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, string>  $payload
     * @return TestResponse<HttpResponse>
     */
    private function postToken(string $provider, array $payload, ?string $bearer = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $request = $bearer === null ? $this : $this->withToken($bearer);

        return $request->postJson("/auth/social/{$provider}/token", $payload);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeUser(string $email, array $attributes = []): SocialTokenTestUser
    {
        return SocialTokenTestUser::query()->create([
            'name' => 'Existing',
            'email' => $email,
            'password' => Hash::make('Password123'),
            ...$attributes,
        ]);
    }

    private function link(SocialTokenTestUser $user, string $provider, string $providerUserId): void
    {
        SocialAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
        ]);
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
    private function jwks(string $kid): array
    {
        $details = openssl_pkey_get_details($this->signingKey);

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

    private function fakeGoogleKeys(): void
    {
        Http::fake([
            self::GOOGLE_CERTS => Http::response($this->jwks('google-key')),
        ]);
    }

    private function appleKeysResponse(): Response
    {
        return new Response(200, [], (string) json_encode($this->jwks('apple-key')));
    }

    /**
     * Answer every Apple call (key set, then token endpoint) from a Guzzle mock on the container-bound factory.
     *
     * @param  list<Response>  $responses
     */
    private function fakeApple(array $responses): void
    {
        $this->appleRequests = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->appleRequests));

        $this->app->make(AppleProviderFactory::class)->setHttpClient(new Client(['handler' => $stack]));
    }

    /**
     * @param  array<string, mixed>  $overrides  a null value drops the claim
     */
    private function googleToken(array $overrides = [], ?OpenSSLAsymmetricKey $key = null): string
    {
        return $this->sign([
            'iss' => 'https://accounts.google.com',
            'aud' => self::GOOGLE_CLIENT,
            'sub' => 'google-sub-1',
            'email' => 'jane@example.com',
            'email_verified' => true,
            'iat' => time() - 10,
            'exp' => time() + 3600,
            ...$overrides,
        ], $key ?? $this->signingKey, 'google-key');
    }

    /**
     * @param  array<string, mixed>  $overrides  a null value drops the claim
     */
    private function appleToken(array $overrides = []): string
    {
        return $this->sign([
            'iss' => 'https://appleid.apple.com',
            'aud' => self::APPLE_BUNDLE_ID,
            'sub' => 'apple-sub-1',
            'email' => 'jane@privaterelay.appleid.com',
            'email_verified' => 'true',
            'iat' => time() - 10,
            'exp' => time() + 600,
            ...$overrides,
        ], $this->signingKey, 'apple-key');
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

/**
 * @property string $id
 * @property string|null $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 */
class SocialTokenTestUser extends Authenticatable
{
    use HasApiTokens;
    use HasSocialAccounts;
    use HasUuids;
    use TwoFactorAuthenticatable;

    protected $table = 'users';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function allTeams(): Collection
    {
        return new Collection;
    }
}
