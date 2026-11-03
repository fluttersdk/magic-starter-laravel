<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\TwoFactorAuthenticatable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Drives the sign-in handoff over HTTP: a signed-in session mints a one-time
 * code, and an unauthenticated caller trades it for the answer a login gives.
 *
 * Every refusal asserts that no token came back, because a refused exchange
 * that still signs someone in is the failure this suite exists to catch. The
 * routes with the feature off are {@see SignInHandoffDisabledTest}'s, because
 * the gate is read once, when the provider boots.
 */
class SignInHandoffTest extends TestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            MagicStarterServiceProvider::class,
        ];
    }

    /**
     * The routes are gated on the feature when the provider boots, so the
     * feature and the user model are set before it does.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The 2FA secret and the challenge token are encrypted.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('magic-starter.features', [
            Features::signInHandoff(),
            Features::twoFactorAuthentication(),
        ]);
        $app['config']->set('auth.providers.users.model', SignInHandoffTestUser::class);
        $app['config']->set('magic-starter.models.user', SignInHandoffTestUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(SignInHandoffTestUser::class);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('deletion_scheduled_at')->nullable();
            $table->timestamp('orphaned_at')->nullable();
            $table->boolean('is_guest')->default(false);
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
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();

        parent::tearDown();
    }

    public function test_minting_needs_a_signed_in_caller(): void
    {
        $this->postJson('/auth/handoff')->assertUnauthorized();
    }

    /**
     * A guest has no credential besides its device, so a code minted for one
     * would hand a full sign-in to whoever holds the guest's token.
     */
    public function test_minting_refuses_a_guest(): void
    {
        $guest = $this->makeUser([
            'email' => null,
            'is_guest' => true,
        ]);

        $this->withToken($guest->createToken('auth_token')->plainTextToken)
            ->postJson('/auth/handoff')
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Only a fully signed-in account can start a sign-in on another device.',
                'code' => 'handoff_forbidden',
            ]);
    }

    /**
     * The exchange issues a full-ability token, so a restricted token must not be able to mint its way up to one.
     */
    public function test_minting_refuses_a_token_that_cannot_do_everything(): void
    {
        $user = $this->makeUser();

        $this->withToken($user->createToken('reader', ['read'])->plainTextToken)
            ->postJson('/auth/handoff')
            ->assertForbidden()
            ->assertJsonPath('code', 'handoff_forbidden')
            ->assertJsonMissingPath('data.code');
    }

    public function test_a_token_older_than_the_configured_age_cannot_mint(): void
    {
        config()->set('magic-starter.handoff.max_token_age', 300);

        $token = $this->makeUser()->createToken('auth_token')->plainTextToken;

        $this->travel(299)->seconds();
        $this->withToken($token)->postJson('/auth/handoff')->assertCreated();

        $this->travel(2)->seconds();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->postJson('/auth/handoff')
            ->assertForbidden()
            ->assertJsonPath('code', 'handoff_forbidden')
            ->assertJsonMissingPath('data.code');
    }

    public function test_without_a_configured_age_an_old_token_can_still_mint(): void
    {
        $token = $this->makeUser()->createToken('auth_token')->plainTextToken;

        $this->travel(30)->days();

        $this->withToken($token)->postJson('/auth/handoff')->assertCreated();
    }

    public function test_minting_accepts_a_full_ability_token(): void
    {
        $user = $this->makeUser();

        $this->withToken($user->createToken('auth_token')->plainTextToken)
            ->postJson('/auth/handoff')
            ->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'code',
                    'expires_in',
                ],
            ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sameAppPaths(): array
    {
        return [
            'path with query' => ['/connect?code=abc'],
            'root' => ['/'],
            'nested with fragment' => ['/teams/42/settings#billing'],
            'single dot segment' => ['/a/./b'],
            'dots inside a segment' => ['/files/v1..2'],
            'dot segment in the query' => ['/search?q=../x'],
        ];
    }

    #[DataProvider('sameAppPaths')]
    public function test_minting_accepts_a_path_inside_the_app(string $next): void
    {
        Sanctum::actingAs($this->makeUser(), ['*']);

        $this->postJson('/auth/handoff', [
            'next' => $next,
        ])->assertCreated();
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function foreignPaths(): array
    {
        return [
            'protocol relative' => ['//evil.com'],
            'backslash after the slash' => ['/\\evil'],
            'backslash later' => ['/a\\b'],
            'absolute url' => ['http://x'],
            'scheme only' => ['javascript:alert(1)'],
            'relative path' => ['connect'],
            'dot dot segment' => ['/a/../b'],
            'trailing dot dot segment' => ['/a/..'],
            'encoded dot dot segment' => ['/a/%2e%2E/b'],
            'half encoded dot dot segment' => ['/a/.%2e/b'],
            'space' => ['/a b'],
            'tab a browser strips into //' => ["/\t/evil.com"],
            'newline' => ["/a\nb"],
            'null byte' => ["/a\0b"],
            'unicode space' => ["/a\u{00A0}b"],
            'too long' => ['/' . str_repeat('a', 512)],
            'not a string' => [['/x']],
        ];
    }

    #[DataProvider('foreignPaths')]
    public function test_minting_refuses_a_path_that_could_leave_the_app(mixed $next): void
    {
        Sanctum::actingAs($this->makeUser(), ['*']);

        $this->postJson('/auth/handoff', [
            'next' => $next,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('next')
            ->assertJsonMissingPath('data.code');
    }

    public function test_a_512_character_path_is_the_longest_accepted(): void
    {
        Sanctum::actingAs($this->makeUser(), ['*']);

        $this->postJson('/auth/handoff', [
            'next' => '/' . str_repeat('a', 511),
        ])->assertCreated();
    }

    public function test_minting_answers_a_64_character_code_and_its_lifetime(): void
    {
        Sanctum::actingAs($this->makeUser(), ['*']);

        $response = $this->postJson('/auth/handoff', [
            'next' => '/connect?code=abc',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.expires_in', 60);
        $this->assertSame(64, strlen((string) $response->json('data.code')));
    }

    public function test_the_lifetime_follows_the_configured_ttl(): void
    {
        config([
            'magic-starter.handoff.code_ttl' => 30,
        ]);
        Sanctum::actingAs($this->makeUser(), ['*']);

        $this->postJson('/auth/handoff')
            ->assertCreated()
            ->assertJsonPath('data.expires_in', 30);
    }

    public function test_the_cache_holds_the_digest_of_a_code_and_never_the_code(): void
    {
        $code = $this->mint($this->makeUser(), '/connect');

        $this->assertTrue(Cache::has('magic-starter:handoff:' . hash('sha256', $code)));
        $this->assertFalse(Cache::has('magic-starter:handoff:' . $code));
        $this->assertSame(
            ['user_id', 'next'],
            array_keys(Cache::get('magic-starter:handoff:' . hash('sha256', $code))),
        );
    }

    public function test_an_exchange_answers_the_login_shape_with_next(): void
    {
        $user = $this->makeUser();
        $code = $this->mint($user, '/connect?code=abc');

        $response = $this->exchange($code);

        $response->assertOk()
            ->assertJsonPath('data.user.id', (string) $user->getKey())
            ->assertJsonPath('data.next', '/connect?code=abc')
            ->assertJsonPath('message', 'Login successful');
        $this->assertIsString($response->json('data.token'));
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('127.0.0.1', $user->tokens()->value('ip_address'));
    }

    public function test_an_exchange_without_next_answers_a_null_next(): void
    {
        $code = $this->mint($this->makeUser(), null);

        $this->exchange($code)
            ->assertOk()
            ->assertJsonPath('data.next', null);
    }

    public function test_a_replayed_code_is_refused(): void
    {
        $code = $this->mint($this->makeUser(), '/connect');

        $this->exchange($code)->assertOk();

        $this->assertRefused($this->exchange($code));
    }

    public function test_an_unknown_and_an_expired_code_answer_identically(): void
    {
        $code = $this->mint($this->makeUser(), '/connect');

        $unknown = $this->exchange(str_repeat('x', 64));
        $this->travel(61)->seconds();
        $expired = $this->exchange($code);

        $this->assertRefused($unknown);
        $this->assertRefused($expired);
        $this->assertSame($unknown->json(), $expired->json());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedExchanges(): array
    {
        return [
            'no code' => [[]],
            'empty code' => [['code' => '']],
            'null code' => [['code' => null]],
            'array code' => [['code' => ['abc']]],
            'number code' => [['code' => 12345]],
            'over 255 characters' => [['code' => str_repeat('x', 256)]],
            'very long' => [['code' => str_repeat('x', 20000)]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('malformedExchanges')]
    public function test_a_malformed_code_is_refused_like_an_unknown_one(array $payload): void
    {
        $this->assertRefused($this->postJson('/auth/handoff/exchange', $payload));
    }

    public function test_a_wrong_code_does_not_burn_a_valid_one(): void
    {
        $code = $this->mint($this->makeUser(), '/connect');

        $this->assertRefused($this->exchange(str_repeat('x', 64)));

        $this->exchange($code)->assertOk();
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
            'magic-starter.handoff.cache_store' => 'never-forgets',
        ]);
        $user = $this->makeUser();
        $code = $this->mint($user, '/connect');

        $this->assertTrue(Cache::store('never-forgets')->has('magic-starter:handoff:' . hash('sha256', $code)));
        $this->exchange($code)->assertOk();
        $this->assertRefused($this->exchange($code));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_a_user_with_confirmed_two_factor_gets_the_challenge_and_no_token(): void
    {
        $user = $this->makeUser([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $code = $this->mint($user, '/connect');

        $response = $this->exchange($code);

        $response->assertOk()
            ->assertJsonPath('two_factor', true)
            ->assertJsonPath('data.next', '/connect')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.user');
        $payload = json_decode(decrypt($response->json('two_factor_token')), true);
        $this->assertSame((string) $user->getKey(), (string) $payload['user_id']);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_an_exchange_cancels_a_scheduled_deletion_as_a_login_does(): void
    {
        $user = $this->makeUser([
            'deletion_scheduled_at' => now(),
        ]);
        $code = $this->mint($user, '/connect');

        $this->exchange($code)
            ->assertOk()
            ->assertJsonPath('data.deletion_cancelled', true)
            ->assertJsonPath('data.next', '/connect');
        $this->assertNull($user->refresh()->deletion_scheduled_at);
    }

    public function test_a_code_signs_in_the_user_who_minted_it(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser();
        $aliceCode = $this->mint($alice, '/a');
        $bobCode = $this->mint($bob, '/b');

        $this->exchange($bobCode)
            ->assertJsonPath('data.user.id', (string) $bob->getKey())
            ->assertJsonPath('data.next', '/b');
        $this->exchange($aliceCode)
            ->assertJsonPath('data.user.id', (string) $alice->getKey())
            ->assertJsonPath('data.next', '/a');
    }

    public function test_a_code_whose_user_was_deleted_answers_the_generic_refusal(): void
    {
        $user = $this->makeUser();
        $code = $this->mint($user, '/connect');
        $user->delete();

        $refused = $this->exchange($code);

        $this->assertRefused($refused);
        $this->assertSame($this->exchange(str_repeat('x', 64))->json(), $refused->json());
    }

    public function test_minting_is_throttled_per_user(): void
    {
        Sanctum::actingAs($this->makeUser(), ['*']);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/auth/handoff')->assertCreated();
        }

        $this->postJson('/auth/handoff')->assertTooManyRequests();

        Sanctum::actingAs($this->makeUser(), ['*']);

        $this->postJson('/auth/handoff')->assertCreated();
    }

    public function test_the_exchange_is_throttled_per_address(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->assertRefused($this->exchange(str_repeat('x', 64)));
        }

        $this->exchange(str_repeat('x', 64))->assertTooManyRequests();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeUser(array $attributes = []): SignInHandoffTestUser
    {
        return SignInHandoffTestUser::query()->create([
            'name' => 'Octo Cat',
            'email' => uniqid('octo', true) . '@example.test',
            ...$attributes,
        ]);
    }

    /**
     * Mint a code as the user, then sign out so the exchange runs unauthenticated.
     */
    private function mint(SignInHandoffTestUser $user, ?string $next): string
    {
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/auth/handoff', [
            'next' => $next,
        ]);
        $response->assertCreated();

        $this->app['auth']->forgetGuards();

        return (string) $response->json('data.code');
    }

    /**
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function exchange(string $code): TestResponse
    {
        return $this->postJson('/auth/handoff/exchange', [
            'code' => $code,
        ]);
    }

    /**
     * @param  TestResponse<\Illuminate\Http\JsonResponse>  $response
     */
    private function assertRefused(TestResponse $response): void
    {
        $response->assertStatus(422)
            ->assertExactJson([
                'message' => 'This sign-in link has expired. Sign in again.',
                'code' => 'handoff_expired',
            ]);
    }
}

/**
 * @property string $id
 * @property \Illuminate\Support\Carbon|null $deletion_scheduled_at
 */
class SignInHandoffTestUser extends Authenticatable
{
    use HasApiTokens;
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
            'two_factor_confirmed_at' => 'datetime',
            'deletion_scheduled_at' => 'datetime',
        ];
    }
}
