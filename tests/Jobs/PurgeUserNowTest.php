<?php

namespace FlutterSdk\MagicStarter\Tests\Jobs;

use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\Contracts\SchedulesUserDeletion;
use FlutterSdk\MagicStarter\Jobs\PurgeUserNow;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

/**
 * The job behind an immediate deletion runs the purge's own per-user decision
 * with a cutoff of now: re-read under lock, refusals at fire time, then
 * {@see DeletesUsers}. It is safe to run twice.
 */
class PurgeUserNowTest extends TestCase
{
    use RefreshDatabase;

    private PurgeUserNowTestDeleterSpy $deleter;

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(PurgeUserNowTestUser::class);

        config([
            'magic-starter.billing.billable' => 'team',
            'magic-starter.account_deletion.grace_days' => 30,
        ]);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
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

        $this->deleter = new PurgeUserNowTestDeleterSpy;
        $this->app->instance(DeletesUsers::class, $this->deleter);
    }

    public function test_the_job_is_queued_rather_than_run_in_the_request(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new PurgeUserNow('any'));
    }

    /**
     * A user scheduled a moment ago is due under a cutoff of now, so the grace
     * period does not hold the job back.
     */
    public function test_running_the_job_deletes_a_just_scheduled_user_through_deletes_users(): void
    {
        $user = $this->createUser('Leaving');
        $this->app->make(SchedulesUserDeletion::class)->schedule($user);

        PurgeUserNow::dispatchSync($user->getKey());

        $this->assertSame([$user->getKey()], $this->deleter->deleted);
        $this->assertNull(PurgeUserNowTestUser::query()->find($user->getKey()));
    }

    /**
     * QA: the second run finds no user and does nothing, without throwing.
     */
    public function test_a_second_run_for_the_same_user_is_a_no_op(): void
    {
        $user = $this->createUser('Twice');
        $this->app->make(SchedulesUserDeletion::class)->schedule($user);

        PurgeUserNow::dispatchSync($user->getKey());
        PurgeUserNow::dispatchSync($user->getKey());

        $this->assertSame([$user->getKey()], $this->deleter->deleted);
        $this->assertNull(PurgeUserNowTestUser::query()->find($user->getKey()));
    }

    /**
     * A schedule cleared before the job fires (a sign-in) is honoured: the row
     * is re-read with `deletion_scheduled_at` not null.
     */
    public function test_a_cancelled_schedule_is_not_purged(): void
    {
        $user = $this->createUser('Regret');
        $this->app->make(SchedulesUserDeletion::class)->schedule($user);
        $user->forceFill([
            'deletion_scheduled_at' => null,
        ])->save();

        PurgeUserNow::dispatchSync($user->getKey());

        $this->assertSame([], $this->deleter->deleted);
        $this->assertNotNull(PurgeUserNowTestUser::query()->find($user->getKey()));
    }

    /**
     * The purge's refusals run at fire time too: a team that gained a member
     * between the request and the job un-schedules the account.
     */
    public function test_a_team_that_gained_a_member_since_the_request_unschedules_instead(): void
    {
        $user = $this->createUser('Owner');
        $team = $this->createTeam($user, 'Solo');
        $this->app->make(SchedulesUserDeletion::class)->schedule($user);
        $team->users()->attach($this->createUser('Joiner')->getKey(), ['role' => 'member']);

        PurgeUserNow::dispatchSync($user->getKey());

        $this->assertSame([], $this->deleter->deleted);
        $this->assertNull($user->refresh()->deletion_scheduled_at);
    }

    private function createUser(string $name): PurgeUserNowTestUser
    {
        return PurgeUserNowTestUser::query()->create([
            'name' => $name,
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
class PurgeUserNowTestUser extends ConcreteUser
{
    use HasApiTokens;
}

/**
 * Records every user handed to the contract, then deletes the row.
 */
class PurgeUserNowTestDeleterSpy implements DeletesUsers
{
    /**
     * @var list<mixed>
     */
    public array $deleted = [];

    public function delete(Authenticatable $user): void
    {
        $this->deleted[] = $user->getAuthIdentifier();

        if ($user instanceof Model) {
            $user->delete();
        }
    }
}
