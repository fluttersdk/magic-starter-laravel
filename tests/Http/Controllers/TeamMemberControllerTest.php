<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\AddsTeamMembers;
use FlutterSdk\MagicStarter\Contracts\RemovesTeamMembers;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeamMemberRoles;
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

        \call_user_func('app', 'gate')->define('view', function (mixed $user, mixed $team): bool {
            return (string) $team->user_id === (string) $user->getKey()
                || $team->users()->where('user_id', $user->getKey())->exists();
        });

        \call_user_func('app', 'gate')->define('manageMembers', function (mixed $user, mixed $team): bool {
            return (string) $team->user_id === (string) $user->getKey();
        });

        $this->app->instance(AddsTeamMembers::class, new class implements AddsTeamMembers
        {
            public function add(\Illuminate\Contracts\Auth\Authenticatable $user, \Illuminate\Database\Eloquent\Model $team, string $email, string $role): void
            {
                $userClass = MagicStarter::userModel();
                $member = $userClass::query()->where('email', $email)->firstOrFail();
                $team->users()->attach($member->getKey(), ['role' => $role]);
            }
        });

        $this->app->instance(RemovesTeamMembers::class, new class implements RemovesTeamMembers
        {
            public function remove(\Illuminate\Contracts\Auth\Authenticatable $user, \Illuminate\Database\Eloquent\Model $team, \Illuminate\Database\Eloquent\Model $teamMember): void
            {
                $team->users()->detach($teamMember->getKey());
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

    public function test_leave_removes_current_user_and_switches_team(): void
    {
        $owner = TeamMemberControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamMemberControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);

        $teamA = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $teamB = TeamMemberControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'B Team', 'personal_team' => false]);
        $teamA->users()->attach($member->id, ['role' => 'member']);
        $teamB->users()->attach($member->id, ['role' => 'member']);
        $member->update(['current_team_id' => $teamA->id]);

        $this->actingAs($member)
            ->deleteJson('/teams/' . $teamA->id . '/members/leave')
            ->assertOk()
            ->assertJsonPath('message', 'You have left the team.');

        $this->assertFalse($teamA->fresh()->users()->where('user_id', $member->id)->exists());
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

    protected $guarded = [];
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
            ->using(\FlutterSdk\MagicStarter\Models\TeamUser::class)
            ->withPivot('role')
            ->withTimestamps();
    }
}
