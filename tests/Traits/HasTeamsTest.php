<?php

namespace FlutterSdk\MagicStarter\Tests\Traits;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class HasTeamsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset $using so config-based resolution works in this test class.
        MagicStarter::reset();

        config(['database.default' => 'testing']);
        config(['database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        Schema::create('users', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('device_id')->unique()->nullable();
            $table->string('phone')->nullable();
            $table->char('phone_country', 2)->nullable();
            $table->string('current_team_id')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->string('id')->nullable();
            $table->string('team_id');
            $table->string('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        config([
            'magic-starter.models.team' => HasTeamsTestTeam::class,
            'magic-starter.models.membership' => \FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser::class,
        ]);
    }

    public function test_owned_teams_uses_magic_starter_team_model_resolution(): void
    {
        $user = new HasTeamsTestUser;

        $this->assertSame(HasTeamsTestTeam::class, $user->ownedTeams()->getRelated()::class);
    }

    public function test_teams_relationship_resolves_membership_records(): void
    {
        $user = HasTeamsTestUser::query()->create(['id' => 'u-1', 'name' => 'Alice']);
        $teamA = HasTeamsTestTeam::query()->create(['id' => 't-1', 'user_id' => 'u-1', 'name' => 'Zulu', 'personal_team' => false]);
        $teamB = HasTeamsTestTeam::query()->create(['id' => 't-2', 'user_id' => 'u-2', 'name' => 'Alpha', 'personal_team' => false]);

        $user->teams()->attach($teamA->getKey(), ['role' => 'admin']);
        $user->teams()->attach($teamB->getKey(), ['role' => 'editor']);

        $this->assertSame(['Alpha', 'Zulu'], $user->teams->sortBy('name')->pluck('name')->values()->all());
    }

    public function test_personal_team_returns_owned_team_marked_personal(): void
    {
        $user = HasTeamsTestUser::query()->create(['id' => 'u-3', 'name' => 'Bob']);
        HasTeamsTestTeam::query()->create(['id' => 't-3', 'user_id' => 'u-3', 'name' => 'Work', 'personal_team' => false]);
        $personal = HasTeamsTestTeam::query()->create(['id' => 't-4', 'user_id' => 'u-3', 'name' => 'Personal', 'personal_team' => true]);

        $this->assertSame($personal->getKey(), $user->fresh()->personalTeam()?->getKey());
    }

    public function test_current_team_relation_uses_current_team_id_foreign_key(): void
    {
        $team = HasTeamsTestTeam::query()->create(['id' => 't-5', 'user_id' => 'u-4', 'name' => 'Current', 'personal_team' => false]);
        $user = HasTeamsTestUser::query()->create(['id' => 'u-4', 'name' => 'Carol', 'current_team_id' => 't-5']);

        $this->assertSame($team->getKey(), $user->currentTeam?->getKey());
    }

    public function test_all_teams_returns_owned_and_member_teams_sorted_by_name(): void
    {
        $user = HasTeamsTestUser::query()->create(['id' => 'u-5', 'name' => 'Dave']);
        $owned = HasTeamsTestTeam::query()->create(['id' => 't-6', 'user_id' => 'u-5', 'name' => 'Bravo', 'personal_team' => false]);
        $member = HasTeamsTestTeam::query()->create(['id' => 't-7', 'user_id' => 'u-6', 'name' => 'Alpha', 'personal_team' => false]);
        $user->teams()->attach($member->getKey(), ['role' => 'member']);

        $this->assertSame(['Alpha', 'Bravo'], $user->allTeams()->pluck('name')->values()->all());
        $this->assertTrue($user->allTeams()->contains('id', $owned->getKey()));
    }

    public function test_get_current_team_or_personal_prefers_current_team_then_personal_fallback(): void
    {
        $personal = HasTeamsTestTeam::query()->create(['id' => 't-8', 'user_id' => 'u-7', 'name' => 'Personal', 'personal_team' => true]);
        $current = HasTeamsTestTeam::query()->create(['id' => 't-9', 'user_id' => 'u-7', 'name' => 'Current', 'personal_team' => false]);

        $userWithCurrent = HasTeamsTestUser::query()->create(['id' => 'u-7', 'name' => 'Eve', 'current_team_id' => 't-9']);
        $this->assertSame($current->getKey(), $userWithCurrent->getCurrentTeamOrPersonal()?->getKey());

        $userWithoutCurrent = HasTeamsTestUser::query()->create(['id' => 'u-8', 'name' => 'Frank']);
        HasTeamsTestTeam::query()->create(['id' => 't-10', 'user_id' => 'u-8', 'name' => 'Personal Only', 'personal_team' => true]);
        $this->assertSame('t-10', $userWithoutCurrent->fresh()->getCurrentTeamOrPersonal()?->getKey());
        $this->assertNotSame($personal->getKey(), $userWithoutCurrent->fresh()->getCurrentTeamOrPersonal()?->getKey());
    }

    public function test_belongs_to_team_returns_true_for_owned_team(): void
    {
        $user = HasTeamsTestUser::query()->create(['id' => 'u-bt1', 'name' => 'Grace']);
        HasTeamsTestTeam::query()->create([
            'id' => 't-bt1',
            'user_id' => 'u-bt1',
            'name' => 'Owned',
            'personal_team' => false,
        ]);

        $this->assertTrue($user->fresh()->belongsToTeam(HasTeamsTestTeam::find('t-bt1')));
    }

    public function test_belongs_to_team_returns_true_for_member_team(): void
    {
        $user = HasTeamsTestUser::query()->create(['id' => 'u-bt2', 'name' => 'Heidi']);
        $team = HasTeamsTestTeam::query()->create([
            'id' => 't-bt2',
            'user_id' => 'u-other',
            'name' => 'Member',
            'personal_team' => false,
        ]);
        $user->teams()->attach($team->getKey(), ['role' => 'editor']);

        $this->assertTrue($user->fresh()->belongsToTeam($team));
    }

    public function test_belongs_to_team_returns_false_for_unrelated_team(): void
    {
        $user = HasTeamsTestUser::query()->create(['id' => 'u-bt3', 'name' => 'Ivan']);
        $team = HasTeamsTestTeam::query()->create([
            'id' => 't-bt3',
            'user_id' => 'u-stranger',
            'name' => 'Stranger',
            'personal_team' => false,
        ]);

        $this->assertFalse($user->fresh()->belongsToTeam($team));
    }

    public function test_owns_team_returns_true_for_owner(): void
    {
        $user = HasTeamsTestUser::query()->create(['id' => 'u-ot1', 'name' => 'Judy']);
        $team = HasTeamsTestTeam::query()->create([
            'id' => 't-ot1',
            'user_id' => 'u-ot1',
            'name' => 'Owned',
            'personal_team' => false,
        ]);

        $this->assertTrue($user->ownsTeam($team));
    }

    public function test_owns_team_returns_false_for_non_owner(): void
    {
        $user = HasTeamsTestUser::query()->create(['id' => 'u-ot2', 'name' => 'Karl']);
        $team = HasTeamsTestTeam::query()->create([
            'id' => 't-ot2',
            'user_id' => 'u-someone-else',
            'name' => 'Not Mine',
            'personal_team' => false,
        ]);

        $this->assertFalse($user->ownsTeam($team));
    }

    public function test_has_team_role_returns_true_when_user_has_matching_role(): void
    {
        $user = HasTeamsTestUser::query()->create(['id' => 'u-hr1', 'name' => 'Liam']);
        $team = HasTeamsTestTeam::query()->create([
            'id' => 't-hr1',
            'user_id' => 'u-other',
            'name' => 'Role Team',
            'personal_team' => false,
        ]);
        $user->teams()->attach($team->getKey(), ['role' => 'admin']);

        $this->assertTrue($user->fresh()->hasTeamRole($team, 'admin'));
    }

    public function test_has_team_role_returns_false_when_user_has_different_role(): void
    {
        $user = HasTeamsTestUser::query()->create(['id' => 'u-hr2', 'name' => 'Mia']);
        $team = HasTeamsTestTeam::query()->create([
            'id' => 't-hr2',
            'user_id' => 'u-other',
            'name' => 'Role Team',
            'personal_team' => false,
        ]);
        $user->teams()->attach($team->getKey(), ['role' => 'member']);

        $this->assertFalse($user->fresh()->hasTeamRole($team, 'admin'));
    }

    public function test_has_team_role_returns_false_when_not_a_member(): void
    {
        $user = HasTeamsTestUser::query()->create(['id' => 'u-hr3', 'name' => 'Noah']);
        $team = HasTeamsTestTeam::query()->create([
            'id' => 't-hr3',
            'user_id' => 'u-other',
            'name' => 'No Membership',
            'personal_team' => false,
        ]);

        $this->assertFalse($user->fresh()->hasTeamRole($team, 'admin'));
    }
}

final class HasTeamsTestUser extends Model
{
    use \FlutterSdk\MagicStarter\Traits\HasTeams;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

final class HasTeamsTestTeam extends Model
{
    protected $table = 'teams';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
