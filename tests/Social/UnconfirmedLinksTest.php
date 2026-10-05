<?php

namespace FlutterSdk\MagicStarter\Tests\Social;

use FlutterSdk\MagicStarter\Contracts\ConnectsSocialAccounts;
use FlutterSdk\MagicStarter\Contracts\ResolvesSocialUsers;
use FlutterSdk\MagicStarter\Http\Controllers\EmailVerificationController;
use FlutterSdk\MagicStarter\Http\Controllers\PasswordResetController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\SocialAccount;
use FlutterSdk\MagicStarter\Social\AppleProviderFactory;
use FlutterSdk\MagicStarter\Social\SocialSignInRefused;
use FlutterSdk\MagicStarter\Social\UnconfirmedLinks;
use FlutterSdk\MagicStarter\Social\VerifiedIdentity;
use FlutterSdk\MagicStarter\Tests\TestCase;
use FlutterSdk\MagicStarter\Traits\HasSocialAccounts;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\SanctumServiceProvider;

/**
 * Locks the repair for an account created from an unverified provider address.
 *
 * Whoever controls the provider identity may hold an account on somebody
 * else's address until the mailbox owner proves control, through a password
 * reset or the verification link. That proof severs every unconfirmed link and
 * signs the account out everywhere, so the identity can no longer sign in as
 * the recovered account; confirmed links and their sessions are left alone.
 */
class UnconfirmedLinksTest extends TestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The Apple refresh token is an encrypted cast, and Testbench ships no key.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('magic-starter.features', []);
        $app['config']->set('auth.providers.users.model', UnconfirmedLinksTestUser::class);
        $app['config']->set('magic-starter.models.user', UnconfirmedLinksTestUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(UnconfirmedLinksTestUser::class);
        Notification::fake();

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
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

        $migration = require __DIR__ . '/../../database/migrations/create_social_accounts_table.php';
        $migration->up();

        Route::post('reset-password', [PasswordResetController::class, 'reset']);
        Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware('signed')
            ->name('verification.verify');
    }

    protected function tearDown(): void
    {
        MagicStarter::reset();
        parent::tearDown();
    }

    public function test_a_password_reset_severs_the_unconfirmed_link_and_revokes_every_token(): void
    {
        $user = $this->signUpUnverified();
        $user->createToken('attacker-phone');
        $user->createToken('attacker-web');

        $this->resetPassword($user)->assertOk();

        $this->assertSame(0, SocialAccount::query()->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame('social_email_taken', $this->refusalOf(fn () => $this->resolve($this->microsoft()))->code());
    }

    public function test_email_verification_severs_the_unconfirmed_link_and_revokes_every_token(): void
    {
        $user = $this->signUpUnverified();
        $user->createToken('attacker-phone');

        $this->getJson($this->verificationUrl($user))->assertOk();

        $this->assertNotNull($user->fresh()?->email_verified_at);
        $this->assertSame(0, SocialAccount::query()->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame('social_email_taken', $this->refusalOf(fn () => $this->resolve($this->microsoft()))->code());
    }

    /**
     * Whoever signed up through the unverified identity could otherwise drop
     * that link and connect a fresh one, which would count as confirmed because
     * no unconfirmed link was left. While the address is unverified, every new
     * link is provisional.
     */
    public function test_a_link_added_while_the_address_is_unverified_is_severed_on_verification(): void
    {
        $user = $this->signUpUnverified();
        SocialAccount::query()->delete();

        $link = app(ConnectsSocialAccounts::class)->connect($user, new VerifiedIdentity(
            provider: 'google',
            providerUserId: 'attacker-google',
            email: 'attacker@example.net',
            emailVerified: true,
        ));

        $this->assertFalse($link->owner_confirmed);

        $this->getJson($this->verificationUrl($user))->assertOk();

        $this->assertSame(0, SocialAccount::query()->count());
    }

    public function test_a_confirmed_link_and_its_tokens_survive_a_password_reset(): void
    {
        $user = $this->makeUser();
        $this->link($user, 'google', 'g-1', true);
        $user->createToken('owner-phone');

        $this->resetPassword($user)->assertOk();

        $this->assertSame('g-1', SocialAccount::query()->sole()->provider_user_id);
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_a_confirmed_link_and_its_tokens_survive_email_verification(): void
    {
        $user = $this->makeUser();
        $this->link($user, 'google', 'g-1', true);
        $user->createToken('owner-phone');

        $this->getJson($this->verificationUrl($user))->assertOk();

        $this->assertSame('g-1', SocialAccount::query()->sole()->provider_user_id);
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_only_the_unconfirmed_links_are_severed(): void
    {
        $user = $this->makeUser();
        $this->link($user, 'google', 'g-1', true);
        $this->link($user, 'microsoft', 'm-1', false);

        $severed = $this->app->make(UnconfirmedLinks::class)->sever($user);

        $this->assertSame(1, $severed);
        $this->assertSame('google', SocialAccount::query()->sole()->provider);
    }

    public function test_an_unconfirmed_apple_grant_is_revoked_after_the_unlink_commits(): void
    {
        $this->configureApple();
        $user = $this->makeUser();
        $this->link($user, 'apple', 'a-1', false, [
            'client_id' => 'com.example.app',
            'refresh_token' => 'stored-refresh-token',
        ]);

        $history = [];
        $seen = [];
        $mock = new MockHandler([
            function () use (&$seen): Response {
                $seen = [
                    'level' => DB::transactionLevel(),
                    'links' => DB::table('social_accounts')->count(),
                ];

                return new Response(200);
            },
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $this->app->make(AppleProviderFactory::class)->setHttpClient(new Client(['handler' => $stack]));

        $this->resetPassword($user)->assertOk();

        $this->assertCount(1, $history);
        $this->assertSame('https://appleid.apple.com/auth/revoke', (string) $history[0]['request']->getUri());
        parse_str((string) $history[0]['request']->getBody(), $form);
        $this->assertSame('stored-refresh-token', $form['token']);
        $this->assertSame(
            [
                'level' => 0,
                'links' => 0,
            ],
            $seen,
        );
    }

    public function test_sever_is_a_no_op_without_the_social_accounts_table(): void
    {
        Schema::drop('social_accounts');
        $user = $this->makeUser();
        $user->createToken('owner-phone');

        $this->assertSame(0, $this->app->make(UnconfirmedLinks::class)->sever($user));
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    /**
     * Sign up through an identity whose provider does not vouch for the address.
     */
    private function signUpUnverified(): UnconfirmedLinksTestUser
    {
        $user = $this->resolve($this->microsoft());

        $this->assertInstanceOf(UnconfirmedLinksTestUser::class, $user);
        $this->assertNull($user->email_verified_at);
        $this->assertFalse(SocialAccount::query()->sole()->owner_confirmed);

        return $user;
    }

    private function microsoft(): VerifiedIdentity
    {
        return new VerifiedIdentity(
            provider: 'microsoft',
            providerUserId: 'm-1',
            email: 'owner@example.com',
            emailVerified: false,
            name: 'Attacker',
            tenantId: 't-1',
        );
    }

    private function resolve(VerifiedIdentity $identity): AuthenticatableContract
    {
        return $this->app->make(ResolvesSocialUsers::class)->resolve(
            $identity,
            Request::create('/auth/social/' . $identity->provider, 'POST'),
        );
    }

    private function resetPassword(UnconfirmedLinksTestUser $user): TestResponse
    {
        return $this->postJson('reset-password', [
            'token' => Password::broker()->createToken($user),
            'email' => $user->email,
            'password' => 'Owner-Password-123',
            'password_confirmation' => 'Owner-Password-123',
        ]);
    }

    private function verificationUrl(UnconfirmedLinksTestUser $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => sha1((string) $user->email),
        ]);
    }

    /**
     * Run a call that must refuse, and hand back the refusal for inspection.
     */
    private function refusalOf(callable $call): SocialSignInRefused
    {
        try {
            $call();
        } catch (SocialSignInRefused $refusal) {
            return $refusal;
        }

        $this->fail('Expected a SocialSignInRefused, but the call returned.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function link(
        UnconfirmedLinksTestUser $user,
        string $provider,
        string $providerUserId,
        bool $ownerConfirmed,
        array $attributes = [],
    ): SocialAccount {
        return SocialAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'owner_confirmed' => $ownerConfirmed,
            ...$attributes,
        ]);
    }

    private function makeUser(): UnconfirmedLinksTestUser
    {
        return UnconfirmedLinksTestUser::query()->forceCreate([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('Old-Password-123'),
        ]);
    }

    private function configureApple(): void
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
                'services_id' => 'com.example.web',
            ],
        ]);
    }
}

/**
 * @property string $id
 * @property string|null $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 */
class UnconfirmedLinksTestUser extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;
    use HasSocialAccounts;
    use HasUuids;

    protected $table = 'users';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }
}
