<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\AddsTeamMembers;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeamMemberRoles;
use FlutterSdk\MagicStarter\Events\TeamMemberRemoved;
use FlutterSdk\MagicStarter\Http\Controllers\TeamMemberController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Event;

final class TeamMemberControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        \call_user_func('config', [
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'magic-starter.models.user' => TeamMemberControllerTestUser::class,
            'magic-starter.models.team' => TeamMemberControllerTestTeam::class,
            'magic-starter.models.membership' => \FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser::class,
        ]);

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('current_team_id')->nullable();
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamps();
        });

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'team_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        \call_user_func('app')->singleton('gate', function ($app) {
            return new \Illuminate\Auth\Access\Gate($app, function (): mixed {
                return \call_user_func('auth')->user();
            });
        });

        \call_user_func('app')->alias('gate', \Illuminate\Contracts\Auth\Access\Gate::class);

        \call_user_func('app', 'gate')->policy(
            TeamMemberControllerTestTeam::class,
            \FlutterSdk\MagicStarter\Policies\TeamPolicy::class,
        );

        $this->app->instance(AddsTeamMembers::class, new class implements AddsTeamMembers
        {
            public function add(\Illuminate\Contracts\Auth\Authenticatable $user, \Illuminate\Database\Eloquent\Model $team, string $email, string $role): void
            {
                $userClass = MagicStarter::userModel();
                $member = $userClass::query()->where('email', $email)->firstOrFail();
                $team->users()->attach($member->getKey(), ['role' => $role]);
            }
        });

        $this->app->instance(UpdatesTeamMemberRoles::class, new class implements UpdatesTeamMemberRoles
        {
            public function update(mixed $user, mixed $team, mixed $teamMember, string $role): void
            {
                $team->users()->updateExistingPivot($teamMember->getKey(), ['role' => $role]);
            }
        });

        \call_user_func('app', 'router')->get('/teams/{team}/members', [TeamMemberController::class, 'index']);
        \call_user_func('app', 'router')->post('/teams/{team}/members', [TeamMemberController::class, 'store']);
        \call_user_func('app', 'router')->delete('/teams/{team}/members/leave', [TeamMemberController::class, 'leave']);
        \call_user_func('app', 'router')->put('/teams/{team}/members/{user}', [TeamMemberController::class, 'update']);
        \call_user_func('app', 'router')->delete('/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy']);
    }

    public function test_index_returns_owner_and_members(): void
    {
        $owner = TeamMemberControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamMemberControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $team = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->users()->attach($member->id, ['role' => 'editor']);

        $response = $this->actingAs($owner)->getJson('/teams/' . $team->id . '/members');

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertTrue(\call_user_func('collect', $response->json('data'))->contains('role', 'owner'));
        $this->assertTrue(\call_user_func('collect', $response->json('data'))->contains('role', 'editor'));
    }

    public function test_update_changes_member_role(): void
    {
        $owner = TeamMemberControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamMemberControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $team = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->users()->attach($member->id, ['role' => 'member']);

        $this->actingAs($owner)
            ->putJson('/teams/' . $team->id . '/members/' . $member->id, ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('message', 'Team member updated successfully.');

        $this->assertSame('admin', $team->fresh()->users()->find($member->id)?->pivot?->role);
    }

    public function test_destroy_removes_member_via_contract_action(): void
    {
        $owner = TeamMemberControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamMemberControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $team = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->users()->attach($member->id, ['role' => 'member']);

        $this->actingAs($owner)
            ->deleteJson('/teams/' . $team->id . '/members/' . $member->id)
            ->assertOk()
            ->assertJsonPath('message', 'Team member removed successfully.');

        $this->assertFalse($team->fresh()->users()->where('user_id', $member->id)->exists());
    }

    public function test_destroy_dispatches_removed_with_the_owner_as_the_actor(): void
    {
        Event::fake([TeamMemberRemoved::class]);
        $owner = TeamMemberControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamMemberControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $team = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->users()->attach($member->id, ['role' => 'member']);

        $this->actingAs($owner)->deleteJson('/teams/' . $team->id . '/members/' . $member->id)->assertOk();

        Event::assertDispatchedTimes(TeamMemberRemoved::class, 1);
        Event::assertDispatched(
            TeamMemberRemoved::class,
            fn (TeamMemberRemoved $event): bool => $event->teamId === (string) $team->id
                && $event->userId === (string) $member->id
                && $event->actorId === (string) $owner->id,
        );
    }

    public function test_leave_dispatches_removed_with_the_member_as_the_actor(): void
    {
        Event::fake([TeamMemberRemoved::class]);
        $owner = TeamMemberControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamMemberControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $team = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->users()->attach($member->id, ['role' => 'member']);

        $this->actingAs($member)->deleteJson('/teams/' . $team->id . '/members/leave')->assertOk();

        Event::assertDispatchedTimes(TeamMemberRemoved::class, 1);
        Event::assertDispatched(
            TeamMemberRemoved::class,
            fn (TeamMemberRemoved $event): bool => $event->userId === (string) $member->id
                && $event->actorId === (string) $member->id,
        );
    }

    public function test_a_refused_removal_dispatches_nothing(): void
    {
        Event::fake([TeamMemberRemoved::class]);
        $owner = TeamMemberControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);

        $this->actingAs($owner)->deleteJson('/teams/' . $team->id . '/members/' . $owner->id)->assertStatus(403);
        $this->actingAs($owner)->deleteJson('/teams/' . $team->id . '/members/leave')->assertStatus(403);

        Event::assertNotDispatched(TeamMemberRemoved::class);
    }

    public function test_leave_removes_the_current_user_from_the_team(): void
    {
        $owner = TeamMemberControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamMemberControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $team = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->users()->attach($member->id, ['role' => 'member']);

        $this->actingAs($member)
            ->deleteJson('/teams/' . $team->id . '/members/leave')
            ->assertOk()
            ->assertJsonPath('message', 'You have left the team.');

        $this->assertFalse($team->fresh()->users()->where('user_id', $member->id)->exists());
    }

    public function test_leave_resets_the_current_team_pointer_to_the_personal_team_fallback(): void
    {
        $owner = TeamMemberControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamMemberControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $personal = TeamMemberControllerTestTeam::query()->create(['user_id' => $member->id, 'name' => 'Personal', 'personal_team' => true]);
        $teamA = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $teamB = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'B Team', 'personal_team' => false]);
        $teamA->users()->attach($member->id, ['role' => 'member']);
        $teamB->users()->attach($member->id, ['role' => 'member']);
        $member->forceFill(['current_team_id' => $teamA->id])->save();

        $this->actingAs($member)
            ->deleteJson('/teams/' . $teamA->id . '/members/leave')
            ->assertOk();

        $left = $member->fresh();
        $this->assertNull($left->current_team_id);
        $this->assertSame($personal->id, $left->getCurrentTeamOrPersonal()?->getKey());
    }

    public function test_leave_keeps_the_pointer_of_a_member_working_in_another_team(): void
    {
        $owner = TeamMemberControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamMemberControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $teamA = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $teamB = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'B Team', 'personal_team' => false]);
        $teamA->users()->attach($member->id, ['role' => 'member']);
        $teamB->users()->attach($member->id, ['role' => 'member']);
        $member->forceFill(['current_team_id' => $teamB->id])->save();

        $this->actingAs($member)
            ->deleteJson('/teams/' . $teamA->id . '/members/leave')
            ->assertOk();

        $this->assertSame($teamB->id, $member->fresh()->current_team_id);
    }

    public function test_destroy_by_an_admin_resets_the_removed_members_current_team_pointer(): void
    {
        $owner = TeamMemberControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $admin = TeamMemberControllerTestUser::query()->create(['name' => 'Admin', 'email' => 'admin@test.dev']);
        $member = TeamMemberControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $personal = TeamMemberControllerTestTeam::query()->create(['user_id' => $member->id, 'name' => 'Personal', 'personal_team' => true]);
        $team = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->users()->attach($admin->id, ['role' => 'admin']);
        $team->users()->attach($member->id, ['role' => 'member']);
        $member->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($admin)
            ->deleteJson('/teams/' . $team->id . '/members/' . $member->id)
            ->assertOk();

        $removed = $member->fresh();
        $this->assertNull($removed->current_team_id);
        $this->assertSame($personal->id, $removed->getCurrentTeamOrPersonal()?->getKey());
    }

    public function test_destroy_keeps_the_pointer_of_a_member_working_in_another_team(): void
    {
        $owner = TeamMemberControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamMemberControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $teamA = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $teamB = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'B Team', 'personal_team' => false]);
        $teamA->users()->attach($member->id, ['role' => 'member']);
        $teamB->users()->attach($member->id, ['role' => 'member']);
        $member->forceFill(['current_team_id' => $teamB->id])->save();

        $this->actingAs($owner)
            ->deleteJson('/teams/' . $teamA->id . '/members/' . $member->id)
            ->assertOk();

        $this->assertSame($teamB->id, $member->fresh()->current_team_id);
    }

    public function test_update_returns_403_when_changing_owner_role(): void
    {
        $owner = TeamMemberControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $this->actingAs($owner)
            ->putJson('/teams/' . $team->id . '/members/' . $owner->id, ['role' => 'member'])
            ->assertStatus(403);
    }

    public function test_destroy_returns_403_when_removing_owner(): void
    {
        $owner = TeamMemberControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $this->actingAs($owner)
            ->deleteJson('/teams/' . $team->id . '/members/' . $owner->id)
            ->assertStatus(403);
    }

    public function test_leave_returns_403_for_team_owner(): void
    {
        $owner = TeamMemberControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $this->actingAs($owner)
            ->deleteJson('/teams/' . $team->id . '/members/leave')
            ->assertStatus(403);
    }

    public function test_leave_returns_404_for_non_member(): void
    {
        $owner = TeamMemberControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $stranger = TeamMemberControllerTestUser::query()->create(['name' => 'Stranger', 'email' => 'stranger@test.dev']);
        $team = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $this->actingAs($stranger)
            ->deleteJson('/teams/' . $team->id . '/members/leave')
            ->assertStatus(404);
    }
}

final class TeamMemberControllerTestUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use Authorizable;
    use \FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
    use \FlutterSdk\MagicStarter\Traits\HasTeams;
    use HasUuids;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    /** Mirrors the published User stub: `current_team_id` is system-managed and not mass assignable. */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];
}

final class TeamMemberControllerTestTeam extends \FlutterSdk\MagicStarter\Models\Team
{
    protected $table = 'teams';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(TeamMemberControllerTestUser::class, 'team_user', 'team_id', 'user_id')
            ->using(\FlutterSdk\MagicStarter\MagicStarter::membershipModel())
            ->withPivot('role')
            ->withTimestamps();
    }
}
