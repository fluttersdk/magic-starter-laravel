<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\InvitesTeamMembers;
use FlutterSdk\MagicStarter\Http\Controllers\TeamInvitationController;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\TeamInvitation;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\Access\Authorizable;

final class TeamInvitationControllerTest extends TestCase
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
            'magic-starter.models.user' => TeamInvitationControllerTestUser::class,
            'magic-starter.models.team' => TeamInvitationControllerTestTeam::class,
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

        \call_user_func([\call_user_func('app', 'db.schema'), 'create'], 'team_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->string('email');
            $table->string('role')->nullable()->default('member');
            $table->string('token')->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        \call_user_func('app')->singleton('gate', function ($app) {
            return new \Illuminate\Auth\Access\Gate($app, function (): mixed {
                return \call_user_func('auth')->user();
            });
        });

        \call_user_func('app')->alias('gate', \Illuminate\Contracts\Auth\Access\Gate::class);

        \call_user_func('app', 'gate')->define('manageInvitations', function (mixed $user, mixed $team): bool {
            return (string) $team->user_id === (string) $user->getKey();
        });

        $this->app->instance(InvitesTeamMembers::class, new class implements InvitesTeamMembers
        {
            public function invite(\Illuminate\Contracts\Auth\Authenticatable $user, \Illuminate\Database\Eloquent\Model $team, string $email, string $role): \Illuminate\Database\Eloquent\Model
            {
                return $team->invitations()->create([
                    'email' => $email,
                    'role' => $role,
                    'token' => \call_user_func('str')->random(32),
                ]);
            }
        });

        \call_user_func('app', 'router')->get('/teams/{team}/invitations', [TeamInvitationController::class, 'index']);
        \call_user_func('app', 'router')->post('/teams/{team}/invitations', [TeamInvitationController::class, 'store']);
        \call_user_func('app', 'router')->delete('/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy']);
        \call_user_func('app', 'router')->post('/invitations/{token}/accept', [TeamInvitationController::class, 'accept']);
    }

    public function test_index_lists_team_invitations(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $invitation = $team->invitations()->create(['email' => 'invitee@test.dev', 'role' => 'member', 'token' => 'tok-1']);

        $this->actingAs($owner)
            ->getJson('/teams/' . $team->id . '/invitations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $invitation->id);
    }

    public function test_store_creates_invitation_via_contract_action(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);

        $this->actingAs($owner)
            ->postJson('/teams/' . $team->id . '/invitations', [
                'email' => 'new@test.dev',
                'role' => 'editor',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.email', 'new@test.dev')
            ->assertJsonPath('data.role', 'editor');

        $this->assertTrue($team->fresh()->invitations()->where('email', 'new@test.dev')->exists());
    }

    public function test_destroy_cancels_invitation_for_same_team(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $invitation = $team->invitations()->create(['email' => 'invitee@test.dev', 'role' => 'member', 'token' => 'tok-2']);

        $this->actingAs($owner)
            ->deleteJson('/teams/' . $team->id . '/invitations/' . $invitation->id)
            ->assertOk()
            ->assertJsonPath('message', 'Invitation canceled successfully.');

        $this->assertNull(TeamInvitation::query()->find($invitation->id));
    }

    public function test_accept_adds_user_to_team_and_deletes_invitation(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $invitee = TeamInvitationControllerTestUser::query()->create(['name' => 'Invitee', 'email' => 'invitee@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $invitation = $team->invitations()->create([
            'email' => $invitee->email,
            'role' => 'admin',
            'token' => 'accept-token',
        ]);

        $this->actingAs($invitee)
            ->postJson('/invitations/accept-token/accept')
            ->assertOk()
            ->assertJsonPath('message', 'Invitation accepted. You have joined the team.');

        $this->assertTrue($team->fresh()->users()->where('user_id', $invitee->id)->exists());
        $this->assertSame('admin', $team->fresh()->users()->find($invitee->id)?->pivot?->role);
        $this->assertNull(TeamInvitation::query()->find($invitation->id));
    }

    public function test_accept_rejects_when_email_does_not_match(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->invitations()->create([
            'email' => 'invitee@test.dev',
            'role' => 'admin',
            'token' => 'accept-token',
        ]);

        $otherUser = TeamInvitationControllerTestUser::query()->create(['name' => 'Other', 'email' => 'other@test.dev']);

        $this->actingAs($otherUser)
            ->postJson('/invitations/accept-token/accept')
            ->assertStatus(403)
            ->assertJsonPath('message', 'This invitation was sent to a different email address.');
    }

    public function test_accept_rejects_expired_invitation(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $invitation = $team->invitations()->create([
            'email' => 'invitee@test.dev',
            'role' => 'admin',
            'token' => 'accept-token',
            'expires_at' => now()->subDay(),
        ]);

        $invitee = TeamInvitationControllerTestUser::query()->create(['name' => 'Invitee', 'email' => 'invitee@test.dev']);

        $this->actingAs($invitee)
            ->postJson('/invitations/accept-token/accept')
            ->assertStatus(410)
            ->assertJsonPath('message', 'This invitation has expired.');

        $this->assertNull(TeamInvitation::query()->find($invitation->id));
    }

    public function test_index_returns_403_for_non_owner(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamInvitationControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->users()->attach($member->id, ['role' => 'member']);
        $this->actingAs($member)
            ->getJson('/teams/' . $team->id . '/invitations')
            ->assertStatus(403);
    }

    public function test_store_returns_422_for_duplicate_invitation(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->invitations()->create(['email' => 'dup@test.dev', 'role' => 'member', 'token' => 'tok-dup']);
        $this->actingAs($owner)
            ->postJson('/teams/' . $team->id . '/invitations', [
                'email' => 'dup@test.dev',
                'role' => 'editor',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    public function test_store_returns_422_for_existing_member(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $member = TeamInvitationControllerTestUser::query()->create(['name' => 'Member', 'email' => 'member@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->users()->attach($member->id, ['role' => 'member']);
        $this->actingAs($owner)
            ->postJson('/teams/' . $team->id . '/invitations', [
                'email' => $member->email,
                'role' => 'editor',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    public function test_accept_returns_404_for_invalid_token(): void
    {
        $user = TeamInvitationControllerTestUser::query()->create(['name' => 'User', 'email' => 'user@test.dev']);
        $this->actingAs($user)
            ->postJson('/invitations/nonexistent-token/accept')
            ->assertStatus(404);
    }
}

final class TeamInvitationControllerTestUser extends Model implements AuthenticatableContract
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

final class TeamInvitationControllerTestTeam extends \FlutterSdk\MagicStarter\Models\Team
{
    protected $table = 'teams';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(TeamInvitationControllerTestUser::class, 'team_user', 'team_id', 'user_id')
            ->using(\FlutterSdk\MagicStarter\Models\TeamUser::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class, 'team_id');
    }
}
