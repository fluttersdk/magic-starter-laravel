<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\CreatesTeams;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeams;
use FlutterSdk\MagicStarter\Http\Controllers\TeamController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\Access\Authorizable;

final class TeamControllerTest extends TestCase
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
            'magic-starter.models.user' => TeamControllerTestUser::class,
            'magic-starter.models.team' => TeamControllerTestTeam::class,
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

        \call_user_func('app', 'gate')->define('update', function (mixed $user, mixed $team): bool {
            return (string) $team->user_id === (string) $user->getKey();
        });

        \call_user_func('app', 'gate')->define('delete', function (mixed $user, mixed $team): bool {
            return (string) $team->user_id === (string) $user->getKey();
        });

        $this->app->instance(CreatesTeams::class, new class implements CreatesTeams
        {
            public function create(\Illuminate\Contracts\Auth\Authenticatable $user, array $input): \Illuminate\Database\Eloquent\Model
            {
                $team = $user->ownedTeams()->create([
                    'name' => $input['name'],
                    'personal_team' => false,
                ]);

                $team->users()->attach($user->getKey(), ['role' => 'owner']);
                $user->update(['current_team_id' => $team->getKey()]);

                return $team;
            }
        });

        $this->app->instance(UpdatesTeams::class, new class implements UpdatesTeams
        {
            public function update(\Illuminate\Contracts\Auth\Authenticatable $user, \Illuminate\Database\Eloquent\Model $team, array $input): void
            {
                $team->update(['name' => $input['name']]);
            }
        });

        $this->app->instance(DeletesTeams::class, new class implements DeletesTeams
        {
            public function delete(\Illuminate\Database\Eloquent\Model $team): void
            {
                $team->delete();
            }
        });

        \call_user_func('app', 'router')->get('/teams', [TeamController::class, 'index']);
        \call_user_func('app', 'router')->post('/teams', [TeamController::class, 'store']);
        \call_user_func('app', 'router')->get('/teams/{team}', [TeamController::class, 'show']);
        \call_user_func('app', 'router')->put('/teams/{team}', [TeamController::class, 'update']);
        \call_user_func('app', 'router')->delete('/teams/{team}', [TeamController::class, 'destroy']);
    }

    public function test_index_lists_owned_and_member_teams(): void
    {
        $owner = TeamControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);

        $owned = TeamControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'Owned', 'personal_team' => false]);
        $memberTeam = TeamControllerTestTeam::query()->create(['user_id' => $member->id, 'name' => 'Joined', 'personal_team' => false]);
        $memberTeam->users()->attach($owner->id, ['role' => 'member']);

        $response = $this->actingAs($owner)->getJson('/teams');

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertTrue(\call_user_func('collect', $response->json('data'))->contains('id', $owned->id));
        $this->assertTrue(\call_user_func('collect', $response->json('data'))->contains('id', $memberTeam->id));
    }

    public function test_store_creates_team_and_sets_current_team(): void
    {
        $user = TeamControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);

        $response = $this->actingAs($user)->postJson('/teams', [
            'name' => 'New Team',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'New Team');

        $team = TeamControllerTestTeam::query()->where('name', 'New Team')->firstOrFail();
        $this->assertSame($team->id, $user->fresh()->current_team_id);
        $this->assertTrue($team->users()->where('user_id', $user->id)->exists());
    }

    public function test_show_returns_team_for_member(): void
    {
        $owner = TeamControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $team = TeamControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'Visible Team', 'personal_team' => false]);
        $team->users()->attach($member->id, ['role' => 'member']);

        $this->actingAs($member)
            ->getJson('/teams/' . $team->id)
            ->assertOk()
            ->assertJsonPath('data.id', $team->id)
            ->assertJsonPath('data.name', 'Visible Team');
    }

    public function test_update_changes_team_name_for_owner(): void
    {
        $owner = TeamControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'Old Name', 'personal_team' => false]);

        $this->actingAs($owner)
            ->putJson('/teams/' . $team->id, ['name' => 'Updated Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');

        $this->assertSame('Updated Name', $team->fresh()->name);
    }

    public function test_destroy_deletes_team_and_switches_current_team(): void
    {
        $owner = TeamControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);

        $teamA = TeamControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'Team A', 'personal_team' => false]);
        $teamB = TeamControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'Team B', 'personal_team' => false]);
        $owner->update(['current_team_id' => $teamA->id]);

        $this->actingAs($owner)
            ->deleteJson('/teams/' . $teamA->id)
            ->assertOk()
            ->assertJsonPath('message', 'Team deleted successfully.');

        $this->assertNull(TeamControllerTestTeam::query()->find($teamA->id));
        $this->assertSame($teamB->id, $owner->fresh()->current_team_id);
    }

    public function test_show_returns_403_for_non_member(): void
    {
        $owner = TeamControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $stranger = TeamControllerTestUser::query()->create(['name' => 'Stranger', 'email' => 'stranger@test.dev']);
        $team = TeamControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'Private Team', 'personal_team' => false]);

        $this->actingAs($stranger)
            ->getJson('/teams/' . $team->id)
            ->assertStatus(403);
    }

    public function test_update_returns_403_for_non_owner(): void
    {
        $owner = TeamControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $team = TeamControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'Team', 'personal_team' => false]);
        $team->users()->attach($member->id, ['role' => 'member']);

        $this->actingAs($member)
            ->putJson('/teams/' . $team->id, ['name' => 'Hacked Name'])
            ->assertStatus(403);

        $this->assertSame('Team', $team->fresh()->name);
    }

    public function test_destroy_returns_403_for_non_owner(): void
    {
        $owner = TeamControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $team = TeamControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'Team', 'personal_team' => false]);
        $team->users()->attach($member->id, ['role' => 'member']);

        $this->actingAs($member)
            ->deleteJson('/teams/' . $team->id)
            ->assertStatus(403);

        $this->assertNotNull(TeamControllerTestTeam::query()->find($team->id));
    }

    public function test_destroy_returns_403_when_deleting_last_team(): void
    {
        $owner = TeamControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'Only Team', 'personal_team' => false]);
        $team->users()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner)
            ->deleteJson('/teams/' . $team->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'You cannot delete your last team.');

        $this->assertNotNull(TeamControllerTestTeam::query()->find($team->id));
    }

    public function test_show_returns_404_for_nonexistent_team(): void
    {
        $user = TeamControllerTestUser::query()->create(['name' => 'User', 'email' => 'user@test.dev']);

        $this->actingAs($user)
            ->getJson('/teams/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }
}

final class TeamControllerTestUser extends Model implements AuthenticatableContract
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

final class TeamControllerTestTeam extends \FlutterSdk\MagicStarter\Models\Team
{
    protected $table = 'teams';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(TeamControllerTestUser::class, 'team_user', 'team_id', 'user_id')
            ->using(\FlutterSdk\MagicStarter\Models\TeamUser::class)
            ->withPivot('role')
            ->withTimestamps();
    }
}
