<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\ScheduleUserDeletion;
use FlutterSdk\MagicStarter\Contracts\SchedulesUserDeletion;
use FlutterSdk\MagicStarter\Http\Controllers\AuthController;
use FlutterSdk\MagicStarter\Http\Controllers\ProfileController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;
use RuntimeException;

/**
 * Scheduling an account deletion locks the account at once and deletes nothing:
 * tokens and push devices go, `deletion_scheduled_at` is stamped, and the purge
 * does the rest after the grace period. A sign-in during the grace period
 * cancels a deletion the user asked for.
 */
class ScheduleUserDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(ScheduleUserDeletionTestUser::class);

        config([
            'auth.providers.users.model' => ScheduleUserDeletionTestUser::class,
            'magic-starter.billing.billable' => 'team',
        ]);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('locale')->nullable();
            $table->string('timezone')->nullable();
            $table->uuid('current_team_id')->nullable();
            $table->string('plan')->nullable();
            $table->string('plan_status')->nullable();
            $table->string('plan_provider')->nullable();
            $table->timestamp('deletion_scheduled_at')->nullable();
            $table->timestamp('orphaned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
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

        Schema::create('push_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('external_id')->nullable();
            $table->string('subscription_id')->nullable();
            $table->string('reachability', 16);
            $table->timestamp('captured_at');
            $table->timestamp('reported_at');
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('user_id')->nullable();
            $table->uuid('team_id')->nullable();
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

        Route::post('/login', [AuthController::class, 'login']);
        Route::delete('/user', [ProfileController::class, 'destroy']);
    }

    public function test_the_contract_resolves_to_the_default_action(): void
    {
        $this->assertInstanceOf(ScheduleUserDeletion::class, $this->app->make(SchedulesUserDeletion::class));
    }

    /**
     * An owned team somebody else belongs to refuses with its stable code and the
     * ids of every such team, and the account is left exactly as it was.
     */
    public function test_a_shared_owned_team_refuses_and_changes_nothing(): void
    {
        $user = $this->createUser('owner@example.test');
        $shared = $this->createTeam($user, 'Shared');
        $this->createTeam($user, 'Solo');
        $shared->users()->attach($this->createUser('member@example.test')->getKey(), ['role' => 'member']);
        $user->createToken('device');
        $this->createPushDevice($user);

        $body = $this->refusalBody(fn () => $this->app->make(SchedulesUserDeletion::class)->schedule($user));

        $this->assertSame('owns_shared_teams', $body['code']);
        $this->assertSame(__('magic-starter::social.owns_shared_teams'), $body['message']);
        $this->assertSame([(string) $shared->getKey()], $body['team_ids']);
        $this->assertArrayNotHasKey('team_providers', $body);

        $user->refresh();
        $this->assertNull($user->deletion_scheduled_at);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(1, DB::table('push_devices')->count());
    }

    /**
     * The refusal names only what the customer can do here. The package serves
     * no ownership transfer, so a sentence asking for a hand-over sends them
     * looking for a screen that does not exist; the wording is magic_starter's.
     */
    public function test_the_shared_teams_refusal_asks_for_nothing_the_package_cannot_do(): void
    {
        $this->assertSame(
            'You own teams that other people belong to. '
            . 'Remove their members or delete those teams before deleting your account.',
            __('magic-starter::social.owns_shared_teams', [], 'en'),
        );
        $this->assertSame(
            'Başka kişilerin de üye olduğu takımların sahibisiniz. '
            . 'Hesabınızı silmeden önce üyeleri çıkarın veya bu takımları silin.',
            __('magic-starter::social.owns_shared_teams', [], 'tr'),
        );
    }

    /**
     * A solo team either rail is still billing refuses too: deleting it at the
     * purge would strand the charge, and nothing is cancelled for the customer.
     */
    public function test_a_billing_solo_team_refuses_with_its_own_code(): void
    {
        $user = $this->createUser('payer@example.test');
        $team = $this->createTeam($user, 'Paid');
        $team->forceFill([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'play_store',
        ])->save();

        $body = $this->refusalBody(fn () => $this->app->make(SchedulesUserDeletion::class)->schedule($user));

        $this->assertSame('team_has_active_subscription', $body['code']);
        $this->assertSame([(string) $team->getKey()], $body['team_ids']);
        $this->assertSame([(string) $team->getKey() => 'play_store'], $body['team_providers']);
        $this->assertNull($user->refresh()->deletion_scheduled_at);
        $this->assertSame('pro', $team->refresh()->plan, 'Nothing is cancelled on the customer\'s behalf.');
    }

    /**
     * A client tells a card-billed team from a store-billed one by the provider
     * the refusal names for each team, even when `plan_provider` was never
     * written because only Cashier's rows say who is billing.
     */
    public function test_a_stripe_billed_team_refusal_names_stripe_beside_a_store_billed_one(): void
    {
        MagicStarter::useTeamModel(ScheduleUserDeletionTestBillableTeam::class);

        $user = $this->createUser('mixed@example.test');
        $card = $this->createBillableTeam($user, 'Card');
        $card->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_' . bin2hex(random_bytes(6)),
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ]);
        $store = $this->createBillableTeam($user, 'Store');
        $store->forceFill([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ])->save();
        $this->createBillableTeam($user, 'Free');

        $body = $this->refusalBody(fn () => $this->app->make(SchedulesUserDeletion::class)->schedule($user));

        $this->assertSame('team_has_active_subscription', $body['code']);
        $this->assertEqualsCanonicalizing(
            [(string) $card->getKey(), (string) $store->getKey()],
            $body['team_ids'],
        );
        $this->assertSame(
            [
                (string) $card->getKey() => 'stripe',
                (string) $store->getKey() => 'app_store',
            ],
            $body['team_providers'],
        );
    }

    /**
     * Under user billing the money is on the user's own row: a valid Cashier
     * subscription refuses DELETE /user with the user-level code, and the
     * account keeps its tokens and its schedule stays empty.
     */
    public function test_under_user_billing_a_valid_stripe_subscription_refuses_delete_user(): void
    {
        $user = $this->useBillableUsers('card@example.test');
        $this->subscribe($user, 'active');
        $user->createToken('device');

        $this->actingAs($user)
            ->deleteJson('/user', ['password' => 'Password123'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'subscription_active')
            ->assertJsonPath('message', __('magic-starter::social.subscription_active'))
            ->assertJsonPath('team_ids', []);

        $user->refresh();
        $this->assertNull($user->deletion_scheduled_at);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('active', $user->subscriptions()->first()?->stripe_status);
    }

    /**
     * A store subscription on the user's row refuses the same way.
     */
    public function test_under_user_billing_a_store_billed_user_refuses(): void
    {
        $user = $this->useBillableUsers('store@example.test');
        $user->forceFill([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ])->save();

        $body = $this->refusalBody(fn () => $this->app->make(SchedulesUserDeletion::class)->schedule($user));

        $this->assertSame('subscription_active', $body['code']);
        $this->assertNull($user->refresh()->deletion_scheduled_at);
        $this->assertSame('pro', $user->plan, 'Nothing is cancelled on the customer\'s behalf.');
    }

    /**
     * A subscription Cashier no longer calls valid bills nobody, so it does not
     * stand in the way.
     */
    public function test_under_user_billing_an_ended_subscription_schedules(): void
    {
        $user = $this->useBillableUsers('ended@example.test');
        $this->subscribe($user, 'canceled', endsAt: now()->subDay());

        $this->app->make(SchedulesUserDeletion::class)->schedule($user);

        $this->assertNotNull($user->refresh()->deletion_scheduled_at);
    }

    /**
     * A solo user is locked out at once and deleted later: tokens and push
     * devices go now, the user and the team rows stay.
     */
    public function test_a_solo_user_is_locked_and_stamped_but_nothing_is_deleted(): void
    {
        Carbon::setTestNow('2026-10-03 12:00:00');

        $user = $this->createUser('solo@example.test');
        $team = $this->createTeam($user, 'Personal', personal: true);
        $user->createToken('phone');
        $user->createToken('laptop');
        $this->createPushDevice($user);
        $bystander = $this->createUser('bystander@example.test');
        $this->createPushDevice($bystander);

        $this->app->make(SchedulesUserDeletion::class)->schedule($user);

        $user->refresh();
        $this->assertSame('2026-10-03 12:00:00', Carbon::parse($user->deletion_scheduled_at)->toDateTimeString());
        $this->assertNull($user->orphaned_at);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, DB::table('push_devices')->where('user_id', $user->getKey())->count());
        $this->assertSame(1, DB::table('push_devices')->where('user_id', $bystander->getKey())->count());
        $this->assertNotNull(ConcreteTeam::query()->find($team->getKey()));
    }

    /**
     * An orphan (the identity provider deleted the account) is never refused:
     * there is nobody left to transfer a team or cancel a subscription, so the
     * purge decides what happens to their teams.
     */
    public function test_an_orphan_is_scheduled_despite_a_shared_team(): void
    {
        $user = $this->createUser('orphan@example.test');
        $shared = $this->createTeam($user, 'Shared');
        $shared->users()->attach($this->createUser('heir@example.test')->getKey(), ['role' => 'admin']);

        $this->app->make(SchedulesUserDeletion::class)->schedule($user, orphan: true);

        $user->refresh();
        $this->assertNotNull($user->deletion_scheduled_at);
        $this->assertNotNull($user->orphaned_at);
        $this->assertSame(2, DB::table('team_user')->where('team_id', $shared->getKey())->count());
    }

    /**
     * A second notification must not push the purge date back: the earliest
     * schedule stands.
     */
    public function test_scheduling_again_keeps_the_earliest_date(): void
    {
        Carbon::setTestNow('2026-10-01 08:00:00');
        $user = $this->createUser('twice@example.test');
        $this->app->make(SchedulesUserDeletion::class)->schedule($user, orphan: true);

        Carbon::setTestNow('2026-10-05 08:00:00');
        $this->app->make(SchedulesUserDeletion::class)->schedule($user->refresh(), orphan: true);

        $this->assertSame(
            '2026-10-01 08:00:00',
            Carbon::parse($user->refresh()->deletion_scheduled_at)->toDateTimeString(),
        );
    }

    /**
     * An application without the push-devices table still schedules.
     */
    public function test_a_schema_without_push_devices_still_schedules(): void
    {
        Schema::drop('push_devices');
        $user = $this->createUser('nopush@example.test');

        $this->app->make(SchedulesUserDeletion::class)->schedule($user);

        $this->assertNotNull($user->refresh()->deletion_scheduled_at);
    }

    /**
     * Without the deletion column a schedule could never be purged, so locking
     * the account would strand it forever. The action refuses loudly instead
     * and leaves the tokens alone.
     */
    public function test_a_users_table_without_the_deletion_column_refuses_before_locking_anything(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['deletion_scheduled_at', 'orphaned_at']);
        });
        $user = $this->createUser('legacy@example.test');
        $user->createToken('device');

        try {
            $this->app->make(SchedulesUserDeletion::class)->schedule($user);
            $this->fail('Expected the missing-column refusal.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('deletion_scheduled_at', $exception->getMessage());
        }

        $this->assertSame(1, $user->tokens()->count());
    }

    /**
     * Signing in during the grace period cancels a deletion the user asked for,
     * and the response says so.
     */
    public function test_a_sign_in_during_the_grace_period_cancels_the_deletion(): void
    {
        $user = $this->createUser('regret@example.test');
        $this->app->make(SchedulesUserDeletion::class)->schedule($user);

        $this->postJson('/login', [
            'email' => 'regret@example.test',
            'password' => 'Password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.deletion_cancelled', true)
            ->assertJsonPath('message', __('magic-starter::social.deletion_cancelled'));

        $this->assertNull($user->refresh()->deletion_scheduled_at);
        $this->assertSame(1, $user->tokens()->count());
    }

    /**
     * An orphan's schedule is not the user's to cancel: the provider deleted the
     * identity, so a sign-in by another method leaves it standing.
     */
    public function test_a_sign_in_does_not_cancel_an_orphan_schedule(): void
    {
        $user = $this->createUser('orphan@example.test');
        $this->app->make(SchedulesUserDeletion::class)->schedule($user, orphan: true);

        $response = $this->postJson('/login', [
            'email' => 'orphan@example.test',
            'password' => 'Password123',
        ])->assertOk();

        $this->assertArrayNotHasKey('deletion_cancelled', $response->json('data'));
        $this->assertNotNull($user->refresh()->deletion_scheduled_at);
    }

    /**
     * An ordinary sign-in carries no cancellation flag, on a schema without the
     * deletion columns too.
     */
    public function test_an_ordinary_sign_in_carries_no_cancellation_flag(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['deletion_scheduled_at', 'orphaned_at']);
        });
        $this->createUser('plain@example.test');

        $response = $this->postJson('/login', [
            'email' => 'plain@example.test',
            'password' => 'Password123',
        ])
            ->assertOk()
            ->assertJsonPath('message', __('magic-starter::auth.login_successful'));

        $this->assertArrayNotHasKey('deletion_cancelled', $response->json('data'));
    }

    /**
     * Run the scheduler expecting a refusal and return the 422 body it renders.
     *
     * @return array<string, mixed>
     */
    private function refusalBody(callable $schedule): array
    {
        try {
            $schedule();
        } catch (ValidationException $exception) {
            $this->assertInstanceOf(JsonResponse::class, $exception->response);
            $this->assertSame(422, $exception->response->getStatusCode());

            return $exception->response->getData(true);
        }

        $this->fail('Expected the scheduler to refuse.');
    }

    private function createUser(string $email): ScheduleUserDeletionTestUser
    {
        return ScheduleUserDeletionTestUser::query()->create([
            'name' => 'User',
            'email' => $email,
            'password' => Hash::make('Password123'),
        ]);
    }

    /**
     * Switch to user billing on a user model carrying Cashier's trait, and
     * create one user on it.
     */
    private function useBillableUsers(string $email): ScheduleUserDeletionTestBillableUser
    {
        MagicStarter::useUserModel(ScheduleUserDeletionTestBillableUser::class);

        config([
            'auth.providers.users.model' => ScheduleUserDeletionTestBillableUser::class,
            'magic-starter.billing.billable' => 'user',
        ]);

        return ScheduleUserDeletionTestBillableUser::query()->create([
            'name' => 'User',
            'email' => $email,
            'password' => Hash::make('Password123'),
        ]);
    }

    private function subscribe(
        ScheduleUserDeletionTestBillableUser $user,
        string $status,
        ?Carbon $endsAt = null,
    ): void {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_' . bin2hex(random_bytes(6)),
            'stripe_status' => $status,
            'stripe_price' => 'price_pro',
            'quantity' => 1,
            'ends_at' => $endsAt,
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

    private function createBillableTeam(Model $owner, string $name): ScheduleUserDeletionTestBillableTeam
    {
        $team = ScheduleUserDeletionTestBillableTeam::query()->forceCreate([
            'user_id' => $owner->getKey(),
            'name' => $name,
            'personal_team' => false,
        ]);
        $team->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $team;
    }

    private function createPushDevice(Model $user): void
    {
        DB::table('push_devices')->insert([
            'id' => (string) str()->uuid(),
            'user_id' => $user->getKey(),
            'external_id' => 'user_' . $user->getKey(),
            'subscription_id' => (string) str()->uuid(),
            'reachability' => 'on',
            'captured_at' => now(),
            'reported_at' => now(),
        ]);
    }
}

/**
 * A user that can hold Sanctum tokens, which `ConcreteUser` cannot.
 */
class ScheduleUserDeletionTestUser extends ConcreteUser
{
    use HasApiTokens;
}

/**
 * A user Cashier bills, for the user-billing cases. The foreign key is pinned
 * because Cashier derives it from the class basename.
 */
class ScheduleUserDeletionTestBillableUser extends ScheduleUserDeletionTestUser
{
    use Billable;

    public function getForeignKey(): string
    {
        return 'user_id';
    }
}

/**
 * A team Cashier bills, for the team-billing cases. The foreign key is pinned
 * because Cashier derives it from the class basename.
 */
class ScheduleUserDeletionTestBillableTeam extends ConcreteTeam
{
    use Billable;

    public function getForeignKey(): string
    {
        return 'team_id';
    }
}
