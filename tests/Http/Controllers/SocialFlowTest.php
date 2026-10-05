<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\SocialAccount;
use FlutterSdk\MagicStarter\Social\AppleProviderFactory;
use FlutterSdk\MagicStarter\Social\SocialFlowStore;
use FlutterSdk\MagicStarter\Social\StepUpConfirmations;
use FlutterSdk\MagicStarter\Tests\Support\FakeOAuthServer;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasSocialAccounts;
use FlutterSdk\MagicStarter\Traits\TwoFactorAuthenticatable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\SanctumServiceProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteServiceProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Psr\Http\Message\RequestInterface;
use SocialiteProviders\Manager\Config;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

/**
 * Drives the backend-hosted social flow end to end over real HTTP.
 *
 * The provider legs run against {@see FakeOAuthServer} on a real socket: the
 * test follows the redirect to the harness the way a browser would, and the
 * harness sends the browser back to the fixed callback route. GitHub and
 * Microsoft use provider classes pointed at the harness; Apple runs through
 * the production AppleProviderFactory with only its transport redirected (see
 * {@see self::appleHandler()} for the one leg still doubled). Every request
 * the package makes is real, which is how the logs can prove what was sent.
 *
 * Every refusal asserts that no token came back, because a refused flow that
 * still signs someone in is the failure this suite exists to catch.
 */
class SocialFlowTest extends TestCase
{
    private const IOS_TARGET = 'com.example.app://auth/social';

    private const ANDROID_TARGET = 'https://app.example.test/android/auth/social';

    private const WEB_TARGET = 'https://app.example.test/auth/social?from=web';

    private FakeOAuthServer $harness;

    /**
     * The public half of the key production signs Apple's client secret with.
     */
    private string $applePublicPem;

    /**
     * The nonce the production authorize url sent to Apple, echoed into the id_token the token leg answers with.
     */
    private string $appleNonce = '';

    /**
     * The issuer the doubled token leg signs into its id_token.
     */
    private string $appleIssuer = 'https://appleid.apple.com';

    /**
     * Every request production sent to Apple's token endpoint.
     *
     * @var list<RequestInterface>
     */
    private array $appleTokenRequests = [];

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
     * The routes are gated on the social feature when the provider boots, so
     * the feature and the user model are set before it does.
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
        $app['config']->set('auth.providers.users.model', SocialFlowTestUser::class);
        $app['config']->set('magic-starter.models.user', SocialFlowTestUser::class);
        $app['config']->set('magic-starter.social.providers', [
            'google',
            'apple',
            'github',
            'microsoft',
        ]);
        $app['config']->set('magic-starter.social.redirects', [
            'ios' => self::IOS_TARGET,
            'android' => self::ANDROID_TARGET,
            'web' => self::WEB_TARGET,
        ]);
        $app['config']->set('services.github.client_id', FakeOAuthServer::CLIENT_ID);
        $app['config']->set('services.google.client_id', 'google-client');
        $app['config']->set('services.microsoft.client_id', FakeOAuthServer::CLIENT_ID);

        // A real signing key, so production signs Apple's client secret for every exchange.
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        openssl_pkey_export($key, $privatePem);
        $this->applePublicPem = openssl_pkey_get_details($key)['key'];
        $app['config']->set('magic-starter.social.apple', [
            'team_id' => 'TEAM123456',
            'key_id' => 'KEY1234567',
            'private_key' => $privatePem,
            'services_id' => FakeOAuthServer::CLIENT_ID,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(SocialFlowTestUser::class);

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

        $this->harness = FakeOAuthServer::start();
        $this->pointProvidersAtTheHarness();
    }

    protected function tearDown(): void
    {
        $this->harness->stop();
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_a_github_signin_ends_in_a_token_for_a_new_linked_user(): void
    {
        [$verifier, $challenge] = $this->pkce();

        $callback = $this->landOnCallback($this->providerRedirect('github', $challenge));

        $callback->assertStatus(302);
        $this->assertSame(self::IOS_TARGET, strtok((string) $callback->headers->get('Location'), '?'));
        $this->assertSame(['code'], array_keys($this->locationQuery($callback)));
        $this->assertSame('no-referrer', $callback->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', (string) $callback->headers->get('Cache-Control'));

        $exchange = $this->exchange($this->locationQuery($callback)['code'], $verifier);

        $exchange->assertOk()
            ->assertJsonPath('data.user.email', 'octocat@example.test')
            ->assertJsonStructure([
                'data' => [
                    'user',
                    'token',
                ],
            ]);
        $account = SocialAccount::query()->sole();
        $this->assertSame('github', $account->provider);
        $this->assertSame('4242', $account->provider_user_id);
        $this->assertSame((string) SocialFlowTestUser::query()->sole()->getKey(), (string) $account->user_id);
    }

    public function test_the_provider_receives_our_own_pkce_pair_and_state_never_the_app_challenge(): void
    {
        [$verifier, $challenge] = $this->pkce();

        $this->exchange(
            $this->locationQuery($this->landOnCallback($this->providerRedirect('github', $challenge)))['code'],
            $verifier,
        )->assertOk();

        $authorize = $this->harnessRequest('GET', '/login/oauth/authorize');
        $token = $this->harnessRequest('POST', '/login/oauth/access_token');

        $this->assertSame('S256', $authorize['query']['code_challenge_method']);
        $this->assertNotSame($challenge, $authorize['query']['code_challenge']);
        $this->assertNotEmpty($authorize['query']['state']);
        $this->assertSame('http://localhost/magic-starter/social/github/callback', $authorize['query']['redirect_uri']);
        $this->assertSame($authorize['query']['redirect_uri'], $token['body']['redirect_uri']);
        $this->assertNotSame($verifier, $token['body']['code_verifier']);
        $this->assertSame($authorize['query']['code_challenge'], $this->s256($token['body']['code_verifier']));
    }

    public function test_a_microsoft_signin_keys_the_link_on_the_validated_oid_and_tid(): void
    {
        [$verifier, $challenge] = $this->pkce();

        $code = $this->locationQuery($this->landOnCallback($this->providerRedirect('microsoft', $challenge)))['code'];

        $this->exchange($code, $verifier)->assertOk()->assertJsonStructure([
            'data' => [
                'token',
            ],
        ]);
        $account = SocialAccount::query()->sole();
        $this->assertSame('microsoft', $account->provider);
        $this->assertSame('00000000-0000-0000-0000-000000004242', $account->provider_user_id);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $account->tenant_id);
        // Microsoft's address is not verified, so the new account is not either.
        $this->assertNull(SocialFlowTestUser::query()->sole()->email_verified_at);
        $this->assertNotNull($this->harnessRequest('GET', '/microsoft/discovery/v2.0/keys'));
    }

    public function test_an_apple_signin_completes_through_the_form_post_callback(): void
    {
        [$verifier, $challenge] = $this->pkce();

        $location = $this->startFlow('apple', $challenge)->assertStatus(302)->headers->get('Location');
        $fields = $this->appleFormPost((string) $location);
        $callback = $this->post('/magic-starter/social/apple/callback', $fields);

        $callback->assertStatus(302);
        $code = $this->locationQuery($callback)['code'];
        $this->exchange($code, $verifier)->assertOk()->assertJsonPath('data.user.name', 'The Octocat');

        $account = SocialAccount::query()->sole();
        $this->assertSame('apple', $account->provider);
        $this->assertSame('000042.apple.sub', $account->provider_user_id);

        // Account deletion revokes this grant, so a sign-in must keep what revocation needs.
        $this->assertStringStartsWith('fake-refresh-', (string) $account->refresh_token);
        $this->assertSame(config('magic-starter.social.apple.services_id'), $account->client_id);

        $authorize = $this->harnessRequest('GET', '/apple/auth/authorize');
        $this->assertNotEmpty($authorize['query']['nonce']);
        $this->assertSame('form_post', $authorize['query']['response_mode']);
        $this->assertArrayNotHasKey('code_challenge', $authorize['query']);

        // Production built the provider: it signed the client secret as the Services ID,
        // sent the callback it authorised, and verified the id_token against the harness keys.
        $this->assertCount(1, $this->appleTokenRequests);
        parse_str((string) $this->appleTokenRequests[0]->getBody(), $form);
        $this->assertSame(FakeOAuthServer::CLIENT_ID, $form['client_id']);
        $this->assertSame('http://localhost/magic-starter/social/apple/callback', $form['redirect_uri']);
        $this->assertSame(
            FakeOAuthServer::CLIENT_ID,
            JWT::decode($form['client_secret'], new Key($this->applePublicPem, 'ES256'))->sub,
        );
        $this->assertNotNull($this->harnessRequest('GET', '/apple/auth/keys'));
    }

    public function test_an_apple_token_leg_answered_with_a_foreign_issuer_is_refused(): void
    {
        [, $challenge] = $this->pkce();
        $this->appleIssuer = 'https://evil.example.test';

        $location = $this->startFlow('apple', $challenge)->headers->get('Location');
        $callback = $this->post('/magic-starter/social/apple/callback', $this->appleFormPost((string) $location));

        $this->assertSame(['error' => 'invalid_identity'], $this->locationQuery($callback));
        $this->assertSame(0, SocialAccount::query()->count());
    }

    public function test_an_apple_callback_with_a_user_field_that_is_not_json_still_signs_in(): void
    {
        [$verifier, $challenge] = $this->pkce();

        $location = $this->startFlow('apple', $challenge)->headers->get('Location');
        $fields = [
            ...$this->appleFormPost((string) $location),
            'user' => 'not-json',
        ];

        $callback = $this->post('/magic-starter/social/apple/callback', $fields);

        $this->exchange($this->locationQuery($callback)['code'], $verifier)->assertOk();
        $this->assertSame('000042.apple.sub', SocialAccount::query()->sole()->provider_user_id);
    }

    public function test_a_google_signin_takes_email_verified_from_the_provider(): void
    {
        $this->bindGoogleAnswering([
            'sub' => 'google-sub-1',
            'email' => 'Jane@Example.test',
            'email_verified' => false,
            'name' => 'Jane',
        ]);
        [$verifier, $challenge] = $this->pkce();

        $location = (string) $this->startFlow('google', $challenge)->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $callback = $this->get('/magic-starter/social/google/callback?' . http_build_query([
            'code' => 'google-code',
            'state' => $query['state'],
        ]));

        $this->exchange($this->locationQuery($callback)['code'], $verifier)->assertOk();
        $this->assertSame('google-sub-1', SocialAccount::query()->sole()->provider_user_id);
        $user = SocialFlowTestUser::query()->sole();
        $this->assertSame('jane@example.test', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_the_web_platform_returns_to_the_web_target_and_keeps_its_query(): void
    {
        [, $challenge] = $this->pkce();

        $callback = $this->landOnCallback($this->providerRedirect('github', $challenge, ['platform' => 'web']));

        $location = (string) $callback->headers->get('Location');
        $this->assertStringStartsWith(self::WEB_TARGET . '&code=', $location);
    }

    public function test_each_platform_returns_to_its_own_target(): void
    {
        $targets = [
            'ios' => self::IOS_TARGET,
            'android' => self::ANDROID_TARGET,
            'web' => self::WEB_TARGET,
        ];

        foreach ($targets as $platform => $target) {
            [, $challenge] = $this->pkce();

            $callback = $this->landOnCallback($this->providerRedirect('github', $challenge, ['platform' => $platform]));

            $location = (string) $callback->headers->get('Location');
            $separator = str_contains($target, '?') ? '&' : '?';
            $this->assertStringStartsWith($target . $separator . 'code=', $location, "[{$platform}]");
        }
    }

    public function test_the_retired_native_platform_is_refused(): void
    {
        [, $challenge] = $this->pkce();

        $this->startFlow('github', $challenge, ['platform' => 'native'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['platform']);

        $this->assertSame([], $this->harness->requests());
    }

    public function test_a_provider_without_its_services_block_is_refused_not_failed(): void
    {
        config(['services.github' => null]);
        [, $challenge] = $this->pkce();

        $this->startFlow('github', $challenge)
            ->assertStatus(422)
            ->assertJsonPath('code', 'platform_not_configured');

        $this->assertSame([], $this->harness->requests());
    }

    public function test_a_reused_state_is_refused(): void
    {
        [, $challenge] = $this->pkce();
        $callbackUrl = $this->providerRedirect('github', $challenge);

        $this->landOnCallback($callbackUrl)->assertStatus(302);
        $replay = $this->landOnCallback($callbackUrl);

        $this->assertSame(self::IOS_TARGET, strtok((string) $replay->headers->get('Location'), '?'));
        $this->assertSame(['error' => 'flow_expired'], $this->locationQuery($replay));
        $this->assertSame(1, $this->harnessCount('POST', '/login/oauth/access_token'));
    }

    public function test_a_replayed_provider_code_on_a_fresh_state_is_refused(): void
    {
        [, $challenge] = $this->pkce();
        $first = $this->providerRedirect('github', $challenge);
        $this->landOnCallback($first)->assertStatus(302);
        parse_str((string) parse_url($first, PHP_URL_QUERY), $firstQuery);

        $second = $this->providerRedirect('github', $challenge);
        parse_str((string) parse_url($second, PHP_URL_QUERY), $secondQuery);
        $replay = $this->get('/magic-starter/social/github/callback?' . http_build_query([
            'code' => $firstQuery['code'],
            'state' => $secondQuery['state'],
        ]));

        $this->assertSame(['error' => 'invalid_identity'], $this->locationQuery($replay));
    }

    public function test_a_code_exchanged_twice_is_refused_the_second_time(): void
    {
        [$verifier, $challenge] = $this->pkce();
        $code = $this->locationQuery($this->landOnCallback($this->providerRedirect('github', $challenge)))['code'];

        $this->exchange($code, $verifier)->assertOk();
        $second = $this->exchange($code, $verifier);

        $second->assertStatus(422)
            ->assertJsonPath('code', 'flow_expired')
            ->assertJsonPath('message', __('magic-starter::social.flow_expired'))
            ->assertJsonMissingPath('data.token');
    }

    public function test_a_wrong_verifier_is_refused_and_burns_the_code(): void
    {
        [$verifier, $challenge] = $this->pkce();
        [$otherVerifier] = $this->pkce();
        $code = $this->locationQuery($this->landOnCallback($this->providerRedirect('github', $challenge)))['code'];

        $this->exchange($code, $otherVerifier)
            ->assertStatus(422)
            ->assertJsonPath('code', 'flow_expired')
            ->assertJsonMissingPath('data.token');
        $this->exchange($code, $verifier)
            ->assertStatus(422)
            ->assertJsonMissingPath('data.token');
    }

    public function test_an_unconfigured_platform_is_refused_before_the_provider_is_asked(): void
    {
        config(['magic-starter.social.redirects.web' => null]);
        [, $challenge] = $this->pkce();

        $this->startFlow('github', $challenge, ['platform' => 'web'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'platform_not_configured')
            ->assertJsonPath('message', __('magic-starter::social.platform_not_configured'));

        $this->assertSame([], $this->harness->requests());
    }

    public function test_a_provider_outside_the_allowlist_is_refused(): void
    {
        config(['magic-starter.social.providers' => ['github']]);
        [, $challenge] = $this->pkce();

        $this->startFlow('microsoft', $challenge)
            ->assertStatus(404)
            ->assertJsonPath('code', 'provider_not_supported');
        $this->startFlow('facebook', $challenge)
            ->assertStatus(404)
            ->assertJsonPath('code', 'provider_not_supported');
        // A state that names no platform leaves no target to send the browser back to.
        $this->get('/magic-starter/social/facebook/callback?state=x&code=y')
            ->assertStatus(422)
            ->assertJsonPath('code', 'flow_expired');
    }

    public function test_a_malformed_challenge_is_refused_as_json_even_for_a_browser(): void
    {
        $this->get('/auth/social/github/redirect?platform=ios&challenge=short')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['challenge']);
    }

    public function test_a_provider_error_callback_is_refused_and_consumes_the_state(): void
    {
        [, $challenge] = $this->pkce();
        $location = (string) $this->startFlow('github', $challenge)->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $denied = $this->get('/magic-starter/social/github/callback?' . http_build_query([
            'error' => 'access_denied',
            'state' => $query['state'],
        ]));
        $retry = $this->get('/magic-starter/social/github/callback?' . http_build_query([
            'code' => 'anything',
            'state' => $query['state'],
        ]));

        $this->assertSame(['error' => 'invalid_identity'], $this->locationQuery($denied));
        $this->assertSame(['error' => 'flow_expired'], $this->locationQuery($retry));
        $this->assertSame(0, SocialFlowTestUser::query()->count());
        // A declined authorisation never reaches the provider's token endpoint.
        $this->assertSame(0, $this->harnessCount('POST', '/login/oauth/access_token'));
    }

    /**
     * Sequential replays are stopped by the record being dropped; this pins
     * the part that stops CONCURRENT ones. The store here never forgets, which
     * is the window two racing consumers share: both can still read the record,
     * and only the claimed sentinel decides who redeems it.
     */
    public function test_a_record_still_readable_by_a_racing_consumer_is_redeemed_once(): void
    {
        Cache::extend('never-forgets', fn () => Cache::repository(new class extends ArrayStore
        {
            public function forget($key): bool
            {
                return true;
            }
        }));
        config([
            'cache.stores.never-forgets' => [
                'driver' => 'never-forgets',
            ],
            'magic-starter.social.cache_store' => 'never-forgets',
        ]);
        $store = app(SocialFlowStore::class);
        $token = $store->put('code', [
            'value' => 'once',
        ], 60);

        $this->assertSame(['value' => 'once'], $store->pull('code', $token, 60));
        $this->assertNull($store->pull('code', $token, 60));
    }

    public function test_an_address_held_by_an_unlinked_account_is_refused_at_the_callback(): void
    {
        $this->makeUser('octocat@example.test');
        [, $challenge] = $this->pkce();

        $callback = $this->landOnCallback($this->providerRedirect('github', $challenge));

        $this->assertSame(['error' => 'social_email_taken'], $this->locationQuery($callback));
        $this->assertSame(0, SocialAccount::query()->count());
    }

    public function test_a_user_with_confirmed_two_factor_gets_a_challenge_instead_of_a_token(): void
    {
        $user = $this->makeUser('octocat@example.test', [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->link($user, 'github', '4242');
        [$verifier, $challenge] = $this->pkce();

        $code = $this->locationQuery($this->landOnCallback($this->providerRedirect('github', $challenge)))['code'];
        $exchange = $this->exchange($code, $verifier);

        $exchange->assertOk()
            ->assertJsonPath('two_factor', true)
            ->assertJsonMissingPath('data.token');
        $payload = json_decode(decrypt($exchange->json('two_factor_token')), true);
        $this->assertSame((string) $user->getKey(), (string) $payload['user_id']);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_connect_ticket_bound_to_another_challenge_is_refused_and_burned(): void
    {
        $user = $this->makeUser('jane@example.test');
        [, $ticketChallenge] = $this->pkce();
        [, $otherChallenge] = $this->pkce();
        $ticket = app(SocialFlowStore::class)->mintTicket($user, 'github', $ticketChallenge, SocialFlowStore::INTENT_CONNECT);

        $this->startFlow('github', $otherChallenge, ['ticket' => $ticket])
            ->assertStatus(422)
            ->assertJsonPath('code', 'flow_expired');
        $this->startFlow('github', $ticketChallenge, ['ticket' => $ticket])
            ->assertStatus(422)
            ->assertJsonPath('code', 'flow_expired');

        $this->assertSame([], $this->harness->requests());
    }

    public function test_a_connect_ticket_for_another_provider_is_refused(): void
    {
        $user = $this->makeUser('jane@example.test');
        [, $challenge] = $this->pkce();
        $ticket = app(SocialFlowStore::class)->mintTicket($user, 'microsoft', $challenge, SocialFlowStore::INTENT_CONNECT);

        $this->startFlow('github', $challenge, ['ticket' => $ticket])
            ->assertStatus(422)
            ->assertJsonPath('code', 'flow_expired');
    }

    public function test_a_connect_flow_links_only_at_the_exchange_and_only_for_the_tickets_user(): void
    {
        $user = $this->makeUser('jane@example.test');
        $intruder = $this->makeUser('intruder@example.test');
        [$verifier, $challenge] = $this->pkce();
        $ticket = app(SocialFlowStore::class)->mintTicket($user, 'github', $challenge, SocialFlowStore::INTENT_CONNECT);

        $code = $this->locationQuery($this->landOnCallback($this->providerRedirect('github', $challenge, [
            'ticket' => $ticket,
        ])))['code'];

        // 1. The callback stages the identity; nothing is linked yet.
        $this->assertSame(0, SocialAccount::query()->count());

        // 2. Another user's bearer cannot redeem it, and burns it.
        $this->exchange($code, $verifier, $intruder->createToken('t')->plainTextToken)->assertStatus(401);
        $this->assertSame(0, SocialAccount::query()->count());
    }

    public function test_a_connect_exchange_with_the_tickets_bearer_links_the_identity(): void
    {
        $user = $this->makeUser('jane@example.test');
        [$verifier, $challenge] = $this->pkce();
        $ticket = app(SocialFlowStore::class)->mintTicket($user, 'github', $challenge, SocialFlowStore::INTENT_CONNECT);

        $code = $this->locationQuery($this->landOnCallback($this->providerRedirect('github', $challenge, [
            'ticket' => $ticket,
        ])))['code'];
        $exchange = $this->exchange($code, $verifier, $user->createToken('t')->plainTextToken);

        $exchange->assertOk()
            ->assertJsonPath('data.provider', 'github')
            ->assertJsonMissingPath('data.token');
        $account = SocialAccount::query()->sole();
        $this->assertSame((string) $user->getKey(), (string) $account->user_id);
        $this->assertSame('4242', $account->provider_user_id);
        $this->assertSame(1, SocialFlowTestUser::query()->count());
    }

    public function test_a_connect_exchange_without_a_bearer_is_refused(): void
    {
        $user = $this->makeUser('jane@example.test');
        [$verifier, $challenge] = $this->pkce();
        $ticket = app(SocialFlowStore::class)->mintTicket($user, 'github', $challenge, SocialFlowStore::INTENT_CONNECT);

        $code = $this->locationQuery($this->landOnCallback($this->providerRedirect('github', $challenge, [
            'ticket' => $ticket,
        ])))['code'];

        $this->exchange($code, $verifier)
            ->assertStatus(401)
            ->assertJsonMissingPath('data.token');
        $this->assertSame(0, SocialAccount::query()->count());
    }

    public function test_a_connect_of_an_identity_another_user_holds_is_refused(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $this->link($owner, 'github', '4242');
        $user = $this->makeUser('jane@example.test');
        [$verifier, $challenge] = $this->pkce();
        $ticket = app(SocialFlowStore::class)->mintTicket($user, 'github', $challenge, SocialFlowStore::INTENT_CONNECT);

        $code = $this->locationQuery($this->landOnCallback($this->providerRedirect('github', $challenge, [
            'ticket' => $ticket,
        ])))['code'];

        $this->exchange($code, $verifier, $user->createToken('t')->plainTextToken)
            ->assertStatus(409)
            ->assertJsonPath('code', 'social_account_taken');
        $this->assertSame((string) $owner->getKey(), (string) SocialAccount::query()->sole()->user_id);
    }

    public function test_a_confirm_flow_returns_a_step_up_token_for_the_linked_bearer(): void
    {
        $user = $this->makeUser('octocat@example.test');
        $this->link($user, 'github', '4242');
        [$verifier, $challenge] = $this->pkce();

        $code = $this->locationQuery($this->landOnCallback($this->providerRedirect('github', $challenge, [
            'intent' => 'confirm',
        ])))['code'];
        $exchange = $this->exchange($code, $verifier, $user->createToken('t')->plainTextToken);

        $exchange->assertOk()->assertJsonMissingPath('data.token');
        $confirmation = (string) $exchange->json('data.confirmation_token');
        $confirmations = app(StepUpConfirmations::class);
        $this->assertTrue($confirmations->consume($user, $confirmation));
        $this->assertFalse($confirmations->consume($user, $confirmation));
    }

    public function test_a_confirm_flow_for_an_identity_linked_to_someone_else_is_refused(): void
    {
        $owner = $this->makeUser('octocat@example.test');
        $this->link($owner, 'github', '4242');
        $user = $this->makeUser('jane@example.test');
        [$verifier, $challenge] = $this->pkce();

        $code = $this->locationQuery($this->landOnCallback($this->providerRedirect('github', $challenge, [
            'intent' => 'confirm',
        ])))['code'];

        $this->exchange($code, $verifier, $user->createToken('t')->plainTextToken)
            ->assertStatus(403)
            ->assertJsonPath('code', 'invalid_identity')
            ->assertJsonMissingPath('data.confirmation_token');
    }

    public function test_a_confirm_flow_through_a_revoked_link_is_refused(): void
    {
        $user = $this->makeUser('octocat@example.test');
        $this->link($user, 'github', '4242');
        SocialAccount::query()->update(['revoked_at' => now()]);
        [$verifier, $challenge] = $this->pkce();

        $code = $this->locationQuery($this->landOnCallback($this->providerRedirect('github', $challenge, [
            'intent' => 'confirm',
        ])))['code'];

        $this->exchange($code, $verifier, $user->createToken('t')->plainTextToken)
            ->assertStatus(403)
            ->assertJsonMissingPath('data.confirmation_token');
    }

    public function test_a_step_up_confirmation_is_bound_to_its_user(): void
    {
        $user = $this->makeUser('jane@example.test');
        $other = $this->makeUser('other@example.test');
        $confirmations = app(StepUpConfirmations::class);

        $token = $confirmations->mint($user);

        $this->assertFalse($confirmations->consume($other, $token));
        $this->assertFalse($confirmations->consume($user, $token));
        $this->assertTrue($confirmations->consume($user, $confirmations->mint($user)));
    }

    /**
     * Point GitHub, Microsoft and Apple at the harness without production code knowing about it.
     */
    private function pointProvidersAtTheHarness(): void
    {
        $harness = $this->harness;

        Socialite::extend('github', fn ($app) => $harness->githubProvider($app['request'], ''));

        Socialite::extend('microsoft', function ($app) use ($harness): MicrosoftProvider {
            $provider = new class($app['request'], FakeOAuthServer::CLIENT_ID, FakeOAuthServer::CLIENT_SECRET, '', ['handler' => $this->microsoftHandler()], $harness) extends MicrosoftProvider
            {
                public function __construct($request, $clientId, $clientSecret, $redirectUrl, $guzzle, private FakeOAuthServer $harness)
                {
                    parent::__construct($request, $clientId, $clientSecret, $redirectUrl, $guzzle);
                }

                protected function getAuthUrl($state): string
                {
                    return $this->buildAuthUrlFromBase(
                        $this->harness->url('/microsoft/' . $this->getConfig('tenant') . '/oauth2/v2.0/authorize'),
                        $state,
                    );
                }
            };

            // The harness issuer carries the identity's tenant, so the provider is configured for that tenant.
            return $provider->setConfig(new Config(FakeOAuthServer::CLIENT_ID, FakeOAuthServer::CLIENT_SECRET, '', [
                'tenant' => '11111111-1111-1111-1111-111111111111',
            ]));
        });

        // Apple runs through the production factory; only its transport is pointed elsewhere.
        app(AppleProviderFactory::class)->setHttpClient(new Client([
            'handler' => $this->appleHandler(),
        ]));
    }

    /**
     * Apple's transport: the key set comes from the harness, the token leg is answered here.
     *
     * The token leg is the one part still doubled, for two reasons the harness cannot meet: the
     * provider pins the id_token issuer to `https://appleid.apple.com` while the harness signs
     * `<origin>/apple`, and the harness demands its fixed client secret while production signs a
     * fresh ES256 one. So this answers with an id_token under Apple's own issuer, signed by the
     * harness key whose JWKS the provider really fetches, and the provider's own `validateToken`
     * checks signature, issuer, audience and nonce exactly as it would against Apple.
     */
    private function appleHandler(): HandlerStack
    {
        $harness = parse_url($this->harness->baseUrl());
        $stack = HandlerStack::create();
        $route = function (callable $next, RequestInterface $request, array $options) use ($harness) {
            $uri = $request->getUri();

            if ($uri->getHost() === 'appleid.apple.com' && $uri->getPath() === '/auth/token') {
                $this->appleTokenRequests[] = $request;

                return Create::promiseFor($this->appleTokenResponse($request));
            }

            if ($uri->getHost() === 'appleid.apple.com') {
                $request = $request->withUri($uri->withScheme('http')
                    ->withHost($harness['host'])
                    ->withPort($harness['port'])
                    ->withPath('/apple' . $uri->getPath()));
            }

            return $next($request, $options);
        };
        $stack->push(fn (callable $next): callable => fn (RequestInterface $request, array $options) => $route(
            $next,
            $request,
            $options,
        ));

        return $stack;
    }

    /**
     * Apple's token response for the code exchange production just sent.
     */
    private function appleTokenResponse(RequestInterface $request): Response
    {
        parse_str((string) $request->getBody(), $form);

        $idToken = JWT::encode([
            'iss' => $this->appleIssuer,
            'aud' => $form['client_id'] ?? null,
            'sub' => '000042.apple.sub',
            'email' => 'octocat@example.test',
            'email_verified' => true,
            'nonce' => $this->appleNonce,
            'iat' => time(),
            'exp' => time() + 600,
        ], $this->harness->signingKeyPem(), 'RS256', FakeOAuthServer::KEY_ID);

        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'access_token' => 'fake-access',
            'token_type' => 'bearer',
            'expires_in' => 3600,
            'refresh_token' => 'fake-refresh-' . bin2hex(random_bytes(8)),
            'id_token' => $idToken,
        ]));
    }

    /**
     * Move Microsoft's login host onto the harness and answer Graph's `/me`, which the harness does not serve.
     *
     * The Graph `id` differs from the id_token's `oid` on purpose: the link must be keyed on the oid.
     */
    private function microsoftHandler(): HandlerStack
    {
        $harness = parse_url($this->harness->baseUrl());
        $stack = HandlerStack::create();
        $stack->push(fn (callable $next): callable => function (RequestInterface $request, array $options) use ($next, $harness) {
            $uri = $request->getUri();

            if ($uri->getHost() === 'graph.microsoft.com') {
                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'id' => 'graph-id-is-not-the-oid',
                    'displayName' => 'The Octocat',
                    'userPrincipalName' => 'octocat@example.test',
                ])));
            }

            if ($uri->getHost() === 'login.microsoftonline.com') {
                $request = $request->withUri($uri->withScheme('http')
                    ->withHost($harness['host'])
                    ->withPort($harness['port'])
                    ->withPath('/microsoft' . $uri->getPath()));
            }

            return $next($request, $options);
        });

        return $stack;
    }

    /**
     * Bind a Google provider whose token and userinfo calls are answered by a Guzzle mock.
     *
     * @param  array<string, mixed>  $userinfo
     */
    private function bindGoogleAnswering(array $userinfo): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'access_token' => 'google-access',
                'token_type' => 'Bearer',
            ])),
            new Response(200, [], (string) json_encode($userinfo)),
        ]);

        Socialite::extend('google', fn ($app) => (new GoogleProvider(
            $app['request'],
            'google-client',
            'google-secret',
            '',
        ))->setHttpClient(new Client(['handler' => HandlerStack::create($mock)])));
    }

    /**
     * An app-side PKCE pair: what the app keeps, and what it sends on the redirect.
     *
     * @return array{0: string, 1: string}
     */
    private function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        return [
            $verifier,
            $this->s256($verifier),
        ];
    }

    private function s256(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Open the package's redirect endpoint the way a browser does (no JSON accept header).
     *
     * @param  array<string, string>  $query
     */
    private function startFlow(string $provider, string $challenge, array $query = []): TestResponse
    {
        return $this->get('/auth/social/' . $provider . '/redirect?' . http_build_query([
            'platform' => 'ios',
            'challenge' => $challenge,
            ...$query,
        ]));
    }

    /**
     * Start a flow and follow it through the harness authorize endpoint, returning the callback url it sends back.
     *
     * @param  array<string, string>  $query
     */
    private function providerRedirect(string $provider, string $challenge, array $query = []): string
    {
        $location = $this->startFlow($provider, $challenge, $query)->assertStatus(302)->headers->get('Location');
        $response = (new Client(['allow_redirects' => false]))->get((string) $location);

        $this->assertSame(302, $response->getStatusCode());

        return $response->getHeaderLine('Location');
    }

    /**
     * Land on the callback url the harness redirected to, in process.
     */
    private function landOnCallback(string $callbackUrl): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->get((string) parse_url($callbackUrl, PHP_URL_PATH) . '?' . parse_url($callbackUrl, PHP_URL_QUERY));
    }

    /**
     * Fetch Apple's form_post page from the harness and read the fields it would submit.
     *
     * @return array<string, string>
     */
    private function appleFormPost(string $location): array
    {
        // The browser leg: production sends the browser to Apple; the harness plays Apple's authorize page.
        $this->assertStringStartsWith('https://appleid.apple.com/auth/authorize?', $location);
        $query = (string) parse_url($location, PHP_URL_QUERY);
        parse_str($query, $authorize);
        $this->appleNonce = (string) $authorize['nonce'];

        $html = (string) (new Client)->get($this->harness->url('/apple/auth/authorize?' . $query))->getBody();
        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $matches, PREG_SET_ORDER);

        $fields = [];

        foreach ($matches as $match) {
            $fields[html_entity_decode($match[1])] = html_entity_decode($match[2]);
        }

        $this->assertArrayHasKey('id_token', $fields);

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    private function locationQuery(TestResponse $response): array
    {
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        unset($query['from']);

        return $query;
    }

    private function exchange(string $code, string $verifier, ?string $bearer = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $request = $bearer === null ? $this : $this->withToken($bearer);

        return $request->postJson('/auth/social/exchange', [
            'code' => $code,
            'code_verifier' => $verifier,
        ]);
    }

    /**
     * @return array{method: string, path: string, query: array<string, mixed>, body: array<string, mixed>, headers: array<string, string>}
     */
    private function harnessRequest(string $method, string $path): array
    {
        foreach ($this->harness->requests() as $request) {
            if ($request['method'] === $method && $request['path'] === $path) {
                return $request;
            }
        }

        $this->fail(sprintf('The harness received no [%s %s].', $method, $path));
    }

    private function harnessCount(string $method, string $path): int
    {
        return count(array_filter(
            $this->harness->requests(),
            fn (array $request): bool => $request['method'] === $method && $request['path'] === $path,
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeUser(string $email, array $attributes = []): SocialFlowTestUser
    {
        return SocialFlowTestUser::query()->create([
            'name' => 'Existing',
            'email' => $email,
            'password' => Hash::make('Password123'),
            ...$attributes,
        ]);
    }

    private function link(SocialFlowTestUser $user, string $provider, string $providerUserId): void
    {
        SocialAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
        ]);
    }
}

/**
 * @property string $id
 * @property string|null $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 */
class SocialFlowTestUser extends Authenticatable
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
