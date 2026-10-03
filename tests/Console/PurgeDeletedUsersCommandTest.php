<?php

namespace FlutterSdk\MagicStarter\Tests\Console;

use FlutterSdk\MagicStarter\Actions\SubscriptionGuardedDeleteTeam;
use FlutterSdk\MagicStarter\Console\PurgeDeletedUsersCommand;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Contracts\DeletesUsers;
use FlutterSdk\MagicStarter\Contracts\SchedulesUserDeletion;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use RuntimeException;

/**
 * The purge is the only place an account is actually deleted, and it re-checks
 * everything at fire time: thirty days is long enough for a solo team to gain a
 * member or a subscription to start.
 */
class PurgeDeletedUsersCommandTest extends TestCase
{
    use RefreshDatabase;

    private PurgeDeletedUsersCommandTestTeamSpy $teamSpy;

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::useUserModel(PurgeDeletedUsersCommandTestUser::class);

        config([
            'magic-starter.billing.billable' => 'team',
            'magic-starter.account_deletion.grace_days' => 30,
        ]);

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('profile_photo_path')->nullable();
            $table->timestamp('deletion_scheduled_at')->nullable();
            $table->timestamp('orphaned_at')->nullable();
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

        $this->teamSpy = new PurgeDeletedUsersCommandTestTeamSpy;
        $this->app->instance(DeletesTeams::class, $this->teamSpy);
    }

    public function test_the_command_is_registered_under_its_name(): void
    {
        $this->assertSame('magic-starter:purge-deleted-users', PurgeDeletedUsersCommand::NAME);
        $this->assertArrayHasKey(PurgeDeletedUsersCommand::NAME, $this->app->make('Illuminate\Contracts\Console\Kernel')->all());
    }

    /**
     * Inside the grace period nothing is touched.
     */
    public function test_a_schedule_inside_the_grace_period_is_left_alone(): void
    {
        $user = $this->createUser('Waiting');
        $team = $this->createTeam($user, 'Personal');
        $this->schedule($user);

        $this->travel(29)->days();

        $this->artisan(PurgeDeletedUsersCommand::NAME)->assertSuccessful();

        $this->assertNotNull(PurgeDeletedUsersCommandTestUser::query()->find($user->getKey()));
        $this->assertNotNull(ConcreteTeam::query()->find($team->getKey()));
        $this->assertSame([], $this->teamSpy->deleted);
    }

    /**
     * Past the grace period a solo user is deleted through `DeletesUsers`, and
     * their solo team through `DeletesTeams`, never through the cascade.
     */
    public function test_a_solo_user_past_the_grace_period_is_deleted_with_their_solo_team(): void
    {
        $user = $this->createUser('Leaver');
        $team = $this->createTeam($user, 'Personal');
        $unscheduled = $this->createUser('Staying');
        $this->schedule($user);

        $this->travel(31)->days();

        $this->artisan(PurgeDeletedUsersCommand::NAME)->assertSuccessful();

        $this->assertNull(PurgeDeletedUsersCommandTestUser::query()->find($user->getKey()));
        $this->assertNull(ConcreteTeam::query()->find($team->getKey()));
        $this->assertSame([$team->getKey()], $this->teamSpy->deleted);
        $this->assertSame(0, DB::table('team_user')->where('user_id', $user->getKey())->count());
        $this->assertNotNull(PurgeDeletedUsersCommandTestUser::query()->find($unscheduled->getKey()));
    }

    /**
     * A user who asked, and whose team gained a member during the grace period,
     * is un-scheduled and reported rather than deleted with the team.
     */
    public function test_a_user_whose_team_became_shared_is_unscheduled_and_reported(): void
    {
        $user = $this->createUser('Owner');
        $team = $this->createTeam($user, 'Grew');
        $this->schedule($user);

        $team->users()->attach($this->createUser('Newcomer')->getKey(), ['role' => 'member']);

        $this->travel(31)->days();

        $this->artisan(PurgeDeletedUsersCommand::NAME)
            ->expectsOutputToContain('un-scheduled')
            ->assertSuccessful();

        $user->refresh();
        $this->assertNull($user->deletion_scheduled_at);
        $this->assertNotNull(ConcreteTeam::query()->find($team->getKey()));
        $this->assertSame(2, DB::table('team_user')->where('team_id', $team->getKey())->count());
        $this->assertSame([], $this->teamSpy->deleted);
    }

    /**
     * An orphan's shared team goes to the earliest-joined ADMIN, even when a
     * plain member joined earlier, and that admin becomes its owner.
     */
    public function test_an_orphans_shared_team_goes_to_the_oldest_admin(): void
    {
        $orphan = $this->createUser('Orphan');
        $team = $this->createTeam($orphan, 'Orphan Personal', personal: true);

        $earliestMember = $this->createUser('Earliest Member');
        $team->users()->attach($earliestMember->getKey(), ['role' => 'member']);

        $this->travel(1)->days();
        $oldestAdmin = $this->createUser('Oldest Admin');
        $team->users()->attach($oldestAdmin->getKey(), ['role' => 'admin']);

        $this->travel(1)->days();
        $newerAdmin = $this->createUser('Newer Admin');
        $team->users()->attach($newerAdmin->getKey(), ['role' => 'admin']);

        $this->schedule($orphan, orphan: true);

        $this->travel(31)->days();

        $this->artisan(PurgeDeletedUsersCommand::NAME)->assertSuccessful();

        $team = ConcreteTeam::query()->findOrFail($team->getKey());
        $this->assertSame($oldestAdmin->getKey(), $team->user_id);
        $this->assertFalse($team->personal_team, 'A handed-on team is nobody\'s personal team.');
        $this->assertSame('owner', $this->roleOf($team, $oldestAdmin));
        $this->assertSame('admin', $this->roleOf($team, $newerAdmin));
        $this->assertSame('member', $this->roleOf($team, $earliestMember));
        $this->assertSame(0, DB::table('team_user')->where('user_id', $orphan->getKey())->count());
        $this->assertNull(PurgeDeletedUsersCommandTestUser::query()->find($orphan->getKey()));
        $this->assertSame([], $this->teamSpy->deleted, 'A handed-on team is not deleted.');
    }

    /**
     * Without an admin the earliest-joined member inherits the team.
     */
    public function test_without_an_admin_the_oldest_member_inherits(): void
    {
        $orphan = $this->createUser('Orphan');
        $team = $this->createTeam($orphan, 'Shared');

        $oldest = $this->createUser('Oldest');
        $team->users()->attach($oldest->getKey(), ['role' => 'editor']);

        $this->travel(1)->days();
        $team->users()->attach($this->createUser('Newer')->getKey(), ['role' => 'member']);

        $this->schedule($orphan, orphan: true);

        $this->travel(31)->days();

        $this->artisan(PurgeDeletedUsersCommand::NAME)->assertSuccessful();

        $team = ConcreteTeam::query()->findOrFail($team->getKey());
        $this->assertSame($oldest->getKey(), $team->user_id);
        $this->assertSame('owner', $this->roleOf($team, $oldest));
        $this->assertNull(PurgeDeletedUsersCommandTestUser::query()->find($orphan->getKey()));
    }

    /**
     * An orphan with a team a subscription still bills is held and reported:
     * nothing is cancelled, transferred or deleted.
     */
    public function test_an_orphan_with_a_billing_team_is_held_and_reported(): void
    {
        $orphan = $this->createUser('Orphan');
        $paid = $this->createTeam($orphan, 'Paid');
        $paid->forceFill([
            'plan' => 'pro',
            'plan_status' => 'active',
            'plan_provider' => 'app_store',
        ])->save();
        $shared = $this->createTeam($orphan, 'Shared');
        $member = $this->createUser('Member');
        $shared->users()->attach($member->getKey(), ['role' => 'admin']);

        $this->schedule($orphan, orphan: true);

        $this->travel(31)->days();

        $this->artisan(PurgeDeletedUsersCommand::NAME)
            ->expectsOutputToContain('held')
            ->assertSuccessful();

        $this->assertNotNull(PurgeDeletedUsersCommandTestUser::query()->find($orphan->getKey()));
        $this->assertSame($orphan->getKey(), ConcreteTeam::query()->findOrFail($shared->getKey())->user_id);
        $this->assertSame('pro', ConcreteTeam::query()->findOrFail($paid->getKey())->plan);
        $this->assertNotNull($orphan->refresh()->deletion_scheduled_at, 'A held account stays scheduled.');
        $this->assertSame([], $this->teamSpy->deleted);
    }

    /**
     * One account failing is reported and does not stop the others; the run
     * exits non-zero so a scheduler notices.
     */
    public function test_one_failing_account_is_reported_and_the_rest_are_purged(): void
    {
        Exceptions::fake();

        $failing = $this->createUser('Failing');
        $fine = $this->createUser('Fine');
        $this->schedule($failing);
        $this->schedule($fine);

        $this->app->instance(DeletesUsers::class, new class($failing->getKey()) implements DeletesUsers
        {
            public function __construct(private string $failingKey) {}

            public function delete(Authenticatable $user): void
            {
                if ($user->getAuthIdentifier() === $this->failingKey) {
                    throw new RuntimeException('Storage is down.');
                }

                $user->delete();
            }
        });

        $this->travel(31)->days();

        $this->artisan(PurgeDeletedUsersCommand::NAME)->assertFailed();

        Exceptions::assertReported(RuntimeException::class);
        $this->assertNotNull(PurgeDeletedUsersCommandTestUser::query()->find($failing->getKey()));
        $this->assertNull(PurgeDeletedUsersCommandTestUser::query()->find($fine->getKey()));
    }

    /**
     * An application whose users table predates the deletion columns has
     * nothing scheduled, so the purge has nothing to do.
     */
    public function test_a_users_table_without_the_deletion_columns_purges_nothing(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['deletion_scheduled_at', 'orphaned_at']);
        });
        $user = $this->createUser('Legacy');

        $this->artisan(PurgeDeletedUsersCommand::NAME)->assertSuccessful();

        $this->assertNotNull(PurgeDeletedUsersCommandTestUser::query()->find($user->getKey()));
    }

    private function schedule(Model $user, bool $orphan = false): void
    {
        $this->app->make(SchedulesUserDeletion::class)->schedule($user, $orphan);
    }

    private function roleOf(Model $team, Model $user): ?string
    {
        return DB::table('team_user')
            ->where('team_id', $team->getKey())
            ->where('user_id', $user->getKey())
            ->value('role');
    }

    private function createUser(string $name): PurgeDeletedUsersCommandTestUser
    {
        return PurgeDeletedUsersCommandTestUser::query()->create([
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
}

/**
 * A user that can hold Sanctum tokens, which `ConcreteUser` cannot.
 */
class PurgeDeletedUsersCommandTestUser extends ConcreteUser
{
    use HasApiTokens;
}

/**
 * Records every team handed to the contract, then deletes it the real way.
 */
class PurgeDeletedUsersCommandTestTeamSpy implements DeletesTeams
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
