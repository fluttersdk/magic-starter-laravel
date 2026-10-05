<?php

namespace FlutterSdk\MagicStarter\Tests\Http;

use FlutterSdk\MagicStarter\Http\Controllers\ProfileController;
use FlutterSdk\MagicStarter\Jobs\PurgeUserNow;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

/**
 * `DELETE user` with `immediately: true` runs the scheduled path unchanged
 * (refusals, step-up, lock) and then queues the purge instead of waiting out
 * the grace period. Nothing is deleted inside the request.
 */
class ImmediateAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(ImmediateAccountDeletionTestUser::class);

        config([
            'auth.providers.users.model' => ImmediateAccountDeletionTestUser::class,
            'magic-starter.billing.billable' => 'team',
            'magic-starter.social.cache_store' => null,
        ]);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_guest')->default(false);
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

        Route::delete('/user', [ProfileController::class, 'destroy']);

        Queue::fake();
    }

    public function test_immediately_answers_202_and_queues_one_purge_for_the_user(): void
    {
        $user = $this->createUser('now@example.test');
        $user->createToken('device');

        $this->actingAs($user)
            ->deleteJson('/user', [
                'password' => 'Password123',
                'immediately' => true,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.immediate', true)
            ->assertJsonPath('message', __('magic-starter::social.deletion_immediate'));

        Queue::assertPushed(PurgeUserNow::class, 1);
        Queue::assertPushed(
            PurgeUserNow::class,
            fn (PurgeUserNow $job): bool => $job->userId === $user->getKey(),
        );

        $user = ImmediateAccountDeletionTestUser::query()->find($user->getKey());
        $this->assertNotNull($user, 'Nothing is deleted inside the request.');
        $this->assertNotNull($user->deletion_scheduled_at);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_immediate_answer_carries_the_scheduled_date(): void
    {
        $user = $this->createUser('now@example.test');

        $response = $this->actingAs($user)
            ->deleteJson('/user', [
                'password' => 'Password123',
                'immediately' => true,
            ])
            ->assertStatus(202);

        $this->assertNotNull($response->json('data.deletion_scheduled_at'));
    }

    /**
     * A caller that omits the flag, or sends it false, gets exactly the
     * scheduled answer it always got, and nothing is queued.
     */
    public function test_without_immediately_the_scheduled_answer_is_unchanged(): void
    {
        $first = $this->createUser('later@example.test');
        $second = $this->createUser('false@example.test');

        $omitted = $this->actingAs($first)
            ->deleteJson('/user', [
                'password' => 'Password123',
            ])
            ->assertStatus(202)
            ->assertJsonPath('message', __('magic-starter::social.deletion_scheduled', ['days' => 30]));

        $this->app['auth']->forgetGuards();

        $false = $this->actingAs($second)
            ->deleteJson('/user', [
                'password' => 'Password123',
                'immediately' => false,
            ])
            ->assertStatus(202)
            ->assertJsonPath('message', __('magic-starter::social.deletion_scheduled', ['days' => 30]));

        $this->assertSame(['deletion_scheduled_at'], array_keys($omitted->json('data')));
        $this->assertSame(['deletion_scheduled_at'], array_keys($false->json('data')));
        Queue::assertNothingPushed();
    }

    public function test_an_owner_of_a_shared_team_is_refused_and_nothing_is_queued(): void
    {
        $user = $this->createUser('owner@example.test');
        $team = $this->createTeam($user, 'Shared');
        $team->users()->attach($this->createUser('member@example.test')->getKey(), ['role' => 'member']);
        $user->createToken('device');

        $this->actingAs($user)
            ->deleteJson('/user', [
                'password' => 'Password123',
                'immediately' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'owns_shared_teams')
            ->assertJsonPath('team_ids', [(string) $team->getKey()]);

        Queue::assertNothingPushed();
        $this->assertNull($user->refresh()->deletion_scheduled_at);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_a_password_less_account_without_proof_must_step_up_and_nothing_is_queued(): void
    {
        $user = ImmediateAccountDeletionTestUser::query()->create([
            'name' => 'Social',
            'email' => 'social@example.test',
            'password' => null,
        ]);

        $this->actingAs($user)
            ->deleteJson('/user', [
                'immediately' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'step_up_required');

        Queue::assertNothingPushed();
        $this->assertNull($user->refresh()->deletion_scheduled_at);
    }

    public function test_a_non_boolean_immediately_is_refused_and_nothing_is_scheduled(): void
    {
        $user = $this->createUser('typo@example.test');

        $this->actingAs($user)
            ->deleteJson('/user', [
                'password' => 'Password123',
                'immediately' => 'soon',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['immediately']);

        Queue::assertNothingPushed();
        $this->assertNull($user->refresh()->deletion_scheduled_at);
    }

    private function createUser(string $email): ImmediateAccountDeletionTestUser
    {
        return ImmediateAccountDeletionTestUser::query()->create([
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
class ImmediateAccountDeletionTestUser extends ConcreteUser
{
    use HasApiTokens;
}
