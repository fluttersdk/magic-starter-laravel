<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\DeleteUser;
use FlutterSdk\MagicStarter\Actions\SubscriptionGuardedDeleteTeam;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\SocialAccount;
use FlutterSdk\MagicStarter\Social\AppleProviderFactory;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;
use RuntimeException;

/**
 * The account-deletion action the purge runs: owned teams go through
 * `DeletesTeams` (so its billing guards run), never through the database
 * cascade, and a shared team is never deleted with its owner.
 */
class DeleteUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(DeleteUserTestUser::class);

        config([
            'magic-starter.billing.billable' => 'team',
            'magic-starter.social.audiences.apple' => ['com.example.app'],
            'magic-starter.social.apple' => [
                'team_id' => 'TEAM123456',
                'key_id' => 'KEY1234567',
                'private_key' => $this->privateKey(),
                'bundle_id' => 'com.example.app',
                'services_id' => 'com.example.web',
            ],
        ]);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('profile_photo_path')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('profile_photo_path')->nullable();
            $table->string('plan')->nullable();
            $table->string('plan_status')->nullable();
            $table->string('plan_provider')->nullable();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Schema::create('team_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('concrete_team_id');
            $table->string('email');
            $table->string('role')->nullable();
            $table->string('token');
            $table->timestamp('expires_at')->nullable();
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

        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('provider', 32);
            $table->string('provider_user_id');
            $table->string('tenant_id')->nullable();
            $table->string('email_at_link')->nullable();
            $table->string('client_id')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('user_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        // Cashier's subscription model eager-loads its items on every read.
        Schema::create('subscription_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->integer('quantity')->nullable();
            $table->timestamps();
        });
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The refresh token is an encrypted cast.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    public function test_the_contract_resolves_to_the_default_action(): void
    {
        $this->assertInstanceOf(DeleteUser::class, $this->app->make(DeletesUsers::class));
    }

    /**
     * A team someone else belongs to is never deleted with its owner, and the
     * refusal changes nothing at all: not the user, not the team, not a token.
     */
    public function test_a_user_who_still_owns_a_shared_team_is_refused_and_nothing_changes(): void
    {
        $user = $this->createUser('Owner');
        $team = $this->createTeam($user, 'Shared');
        $member = $this->createUser('Member');
        $team->users()->attach($member->getKey(), ['role' => 'member']);
        $user->createToken('device');

        try {
            $this->app->make(DeletesUsers::class)->delete($user);
            $this->fail('Expected the shared-team refusal.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('magic-starter::social.owns_shared_teams'),
                $exception->errors()['user'][0],
            );
        }

        $this->assertNotNull(DeleteUserTestUser::query()->find($user->getKey()));
        $this->assertNotNull(ConcreteTeam::query()->find($team->getKey()));
        $this->assertSame(2, DB::table('team_user')->where('team_id', $team->getKey())->count());
        $this->assertSame(1, $user->tokens()->count());
    }

    /**
     * Solo owned teams are deleted through the `DeletesTeams` contract, one call
     * per team, and memberships of other people's teams are detached without
     * touching those teams.
     */
    public function test_solo_teams_go_through_deletes_teams_and_memberships_are_detached(): void
    {
        $spy = new DeleteUserTestTeamDeleterSpy;
        $this->app->instance(DeletesTeams::class, $spy);

        $user = $this->createUser('Leaver');
        $personal = $this->createTeam($user, 'Personal', personal: true);
        $solo = $this->createTeam($user, 'Solo');

        $otherOwner = $this->createUser('Other Owner');
        $foreign = $this->createTeam($otherOwner, 'Foreign');
        $foreign->users()->attach($user->getKey(), ['role' => 'admin']);

        $user->createToken('device');

        $this->app->make(DeletesUsers::class)->delete($user);

        $this->assertEqualsCanonicalizing(
            [$personal->getKey(), $solo->getKey()],
            $spy->deleted,
        );
        $this->assertNull(ConcreteTeam::query()->find($personal->getKey()));
        $this->assertNull(ConcreteTeam::query()->find($solo->getKey()));
        $this->assertNotNull(ConcreteTeam::query()->find($foreign->getKey()));
        $this->assertSame(0, DB::table('team_user')->where('user_id', $user->getKey())->count());
        $this->assertSame(1, DB::table('team_user')->where('team_id', $foreign->getKey())->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertNull(DeleteUserTestUser::query()->find($user->getKey()));
    }

    /**
     * The billing guard on `DeletesTeams` holds the whole deletion back: the
     * refusal rolls back every team already deleted, and the user stays.
     */
    public function test_a_billing_refusal_on_a_solo_team_rolls_the_whole_deletion_back(): void
    {
        $user = $this->createUser('Payer');
        $free = $this->createTeam($user, 'A Free');
        $paid = $this->createTeam($user, 'B Paid');
        $paid->forceFill([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ])->save();

        $this->assertInstanceOf(SubscriptionGuardedDeleteTeam::class, $this->app->make(DeletesTeams::class));

        try {
            $this->app->make(DeletesUsers::class)->delete($user);
            $this->fail('Expected the store-billing refusal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('team', $exception->errors());
        }

        $this->assertNotNull(ConcreteTeam::query()->find($free->getKey()));
        $this->assertNotNull(ConcreteTeam::query()->find($paid->getKey()));
        $this->assertNotNull(DeleteUserTestUser::query()->find($user->getKey()));
    }

    /**
     * Each Apple grant is revoked with the refresh token stored on its row, as
     * App Store review requires, and every linked account row goes with the user.
     */
    public function test_apple_grants_are_revoked_with_the_stored_token_and_social_rows_are_deleted(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200),
        ]));
        $stack->push(Middleware::history($history));
        $this->app->make(AppleProviderFactory::class)->setHttpClient(new Client(['handler' => $stack]));

        $user = $this->createUser('Apple User');
        SocialAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'apple',
            'provider_user_id' => 'apple-sub-1',
            'client_id' => 'com.example.app',
            'refresh_token' => 'stored-apple-refresh-token',
        ]);
        SocialAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'google',
            'provider_user_id' => 'google-sub-1',
        ]);

        $this->app->make(DeletesUsers::class)->delete($user);

        $this->assertCount(1, $history, 'Only the Apple grant is revoked; Google has no revocation step.');
        parse_str((string) $history[0]['request']->getBody(), $form);
        $this->assertSame('https://appleid.apple.com/auth/revoke', (string) $history[0]['request']->getUri());
        $this->assertSame('stored-apple-refresh-token', $form['token']);
        $this->assertSame('com.example.app', $form['client_id']);

        $this->assertSame(0, DB::table('social_accounts')->count());
        $this->assertNull(DeleteUserTestUser::query()->find($user->getKey()));
    }

    /**
     * Apple's revocation is best effort: a failed call is reported by the
     * factory and the deletion still completes.
     */
    public function test_a_failed_apple_revocation_does_not_block_the_deletion(): void
    {
        $this->app->make(AppleProviderFactory::class)->setHttpClient(new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(500),
            ])),
        ]));

        $user = $this->createUser('Apple User');
        SocialAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'apple',
            'provider_user_id' => 'apple-sub-2',
            'client_id' => 'com.example.app',
            'refresh_token' => 'stored-apple-refresh-token',
        ]);

        $this->app->make(DeletesUsers::class)->delete($user);

        $this->assertNull(DeleteUserTestUser::query()->find($user->getKey()));
        $this->assertSame(0, DB::table('social_accounts')->count());
    }

    /**
     * The Apple grant and the profile photo cannot be rolled back, so they go
     * only once the deletion has committed: a failure at the final delete
     * leaves the grant unrevoked, the photo on disk and the rows in place.
     */
    public function test_a_failed_final_delete_revokes_nothing_and_keeps_the_photo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profile-photos/kept.jpg', 'photo');

        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200),
        ]));
        $stack->push(Middleware::history($history));
        $this->app->make(AppleProviderFactory::class)->setHttpClient(new Client(['handler' => $stack]));

        $user = $this->createUser('Apple User');
        $user->forceFill([
            'profile_photo_path' => 'profile-photos/kept.jpg',
        ])->save();
        SocialAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'apple',
            'provider_user_id' => 'apple-sub-3',
            'client_id' => 'com.example.app',
            'refresh_token' => 'stored-apple-refresh-token',
        ]);

        DeleteUserTestUser::deleting(function (): void {
            throw new RuntimeException('The database went away.');
        });

        try {
            $this->app->make(DeletesUsers::class)->delete($user);
            $this->fail('Expected the forced failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The database went away.', $exception->getMessage());
        }

        $this->assertSame([], $history, 'No grant is revoked for an account that still exists.');
        Storage::disk('public')->assertExists('profile-photos/kept.jpg');
        $this->assertSame(1, DB::table('social_accounts')->count());
        $this->assertNotNull(DeleteUserTestUser::query()->find($user->getKey()));
    }

    /**
     * Under user billing the user's own row is the billable: a valid Cashier
     * subscription refuses the deletion and nothing changes.
     */
    public function test_under_user_billing_a_subscribed_user_is_refused_and_nothing_changes(): void
    {
        MagicStarter::useUserModel(DeleteUserTestBillableUser::class);
        config([
            'magic-starter.billing.billable' => 'user',
        ]);

        $user = DeleteUserTestBillableUser::query()->create([
            'name' => 'Payer',
        ]);
        $team = $this->createTeam($user, 'Personal', personal: true);
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_payer',
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
            'trial_ends_at' => now()->addWeek(),
        ]);

        try {
            $this->app->make(DeletesUsers::class)->delete($user);
            $this->fail('Expected the user-level billing refusal.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('magic-starter::social.subscription_active'),
                $exception->errors()['user'][0],
            );
        }

        $this->assertNotNull(DeleteUserTestUser::query()->find($user->getKey()));
        $this->assertNotNull(ConcreteTeam::query()->find($team->getKey()));
    }

    /**
     * Under user billing a user nothing bills is deleted as usual.
     */
    public function test_under_user_billing_an_unsubscribed_user_is_deleted(): void
    {
        MagicStarter::useUserModel(DeleteUserTestBillableUser::class);
        config([
            'magic-starter.billing.billable' => 'user',
        ]);

        $user = DeleteUserTestBillableUser::query()->create([
            'name' => 'Free',
        ]);

        $this->app->make(DeletesUsers::class)->delete($user);

        $this->assertNull(DeleteUserTestUser::query()->find($user->getKey()));
    }

    /**
     * A consumer who never installed social login has no table to read; the
     * deletion must not assume one.
     */
    public function test_a_schema_without_social_accounts_still_deletes(): void
    {
        Schema::drop('social_accounts');

        $user = $this->createUser('Plain User');

        $this->app->make(DeletesUsers::class)->delete($user);

        $this->assertNull(DeleteUserTestUser::query()->find($user->getKey()));
    }

    private function createUser(string $name): DeleteUserTestUser
    {
        return DeleteUserTestUser::query()->create([
            'name' => $name,
        ]);
    }

    private function createTeam(Model $owner, string $name, bool $personal = false): ConcreteTeam
    {
        $team = ConcreteTeam::query()->forceCreate([
            'user_id' => $owner->getKey(),
            'name' => $name,
            'personal_team' => $personal,
        ]);
        $team->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $team;
    }

    private function privateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        openssl_pkey_export($key, $pem);

        return $pem;
    }
}

/**
 * A user that can hold Sanctum tokens, which `ConcreteUser` cannot.
 */
class DeleteUserTestUser extends ConcreteUser
{
    use HasApiTokens;
}

/**
 * A user Cashier bills, for the user-billing cases. The foreign key is pinned
 * because Cashier derives it from the class basename.
 */
class DeleteUserTestBillableUser extends DeleteUserTestUser
{
    use Billable;

    public function getForeignKey(): string
    {
        return 'user_id';
    }
}

/**
 * Records every team handed to the contract, then deletes it the real way.
 */
class DeleteUserTestTeamDeleterSpy implements DeletesTeams
{
    /**
     * @var list<mixed>
     */
    public array $deleted = [];

    public function delete(Model $team): void
    {
        $this->deleted[] = $team->getKey();

        (new SubscriptionGuardedDeleteTeam)->delete($team);
    }
}
