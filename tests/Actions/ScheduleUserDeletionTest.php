<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\ScheduleUserDeletion;
use FlutterSdk\MagicStarter\Contracts\SchedulesUserDeletion;
use FlutterSdk\MagicStarter\Http\Controllers\AuthController;
use FlutterSdk\MagicStarter\Http\Controllers\ProfileController;
use FlutterSdk\MagicStarter\Jobs\PurgeUserNow;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\Support\StripeHttpStub;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
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

    protected function tearDown(): void
    {
        StripeHttpStub::uninstall();

        parent::tearDown();
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
     * Handing a shared team on is the host's choice, so a package that is not
     * configured for it keeps refusing exactly as before.
     */
    public function test_handing_on_shared_teams_is_off_by_default(): void
    {
        $this->assertFalse(config('magic-starter.account_deletion.hand_on_shared_teams'));
    }

    /**
     * With hand-on on, a shared owned team is no reason to refuse: the account
     * is locked and stamped, and the team keeps its owner and its members until
     * the purge hands it on.
     */
    public function test_a_shared_owned_team_schedules_when_shared_teams_are_handed_on(): void
    {
        config([
            'magic-starter.account_deletion.hand_on_shared_teams' => true,
        ]);

        $user = $this->createUser('owner@example.test');
        $shared = $this->createTeam($user, 'Shared');
        $solo = $this->createTeam($user, 'Solo');
        $shared->users()->attach($this->createUser('member@example.test')->getKey(), ['role' => 'member']);
        $user->createToken('device');
        $this->createPushDevice($user);

        $this->app->make(SchedulesUserDeletion::class)->schedule($user);

        $user->refresh();
        $this->assertNotNull($user->deletion_scheduled_at);
        $this->assertNull($user->orphaned_at);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, DB::table('push_devices')->where('user_id', $user->getKey())->count());
        $this->assertSame($user->getKey(), $shared->refresh()->user_id, 'Nothing is handed on before the purge.');
        $this->assertSame(2, DB::table('team_user')->where('team_id', $shared->getKey())->count());
        $this->assertNotNull(ConcreteTeam::query()->find($solo->getKey()));
    }

    /**
     * Hand-on moves ownership, never money: a shared team a subscription is
     * still billing refuses with the billing code, and the account stays whole.
     */
    public function test_a_billing_shared_team_still_refuses_when_shared_teams_are_handed_on(): void
    {
        config([
            'magic-starter.account_deletion.hand_on_shared_teams' => true,
        ]);

        $user = $this->createUser('payer@example.test');
        $shared = $this->createTeam($user, 'Shared and paid');
        $shared->forceFill([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ])->save();
        $shared->users()->attach($this->createUser('member@example.test')->getKey(), ['role' => 'member']);
        $user->createToken('device');

        $body = $this->refusalBody(fn () => $this->app->make(SchedulesUserDeletion::class)->schedule($user));

        $this->assertSame('team_has_active_subscription', $body['code']);
        $this->assertSame([(string) $shared->getKey()], $body['team_ids']);
        $this->assertSame([(string) $shared->getKey() => 'app_store'], $body['team_providers']);

        $user->refresh();
        $this->assertNull($user->deletion_scheduled_at);
        $this->assertSame(1, $user->tokens()->count());
    }

    /**
     * The endpoint a client calls answers 202 for an owner of a shared team
     * when hand-on is on, where it answers 422 `owns_shared_teams` otherwise.
     */
    public function test_delete_user_answers_202_for_a_shared_owner_when_shared_teams_are_handed_on(): void
    {
        $user = $this->createUser('owner@example.test');
        $shared = $this->createTeam($user, 'Shared');
        $shared->users()->attach($this->createUser('member@example.test')->getKey(), ['role' => 'member']);

        $this->actingAs($user)
            ->deleteJson('/user', ['password' => 'Password123'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'owns_shared_teams');

        config([
            'magic-starter.account_deletion.hand_on_shared_teams' => true,
        ]);

        $this->actingAs($user)
            ->deleteJson('/user', ['password' => 'Password123'])
            ->assertStatus(202)
            ->assertJsonStructure(['data' => ['deletion_scheduled_at'], 'message']);

        $this->assertNotNull($user->refresh()->deletion_scheduled_at);
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
     * Refusing is the default, so a host that never opted in keeps the
     * behaviour it always had.
     */
    public function test_billing_is_refused_rather_than_released_by_default(): void
    {
        $this->assertSame('refuse', config('magic-starter.account_deletion.billing'));
    }

    /**
     * In refuse mode a subscription refuses before anything is asked of Stripe:
     * nothing is cancelled for the customer, and a user nothing bills schedules
     * with an empty report and no `billing` key in the 202 body.
     */
    public function test_refuse_mode_never_asks_stripe_and_leaves_the_response_unchanged(): void
    {
        $stripe = StripeHttpStub::install();
        $payer = $this->useBillableUsers('payer@example.test');
        $this->subscribe($payer, 'active');

        $this->actingAs($payer)
            ->deleteJson('/user', ['password' => 'Password123'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'subscription_active');

        $this->assertSame([], $stripe->requests);

        $free = ScheduleUserDeletionTestBillableUser::query()->create([
            'name' => 'User',
            'email' => 'free@example.test',
            'password' => Hash::make('Password123'),
        ]);

        $response = $this->actingAs($free)
            ->deleteJson('/user', ['password' => 'Password123'])
            ->assertStatus(202);

        $this->assertSame(['deletion_scheduled_at'], array_keys($response->json('data')));
        $this->assertSame($this->nothingReleased(), $this->app->make(SchedulesUserDeletion::class)->schedule($free));
    }

    /**
     * A value this build does not know is a typo in the host's config, and
     * guessing which way it meant would either strand a charge or cancel one
     * nobody asked for. It stops before anything is locked.
     */
    public function test_an_unknown_billing_mode_is_refused_before_anything_is_locked(): void
    {
        config([
            'magic-starter.account_deletion.billing' => 'cancel',
        ]);
        $user = $this->createUser('typo@example.test');
        $user->createToken('device');

        try {
            $this->app->make(SchedulesUserDeletion::class)->schedule($user);
            $this->fail('Expected the unknown mode to be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('account_deletion.billing', $exception->getMessage());
        }

        $this->assertNull($user->refresh()->deletion_scheduled_at);
        $this->assertSame(1, $user->tokens()->count());
    }

    /**
     * Releasing ends the Stripe subscription at once, with no proration and no
     * final invoice, then locks and stamps the account as usual. The remaining
     * period is not refunded.
     */
    public function test_release_mode_cancels_a_stripe_subscription_without_proration_and_schedules(): void
    {
        $stripe = $this->releaseBilling();
        $user = $this->useBillableUsers('card@example.test');
        $this->subscribe($user, 'active', id: 'sub_card');
        $user->createToken('device');
        $stripe->answer($this->canceledSubscription('sub_card'));

        $report = $this->app->make(SchedulesUserDeletion::class)->schedule($user);

        $deletes = $stripe->requestsTo('delete', '/v1/subscriptions/sub_card');
        $this->assertCount(1, $deletes);
        $this->assertSame('false', $deletes[0]['params']['prorate']);
        $this->assertSame('false', $deletes[0]['params']['invoice_now']);
        $this->assertSame([
            'cancelled' => [
                [
                    'provider' => 'stripe',
                    'id' => 'sub_card',
                ],
            ],
            'store' => [],
        ], $report);

        $user->refresh();
        $this->assertNotNull($user->deletion_scheduled_at);
        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * The 202 body tells a client what happened to the billing, so it can say
     * the web subscription ended.
     */
    public function test_release_mode_reports_a_cancelled_stripe_subscription_in_the_202_body(): void
    {
        $stripe = $this->releaseBilling();
        $user = $this->useBillableUsers('card@example.test');
        $this->subscribe($user, 'active', id: 'sub_card');
        $stripe->answer($this->canceledSubscription('sub_card'));

        $this->actingAs($user)
            ->deleteJson('/user', ['password' => 'Password123'])
            ->assertStatus(202)
            ->assertJsonPath('data.billing.cancelled', [
                [
                    'provider' => 'stripe',
                    'id' => 'sub_card',
                ],
            ])
            ->assertJsonPath('data.billing.store', [])
            ->assertJsonPath('data.deletion_scheduled_at', fn (mixed $stamp): bool => $stamp !== null);
    }

    /**
     * Only the store can cancel a store subscription, so it does not stand in
     * the way: the account is scheduled, nothing is asked of Stripe, and the
     * body names the store so the client can send the person to its cancel
     * screen.
     */
    public function test_release_mode_reports_a_store_subscription_and_schedules(): void
    {
        $stripe = $this->releaseBilling();
        $user = $this->useBillableUsers('store@example.test');
        $user->forceFill([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ])->save();
        $user->createToken('device');

        $this->actingAs($user)
            ->deleteJson('/user', ['password' => 'Password123'])
            ->assertStatus(202)
            ->assertJsonPath('data.billing.cancelled', [])
            ->assertJsonPath('data.billing.store', [
                [
                    'provider' => 'app_store',
                    'manage_url' => null,
                ],
            ]);

        $this->assertSame([], $stripe->requests);
        $user->refresh();
        $this->assertNotNull($user->deletion_scheduled_at);
        $this->assertSame('pro', $user->plan, 'The entitlement is the store\'s to end.');
    }

    /**
     * A user nothing bills still gets the block, empty, so a client in release
     * mode can rely on the key.
     */
    public function test_release_mode_answers_an_empty_block_when_nothing_bills_the_user(): void
    {
        $this->releaseBilling();
        $user = $this->useBillableUsers('free@example.test');

        $this->actingAs($user)
            ->deleteJson('/user', ['password' => 'Password123'])
            ->assertStatus(202)
            ->assertJsonPath('data.billing', $this->nothingReleased());
    }

    /**
     * The immediate answer carries the block too.
     */
    public function test_release_mode_reports_billing_on_the_immediate_answer(): void
    {
        Queue::fake();
        $stripe = $this->releaseBilling();
        $user = $this->useBillableUsers('card@example.test');
        $this->subscribe($user, 'active', id: 'sub_card');
        $stripe->answer($this->canceledSubscription('sub_card'));

        $this->actingAs($user)
            ->deleteJson('/user', ['password' => 'Password123', 'immediately' => true])
            ->assertStatus(202)
            ->assertJsonPath('data.immediate', true)
            ->assertJsonPath('data.billing.cancelled.0.id', 'sub_card');

        Queue::assertPushed(PurgeUserNow::class);
    }

    /**
     * A Stripe failure is a refusal, never a half-done deletion: the account is
     * not locked, not stamped, and keeps its tokens, so the person can try
     * again.
     */
    public function test_release_mode_refuses_when_stripe_fails_and_schedules_nothing(): void
    {
        $stripe = $this->releaseBilling();
        $user = $this->useBillableUsers('card@example.test');
        $this->subscribe($user, 'active', id: 'sub_card');
        $user->createToken('device');
        $this->createPushDevice($user);

        $this->actingAs($user)
            ->deleteJson('/user', ['password' => 'Password123'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'billing_release_failed')
            ->assertJsonPath('message', __('magic-starter::social.billing_release_failed'))
            ->assertJsonPath('team_ids', [])
            ->assertJsonMissingPath('data');

        $this->assertCount(1, $stripe->requestsTo('delete', '/v1/subscriptions/sub_card'));
        $user->refresh();
        $this->assertNull($user->deletion_scheduled_at);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(1, DB::table('push_devices')->where('user_id', $user->getKey())->count());
    }

    /**
     * What only the user can resolve is checked before anything remote runs, so
     * a refusal never leaves a subscription cancelled behind it.
     */
    public function test_release_mode_refuses_a_shared_team_before_asking_stripe(): void
    {
        $stripe = $this->releaseBilling();
        $user = $this->useBillableUsers('owner@example.test');
        $this->subscribe($user, 'active', id: 'sub_card');
        $shared = $this->createTeam($user, 'Shared');
        $shared->users()->attach($this->createUser('member@example.test')->getKey(), ['role' => 'member']);

        $body = $this->refusalBody(fn () => $this->app->make(SchedulesUserDeletion::class)->schedule($user));

        $this->assertSame('owns_shared_teams', $body['code']);
        $this->assertSame([], $stripe->requests);
        $this->assertNull($user->refresh()->deletion_scheduled_at);
    }

    /**
     * Releasing is about the user's own subscription. A team a store or Stripe
     * bills is still the owner's to settle, as it was.
     */
    public function test_release_mode_still_refuses_a_billing_team(): void
    {
        $stripe = $this->releaseBilling();
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
        $this->assertSame([], $stripe->requests);
        $this->assertNull($user->refresh()->deletion_scheduled_at);
    }

    /**
     * An orphan is never refused, and nobody is left to cancel for them, so a
     * subscription is released for them too: the purge will not hold the
     * account back for it.
     */
    public function test_release_mode_releases_an_orphans_subscription(): void
    {
        $stripe = $this->releaseBilling();
        $orphan = $this->useBillableUsers('orphan@example.test');
        $this->subscribe($orphan, 'active', id: 'sub_orphan');
        $stripe->answer($this->canceledSubscription('sub_orphan'));

        $report = $this->app->make(SchedulesUserDeletion::class)->schedule($orphan, orphan: true);

        $this->assertSame('sub_orphan', $report['cancelled'][0]['id']);
        $orphan->refresh();
        $this->assertNotNull($orphan->deletion_scheduled_at);
        $this->assertNotNull($orphan->orphaned_at);
    }

    /**
     * A Stripe failure stops an orphan's schedule too, so the provider's
     * notification fails and is delivered again rather than the account being
     * scheduled with its subscription still charging.
     */
    public function test_release_mode_does_not_schedule_an_orphan_when_stripe_fails(): void
    {
        $this->releaseBilling();
        $orphan = $this->useBillableUsers('orphan@example.test');
        $this->subscribe($orphan, 'active', id: 'sub_orphan');

        $body = $this->refusalBody(
            fn () => $this->app->make(SchedulesUserDeletion::class)->schedule($orphan, orphan: true),
        );

        $this->assertSame('billing_release_failed', $body['code']);
        $this->assertNull($orphan->refresh()->deletion_scheduled_at);
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

    /**
     * Switch the host to releasing billing and put a Stripe transport stub in
     * front of the SDK.
     */
    private function releaseBilling(): StripeHttpStub
    {
        config([
            'magic-starter.account_deletion.billing' => 'release',
            'cashier.secret' => 'sk_test_schedule_user_deletion',
        ]);

        return StripeHttpStub::install();
    }

    /**
     * @return array<string, mixed>
     */
    private function canceledSubscription(string $id): array
    {
        return [
            'id' => $id,
            'object' => 'subscription',
            'customer' => 'cus_leaver',
            'status' => 'canceled',
        ];
    }

    /**
     * @return array{cancelled: list<mixed>, store: list<mixed>}
     */
    private function nothingReleased(): array
    {
        return [
            'cancelled' => [],
            'store' => [],
        ];
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
        ?string $id = null,
    ): void {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => $id ?? 'sub_' . bin2hex(random_bytes(6)),
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
