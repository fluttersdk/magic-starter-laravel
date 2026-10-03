<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\CreateUserFromProvider;
use FlutterSdk\MagicStarter\Contracts\ConnectsSocialAccounts;
use FlutterSdk\MagicStarter\Contracts\CreatesUsersFromProvider;
use FlutterSdk\MagicStarter\Contracts\ResolvesSocialUsers;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\SocialAccount;
use FlutterSdk\MagicStarter\Social\SocialSignInRefused;
use FlutterSdk\MagicStarter\Social\VerifiedIdentity;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Locks who a verified provider identity signs in as.
 *
 * The identity is the (provider, provider_user_id) pair and never the email:
 * a linked row answers, an address someone already holds is refused rather
 * than linked, and only a brand new identity creates a user. Every case
 * asserts the `users`, `social_accounts` and `teams` row counts, because what
 * a refusal must NOT write matters as much as what a success writes.
 *
 * The teams feature is switched on before the provider boots, so the personal
 * team arrives through the real `Registered` listener wiring.
 */
class ResolveSocialUserTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The refresh token is an encrypted cast, and Testbench ships no key.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('magic-starter.features', [
            Features::teams(),
            Features::extendedProfile(),
        ]);
        $app['config']->set('magic-starter.supported_locales', [
            'en',
            'tr',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'auth.providers.users.model' => ConcreteUser::class,
            'magic-starter.models.user' => ConcreteUser::class,
        ]);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('locale')->default('en');
            $table->string('timezone')->default('UTC');
            $table->string('current_team_id')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        $migration = require __DIR__ . '/../../database/migrations/create_social_accounts_table.php';
        $migration->up();
    }

    public function test_an_existing_link_signs_in_its_user_and_clears_revoked_at(): void
    {
        $user = $this->makeUser('jane@example.com');
        $this->link($user, 'google', 'g-1', [
            'email_at_link' => 'old@example.com',
            'revoked_at' => Carbon::now()->subDay(),
        ]);

        $resolved = $this->resolve($this->identity('google', 'g-1', 'New@Example.com'));

        $this->assertSame($user->getKey(), $resolved->getAuthIdentifier());
        $account = SocialAccount::query()->sole();
        $this->assertNull($account->revoked_at);
        $this->assertSame('new@example.com', $account->email_at_link);
        $this->assertCounts(users: 1, accounts: 1, teams: 0);
    }

    public function test_an_existing_link_keeps_the_recorded_email_when_the_provider_withholds_it(): void
    {
        $user = $this->makeUser('jane@example.com');
        $this->link($user, 'apple', 'a-1', [
            'email_at_link' => 'jane@example.com',
        ]);

        $resolved = $this->resolve($this->identity('apple', 'a-1', null));

        $this->assertSame($user->getKey(), $resolved->getAuthIdentifier());
        $this->assertSame('jane@example.com', SocialAccount::query()->sole()->email_at_link);
        $this->assertCounts(users: 1, accounts: 1, teams: 0);
    }

    public function test_an_address_another_user_holds_is_refused_and_nothing_is_created(): void
    {
        $this->makeUser('jane@example.com', 'hashed-secret');

        $refusal = $this->refusalOf(fn () => $this->resolve($this->identity('google', 'g-1', 'Jane@Example.COM')));

        $this->assertSame('social_email_taken', $refusal->code());
        $this->assertSame(__('magic-starter::social.social_email_taken'), $refusal->getMessage());
        $this->assertCounts(users: 1, accounts: 0, teams: 0);
    }

    public function test_a_withheld_email_without_a_link_is_refused(): void
    {
        $refusal = $this->refusalOf(fn () => $this->resolve($this->identity('apple', 'a-1', null)));

        $this->assertSame('provider_email_missing', $refusal->code());
        $this->assertSame(__('magic-starter::social.provider_email_missing'), $refusal->getMessage());
        $this->assertCounts(users: 0, accounts: 0, teams: 0);
    }

    public function test_a_new_identity_creates_a_passwordless_user_with_a_link_and_a_personal_team(): void
    {
        $request = Request::create('/auth/social/google', 'POST', server: [
            'HTTP_ACCEPT_LANGUAGE' => 'tr-TR,tr;q=0.9,en;q=0.8',
            'HTTP_X_TIMEZONE' => 'Europe/Istanbul',
        ]);

        $user = $this->resolve($this->identity('google', 'g-1', 'Ada@Example.com', name: 'Ada Lovelace'), $request);

        $stored = ConcreteUser::query()->sole();
        $this->assertSame($stored->getKey(), $user->getAuthIdentifier());
        $this->assertNull($stored->password);
        $this->assertSame('ada@example.com', $stored->email);
        $this->assertSame('Ada Lovelace', $stored->name);
        $this->assertNotNull($stored->email_verified_at);
        $this->assertSame('tr', $stored->locale);
        $this->assertSame('Europe/Istanbul', $stored->timezone);

        $account = SocialAccount::query()->sole();
        $this->assertSame($stored->getKey(), $account->user_id);
        $this->assertSame('google', $account->provider);
        $this->assertSame('g-1', $account->provider_user_id);
        $this->assertSame('ada@example.com', $account->email_at_link);

        $team = DB::table('teams')->sole();
        $this->assertSame($stored->getKey(), $team->user_id);
        $this->assertTrue((bool) $team->personal_team);
        $this->assertSame($team->id, $stored->current_team_id);
        $this->assertCounts(users: 1, accounts: 1, teams: 1);
    }

    public function test_an_unverified_email_leaves_email_verified_at_null(): void
    {
        $this->resolve($this->identity('github', 'gh-1', 'ada@example.com', verified: false));

        $this->assertNull(ConcreteUser::query()->sole()->email_verified_at);
        $this->assertCounts(users: 1, accounts: 1, teams: 1);
    }

    public function test_a_nameless_identity_is_named_after_its_address(): void
    {
        $this->resolve($this->identity('apple', 'a-1', 'ada.l@example.com'));

        $this->assertSame('ada.l', ConcreteUser::query()->sole()->name);
        $this->assertCounts(users: 1, accounts: 1, teams: 1);
    }

    public function test_identities_from_two_providers_never_merge(): void
    {
        $first = $this->resolve($this->identity('google', 'g-1', 'ada@example.com'));

        $refusal = $this->refusalOf(fn () => $this->resolve($this->identity('github', 'gh-1', 'ada@example.com')));

        $this->assertSame('social_email_taken', $refusal->code());
        $this->assertCounts(users: 1, accounts: 1, teams: 1);
        $this->assertSame('google', SocialAccount::query()->sole()->provider);

        $second = $this->resolve($this->identity('github', 'gh-1', 'ada@elsewhere.example'));

        $this->assertNotSame($first->getAuthIdentifier(), $second->getAuthIdentifier());
        $this->assertCounts(users: 2, accounts: 2, teams: 2);
    }

    public function test_a_first_sign_in_that_loses_the_race_answers_with_the_winner(): void
    {
        // The winner commits between this request's empty lookups and its own
        // insert, so the loser's write trips the unique index for real.
        $this->app->bind(CreatesUsersFromProvider::class, function ($app): CreatesUsersFromProvider {
            $real = $app->make(CreateUserFromProvider::class);

            return new class($real) implements CreatesUsersFromProvider
            {
                public function __construct(private CreatesUsersFromProvider $real) {}

                public function create(VerifiedIdentity $identity, Request $request): Authenticatable
                {
                    $this->real->create($identity, $request);

                    return $this->real->create($identity, $request);
                }
            };
        });

        $user = $this->resolve($this->identity('google', 'g-1', 'ada@example.com'));

        $this->assertSame(ConcreteUser::query()->sole()->getKey(), $user->getAuthIdentifier());
        $this->assertCounts(users: 1, accounts: 1, teams: 1);
    }

    public function test_connecting_an_identity_another_user_owns_is_refused(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $this->link($owner, 'google', 'g-1');
        $other = $this->makeUser('other@example.com');

        $refusal = $this->refusalOf(fn () => $this->connect($other, $this->identity('google', 'g-1', 'owner@example.com')));

        $this->assertSame('social_account_taken', $refusal->code());
        $this->assertSame(__('magic-starter::social.social_account_taken'), $refusal->getMessage());
        $this->assertSame($owner->getKey(), SocialAccount::query()->sole()->user_id);
        $this->assertCounts(users: 2, accounts: 1, teams: 0);
    }

    public function test_a_second_account_of_one_provider_on_a_user_is_refused(): void
    {
        $user = $this->makeUser('ada@example.com');
        $this->link($user, 'google', 'g-1');

        $refusal = $this->refusalOf(fn () => $this->connect($user, $this->identity('google', 'g-2', 'ada@example.com')));

        $this->assertSame('social_account_taken', $refusal->code());
        $this->assertSame('g-1', SocialAccount::query()->sole()->provider_user_id);
        $this->assertCounts(users: 1, accounts: 1, teams: 0);
    }

    public function test_connecting_writes_the_link_with_its_apple_secrets(): void
    {
        $user = $this->makeUser('ada@example.com');

        $this->connect(
            $user,
            $this->identity('apple', 'a-1', 'Relay@PrivateRelay.AppleId.com', tenantId: null),
            refreshToken: 'r-plain',
            clientId: 'com.example.app',
        );

        $account = SocialAccount::query()->sole();
        $this->assertSame($user->getKey(), $account->user_id);
        $this->assertSame('apple', $account->provider);
        $this->assertSame('a-1', $account->provider_user_id);
        $this->assertSame('relay@privaterelay.appleid.com', $account->email_at_link);
        $this->assertSame('r-plain', $account->refresh_token);
        $this->assertSame('com.example.app', $account->client_id);
        $this->assertCounts(users: 1, accounts: 1, teams: 0);
    }

    public function test_reconnecting_the_identity_a_user_already_holds_returns_its_link(): void
    {
        $user = $this->makeUser('ada@example.com');
        $existing = $this->link($user, 'microsoft', 'm-1', [
            'revoked_at' => Carbon::now()->subDay(),
        ]);

        $account = $this->connect($user, $this->identity('microsoft', 'm-1', 'ada@example.com', tenantId: 't-1'));

        $this->assertSame($existing->getKey(), $account->getKey());
        $this->assertNull(SocialAccount::query()->sole()->revoked_at);
        $this->assertCounts(users: 1, accounts: 1, teams: 0);
    }

    private function resolve(VerifiedIdentity $identity, ?Request $request = null): Authenticatable
    {
        return $this->app->make(ResolvesSocialUsers::class)->resolve(
            $identity,
            $request ?? Request::create('/auth/social/' . $identity->provider, 'POST'),
        );
    }

    private function connect(
        ConcreteUser $user,
        VerifiedIdentity $identity,
        ?string $refreshToken = null,
        ?string $clientId = null,
    ): SocialAccount {
        $account = $this->app->make(ConnectsSocialAccounts::class)->connect(
            $user,
            $identity,
            $refreshToken,
            $clientId,
        );

        $this->assertInstanceOf(SocialAccount::class, $account);

        return $account;
    }

    /**
     * Run a call that must refuse, and hand back the refusal for inspection.
     *
     * A plain `expectException` would end the test at the throw, before the
     * row counts that prove the refusal wrote nothing.
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

    private function identity(
        string $provider,
        string $providerUserId,
        ?string $email,
        bool $verified = true,
        ?string $name = null,
        ?string $tenantId = null,
    ): VerifiedIdentity {
        return new VerifiedIdentity(
            provider: $provider,
            providerUserId: $providerUserId,
            email: $email,
            emailVerified: $verified,
            name: $name,
            tenantId: $tenantId,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function link(ConcreteUser $user, string $provider, string $providerUserId, array $attributes = []): SocialAccount
    {
        return SocialAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            ...$attributes,
        ]);
    }

    private function makeUser(string $email, ?string $password = null): ConcreteUser
    {
        return ConcreteUser::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Test User',
            'email' => $email,
            'password' => $password,
        ]);
    }

    private function assertCounts(int $users, int $accounts, int $teams): void
    {
        $this->assertSame(
            [
                'users' => $users,
                'social_accounts' => $accounts,
                'teams' => $teams,
            ],
            [
                'users' => DB::table('users')->count(),
                'social_accounts' => DB::table('social_accounts')->count(),
                'teams' => DB::table('teams')->count(),
            ],
        );
    }
}
