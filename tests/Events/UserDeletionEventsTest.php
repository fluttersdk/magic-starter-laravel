<?php

namespace FlutterSdk\MagicStarter\Tests\Events;

use FlutterSdk\MagicStarter\Console\PurgeDeletedUsersCommand;
use FlutterSdk\MagicStarter\Contracts\SchedulesUserDeletion;
use FlutterSdk\MagicStarter\Events\UserDeletionCancelled;
use FlutterSdk\MagicStarter\Events\UserDeletionScheduled;
use FlutterSdk\MagicStarter\Http\Controllers\AuthController;
use FlutterSdk\MagicStarter\Http\Controllers\ProfileController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;

/**
 * The deletion lifecycle events a host listens to so it can pause its own
 * resources when an account is scheduled and resume them when the schedule is
 * cleared.
 */
class UserDeletionEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(UserDeletionEventsTestUser::class);

        config([
            'auth.providers.users.model' => UserDeletionEventsTestUser::class,
            'magic-starter.billing.billable' => 'team',
            'magic-starter.account_deletion.grace_days' => 30,
        ]);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('locale')->nullable();
            $table->string('timezone')->nullable();
            $table->uuid('current_team_id')->nullable();
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

        Route::post('/login', [AuthController::class, 'login']);
        Route::delete('/user', [ProfileController::class, 'destroy']);

        // Only the lifecycle events: the models' own events still generate keys.
        Event::fake([
            UserDeletionScheduled::class,
            UserDeletionCancelled::class,
        ]);
    }

    public function test_a_scheduled_deletion_dispatches_scheduled_with_immediate_false(): void
    {
        $user = $this->createUser('later@example.test');

        $this->actingAs($user)
            ->deleteJson('/user', [
                'password' => 'Password123',
            ])
            ->assertStatus(202);

        Event::assertDispatchedTimes(UserDeletionScheduled::class, 1);
        Event::assertDispatched(
            UserDeletionScheduled::class,
            fn (UserDeletionScheduled $event): bool => $event->user->getAuthIdentifier() === $user->getKey()
                && $event->immediate === false
                && $event->user->getAttribute('deletion_scheduled_at') !== null,
        );
    }

    public function test_an_immediate_deletion_dispatches_scheduled_with_immediate_true(): void
    {
        Queue::fake();
        $user = $this->createUser('now@example.test');

        $this->actingAs($user)
            ->deleteJson('/user', [
                'password' => 'Password123',
                'immediately' => true,
            ])
            ->assertStatus(202);

        Event::assertDispatched(
            UserDeletionScheduled::class,
            fn (UserDeletionScheduled $event): bool => $event->user->getAuthIdentifier() === $user->getKey()
                && $event->immediate === true,
        );
    }

    public function test_an_orphan_schedule_dispatches_scheduled_too(): void
    {
        $user = $this->createUser('orphan@example.test');

        $this->app->make(SchedulesUserDeletion::class)->schedule($user, orphan: true);

        Event::assertDispatched(
            UserDeletionScheduled::class,
            fn (UserDeletionScheduled $event): bool => $event->user->getAuthIdentifier() === $user->getKey()
                && $event->immediate === false,
        );
    }

    public function test_a_refused_schedule_dispatches_nothing(): void
    {
        $user = $this->createUser('owner@example.test');
        $team = $this->createTeam($user, 'Shared');
        $team->users()->attach($this->createUser('member@example.test')->getKey(), ['role' => 'member']);

        try {
            $this->app->make(SchedulesUserDeletion::class)->schedule($user);
            $this->fail('Expected the scheduler to refuse.');
        } catch (ValidationException) {
            Event::assertNotDispatched(UserDeletionScheduled::class);
        }
    }

    public function test_a_sign_in_that_cancels_the_deletion_dispatches_cancelled(): void
    {
        $user = $this->createUser('regret@example.test');
        $this->app->make(SchedulesUserDeletion::class)->schedule($user);

        $this->postJson('/login', [
            'email' => 'regret@example.test',
            'password' => 'Password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.deletion_cancelled', true);

        Event::assertDispatchedTimes(UserDeletionCancelled::class, 1);
        Event::assertDispatched(
            UserDeletionCancelled::class,
            fn (UserDeletionCancelled $event): bool => $event->user->getAuthIdentifier() === $user->getKey()
                && $event->user->getAttribute('deletion_scheduled_at') === null,
        );
    }

    public function test_an_ordinary_sign_in_dispatches_no_cancellation(): void
    {
        $this->createUser('plain@example.test');

        $this->postJson('/login', [
            'email' => 'plain@example.test',
            'password' => 'Password123',
        ])->assertOk();

        Event::assertNotDispatched(UserDeletionCancelled::class);
    }

    public function test_a_sign_in_on_an_orphan_dispatches_no_cancellation(): void
    {
        $user = $this->createUser('orphan@example.test');
        $this->app->make(SchedulesUserDeletion::class)->schedule($user, orphan: true);

        $this->postJson('/login', [
            'email' => 'orphan@example.test',
            'password' => 'Password123',
        ])->assertOk();

        Event::assertNotDispatched(UserDeletionCancelled::class);
    }

    /**
     * The purge clearing a schedule is a cancellation as well: without the
     * event a host would keep the account's resources paused for good.
     */
    public function test_the_purge_unscheduling_an_account_dispatches_cancelled(): void
    {
        $user = $this->createUser('owner@example.test');
        $team = $this->createTeam($user, 'Solo');
        $this->app->make(SchedulesUserDeletion::class)->schedule($user);
        $team->users()->attach($this->createUser('joiner@example.test')->getKey(), ['role' => 'member']);
        $this->travel(31)->days();

        $this->artisan(PurgeDeletedUsersCommand::NAME)->assertSuccessful();

        $this->assertNull($user->refresh()->deletion_scheduled_at);
        Event::assertDispatched(
            UserDeletionCancelled::class,
            fn (UserDeletionCancelled $event): bool => $event->user->getAuthIdentifier() === $user->getKey(),
        );
    }

    private function createUser(string $email): UserDeletionEventsTestUser
    {
        return UserDeletionEventsTestUser::query()->create([
            'name' => 'User',
            'email' => $email,
            'password' => Hash::make('Password123'),
        ]);
    }

    private function createTeam(Model $owner, string $name): ConcreteTeam
    {
        $team = ConcreteTeam::query()->forceCreate([
            'user_id' => $owner->getKey(),
            'name' => $name,
        ]);
        $team->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $team;
    }
}

/**
 * A user that can hold Sanctum tokens, which `ConcreteUser` cannot.
 */
class UserDeletionEventsTestUser extends ConcreteUser
{
    use HasApiTokens;
}
