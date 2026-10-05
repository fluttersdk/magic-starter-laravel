<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\MagicStarterServiceProvider;
use FlutterSdk\MagicStarter\Models\SocialAccount;
use FlutterSdk\MagicStarter\Social\AppleProviderFactory;
use FlutterSdk\MagicStarter\Social\SocialFlowStore;
use FlutterSdk\MagicStarter\Social\StepUpConfirmations;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasSocialAccounts;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use Laravel\Socialite\SocialiteServiceProvider;

/**
 * Connect and disconnect from the authenticated profile, and the first
 * password a social-only account sets.
 *
 * The invariant under test is that a password-less account can never remove
 * its last sign-in method, so every refusal asserts the row is still there.
 */
class SocialAccountControllerTest extends TestCase
{
    private const APPLE_SERVICES_ID = 'com.example.web';

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
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The Apple refresh token is an encrypted cast.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('magic-starter.features', [
            Features::socialLogin(),
        ]);
        $app['config']->set('auth.providers.users.model', SocialAccountTestUser::class);
        $app['config']->set('magic-starter.models.user', SocialAccountTestUser::class);
        $app['config']->set('magic-starter.social.providers', [
            'google',
            'apple',
            'github',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(SocialAccountTestUser::class);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
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

    public function test_a_link_ticket_is_returned_in_the_body_and_redeems_once_for_its_user(): void
    {
        $user = $this->actingAsUser(password: 'secret-password');
        $challenge = $this->challenge();

        $response = $this->postJson('/user/social-accounts/link-ticket', [
            'provider' => 'github',
            'challenge' => $challenge,
            'password' => 'secret-password',
        ]);

        $response->assertOk();
        $ticket = (string) $response->json('data.ticket');
        $this->assertNotSame('', $ticket);
        $this->assertNull($response->headers->get('Location'));

        $store = app(SocialFlowStore::class);
        $this->assertSame(
            (string) $user->getKey(),
            $store->redeemTicket($ticket, 'github', $challenge, SocialFlowStore::INTENT_CONNECT),
        );
        $this->assertNull($store->redeemTicket($ticket, 'github', $challenge, SocialFlowStore::INTENT_CONNECT));
    }

    public function test_a_link_ticket_presented_with_another_challenge_is_refused_and_burned(): void
    {
        $user = $this->actingAsUser();
        $challenge = $this->challenge();

        $ticket = (string) $this->postJson('/user/social-accounts/link-ticket', [
            'provider' => 'github',
            'challenge' => $challenge,
            'confirmation_token' => $this->mint($user),
        ])->assertOk()->json('data.ticket');

        $store = app(SocialFlowStore::class);
        $this->assertNull(
            $store->redeemTicket($ticket, 'github', $this->challenge(), SocialFlowStore::INTENT_CONNECT),
        );
        $this->assertNull($store->redeemTicket($ticket, 'github', $challenge, SocialFlowStore::INTENT_CONNECT));
    }

    public function test_a_link_ticket_is_refused_for_a_provider_the_deployment_does_not_allow(): void
    {
        $user = $this->actingAsUser();

        $this->postJson('/user/social-accounts/link-ticket', [
            'provider' => 'microsoft',
            'challenge' => $this->challenge(),
            'confirmation_token' => $this->mint($user),
        ])->assertNotFound()->assertJsonPath('code', 'provider_not_supported');
    }

    public function test_a_link_ticket_requires_a_43_character_base64url_challenge(): void
    {
        $this->actingAsUser();

        $this->postJson('/user/social-accounts/link-ticket', [
            'provider' => 'github',
            'challenge' => 'too-short',
        ])->assertUnprocessable()->assertJsonValidationErrors('challenge');
    }

    public function test_a_link_ticket_without_proof_must_step_up(): void
    {
        $this->actingAsUser();

        $this->postJson('/user/social-accounts/link-ticket', [
            'provider' => 'github',
            'challenge' => $this->challenge(),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'step_up_required')
            ->assertJsonPath('accepts', [
                'confirmation_token',
            ])
            ->assertJsonMissingPath('data.ticket');
    }

    public function test_a_link_ticket_for_a_password_user_needs_the_right_password(): void
    {
        $this->actingAsUser(password: 'secret-password');

        $this->postJson('/user/social-accounts/link-ticket', [
            'provider' => 'github',
            'challenge' => $this->challenge(),
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/user/social-accounts/link-ticket', [
            'provider' => 'github',
            'challenge' => $this->challenge(),
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('password')->assertJsonMissingPath('data.ticket');
    }

    public function test_a_link_ticket_requires_an_authenticated_caller(): void
    {
        $this->postJson('/user/social-accounts/link-ticket', [
            'provider' => 'github',
            'challenge' => $this->challenge(),
        ])->assertUnauthorized();
    }

    public function test_a_passwordless_user_cannot_disconnect_their_only_account(): void
    {
        $user = $this->actingAsUser(password: null);
        $this->linkAccount($user, 'github');

        $this->deleteJson('/user/social-accounts/github')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'last_login_method')
            ->assertJsonPath('message', __('magic-starter::social.last_login_method'));

        $this->assertSame(1, SocialAccount::query()->where('user_id', $user->getKey())->count());
    }

    public function test_a_user_with_a_password_can_disconnect_their_only_account(): void
    {
        $user = $this->actingAsUser(password: 'secret-password');
        $this->linkAccount($user, 'github');

        $this->deleteJson('/user/social-accounts/github')->assertNoContent();

        $this->assertSame(0, SocialAccount::query()->where('user_id', $user->getKey())->count());
    }

    public function test_a_passwordless_user_can_disconnect_one_of_two_accounts_but_not_the_last(): void
    {
        $user = $this->actingAsUser(password: null);
        $this->linkAccount($user, 'github');
        $this->linkAccount($user, 'google');

        $this->deleteJson('/user/social-accounts/github')->assertNoContent();
        $this->deleteJson('/user/social-accounts/google')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'last_login_method');

        $remaining = SocialAccount::query()->where('user_id', $user->getKey())->get();
        $this->assertCount(1, $remaining);
        $this->assertSame('google', $remaining->sole()->provider);
    }

    public function test_a_revoked_account_does_not_count_as_another_sign_in_method(): void
    {
        $user = $this->actingAsUser(password: null);
        $this->linkAccount($user, 'github');
        $this->linkAccount($user, 'google', revoked: true);

        $this->deleteJson('/user/social-accounts/github')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'last_login_method');

        $this->assertSame(2, SocialAccount::query()->where('user_id', $user->getKey())->count());
    }

    public function test_disconnecting_a_provider_the_user_has_not_linked_answers_404(): void
    {
        $user = $this->actingAsUser(password: 'secret-password');
        $this->linkAccount($this->createUser('other@example.test', null), 'github');

        $this->deleteJson('/user/social-accounts/github')->assertNotFound();

        $this->assertSame(1, SocialAccount::query()->count());
        $this->assertSame(0, SocialAccount::query()->where('user_id', $user->getKey())->count());
    }

    public function test_disconnecting_apple_revokes_the_grant_with_the_stored_refresh_token(): void
    {
        $history = [];
        $this->configureApple([
            new Response(200),
        ], $history);
        $user = $this->actingAsUser(password: 'secret-password');
        $this->linkAccount($user, 'apple', refreshToken: 'stored-refresh-token');

        $this->deleteJson('/user/social-accounts/apple')->assertNoContent();

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('https://appleid.apple.com/auth/revoke', (string) $request->getUri());
        parse_str((string) $request->getBody(), $form);
        $this->assertSame('stored-refresh-token', $form['token']);
        $this->assertSame(self::APPLE_SERVICES_ID, $form['client_id']);
        $this->assertSame(0, SocialAccount::query()->count());
    }

    public function test_a_failed_apple_revocation_does_not_block_the_disconnect(): void
    {
        $history = [];
        $this->configureApple([
            new Response(500),
        ], $history);
        $user = $this->actingAsUser(password: 'secret-password');
        $this->linkAccount($user, 'apple', refreshToken: 'stored-refresh-token');

        $this->deleteJson('/user/social-accounts/apple')->assertNoContent();

        $this->assertCount(1, $history);
        $this->assertSame(0, SocialAccount::query()->count());
    }

    public function test_a_passwordless_user_sets_a_password_once(): void
    {
        $user = $this->actingAsUser(password: null);

        $this->postJson('/user/password/set', [
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
            'confirmation_token' => $this->mint($user),
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassword123', (string) $user->fresh()?->getAuthPassword()));

        // A later request loads the user afresh; the acting instance still holds the old row.
        Sanctum::actingAs($user->fresh());

        $this->postJson('/user/password/set', [
            'password' => 'OtherPassword456',
            'password_confirmation' => 'OtherPassword456',
        ])->assertUnprocessable()->assertJsonPath('code', 'password_already_set');

        $this->assertTrue(Hash::check('NewPassword123', (string) $user->fresh()?->getAuthPassword()));
    }

    public function test_setting_a_password_needs_a_matching_confirmation_and_the_package_rules(): void
    {
        $user = $this->actingAsUser(password: null);
        $token = $this->mint($user);

        $this->postJson('/user/password/set', [
            'password' => 'NewPassword123',
            'password_confirmation' => 'Different123',
            'confirmation_token' => $token,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/user/password/set', [
            'password' => 'alllowercase',
            'password_confirmation' => 'alllowercase',
            'confirmation_token' => $token,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertNull($user->fresh()?->getAuthPassword());
        // Refused for the password alone, so the token is still spendable.
        $this->assertTrue(app(StepUpConfirmations::class)->consume($user, $token));
    }

    public function test_setting_a_first_password_without_proof_must_step_up(): void
    {
        $user = $this->actingAsUser(password: null);

        $this->postJson('/user/password/set', [
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'step_up_required')
            ->assertJsonPath('accepts', [
                'confirmation_token',
            ]);

        $this->assertNull($user->fresh()?->getAuthPassword());
    }

    public function test_setting_a_first_password_with_a_spent_confirmation_token_must_step_up(): void
    {
        $user = $this->actingAsUser(password: null);
        $token = $this->mint($user);
        $this->assertTrue(app(StepUpConfirmations::class)->consume($user, $token));

        $this->postJson('/user/password/set', [
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
            'confirmation_token' => $token,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'step_up_required');

        $this->assertNull($user->fresh()?->getAuthPassword());
    }

    public function test_a_password_user_cannot_set_a_first_password(): void
    {
        $user = $this->actingAsUser(password: 'secret-password');

        $this->postJson('/user/password/set', [
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
            'confirmation_token' => $this->mint($user),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'password_already_set');

        $this->assertTrue(Hash::check('secret-password', (string) $user->fresh()?->getAuthPassword()));
    }

    public function test_a_user_model_without_the_social_trait_disconnects_and_sets_a_password(): void
    {
        config([
            'auth.providers.users.model' => PlainSocialAccountTestUser::class,
            'magic-starter.models.user' => PlainSocialAccountTestUser::class,
        ]);
        MagicStarter::useUserModel(PlainSocialAccountTestUser::class);

        $withPassword = PlainSocialAccountTestUser::query()->create([
            'name' => 'Person',
            'email' => 'password@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        $this->linkAccount($withPassword, 'github');
        Sanctum::actingAs($withPassword);

        $this->deleteJson('/user/social-accounts/github')->assertNoContent();

        $withoutPassword = PlainSocialAccountTestUser::query()->create([
            'name' => 'Person',
            'email' => 'social@example.test',
            'password' => null,
        ]);
        Sanctum::actingAs($withoutPassword);

        $this->postJson('/user/password/set', [
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
            'confirmation_token' => $this->mint($withoutPassword),
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassword123', (string) $withoutPassword->fresh()?->getAuthPassword()));
    }

    private function actingAsUser(?string $password = null): SocialAccountTestUser
    {
        $user = $this->createUser('person@example.test', $password);

        Sanctum::actingAs($user);

        return $user;
    }

    private function createUser(string $email, ?string $password): SocialAccountTestUser
    {
        return SocialAccountTestUser::query()->create([
            'name' => 'Person',
            'email' => $email,
            'password' => $password === null ? null : Hash::make($password),
        ]);
    }

    private function mint(Authenticatable $user): string
    {
        return app(StepUpConfirmations::class)->mint($user);
    }

    private function linkAccount(
        Authenticatable $user,
        string $provider,
        bool $revoked = false,
        ?string $refreshToken = null,
    ): SocialAccount {
        return SocialAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'provider_user_id' => $provider . '-' . $user->getKey(),
            'client_id' => $refreshToken === null ? null : self::APPLE_SERVICES_ID,
            'refresh_token' => $refreshToken,
            'revoked_at' => $revoked ? now() : null,
        ]);
    }

    /**
     * @param  list<Response>  $responses
     * @param  array<int, array<string, mixed>>  $history
     */
    private function configureApple(array $responses, array &$history): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        openssl_pkey_export($key, $privatePem);

        config([
            'magic-starter.social.apple' => [
                'team_id' => 'TEAM123456',
                'key_id' => 'KEY1234567',
                'private_key' => $privatePem,
                'bundle_id' => 'com.example.app',
                'services_id' => self::APPLE_SERVICES_ID,
            ],
        ]);

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        app(AppleProviderFactory::class)->setHttpClient(new Client([
            'handler' => $stack,
        ]));
    }

    private function challenge(): string
    {
        return SocialFlowStore::challenge(bin2hex(random_bytes(32)));
    }
}

class SocialAccountTestUser extends Authenticatable
{
    use HasApiTokens;
    use HasSocialAccounts;
    use HasUuids;

    protected $table = 'users';

    protected $guarded = [];
}

/**
 * A consumer User that never adopted the package's `HasSocialAccounts` trait.
 */
class PlainSocialAccountTestUser extends Authenticatable
{
    use HasApiTokens;
    use HasUuids;

    protected $table = 'users';

    protected $guarded = [];
}
