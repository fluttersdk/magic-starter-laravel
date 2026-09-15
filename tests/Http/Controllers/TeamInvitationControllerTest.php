<?php

namespace FlutterSdk\MagicStarter\Tests\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\InvitesTeamMembers;
use FlutterSdk\MagicStarter\Http\Controllers\TeamInvitationController;
use FlutterSdk\MagicStarter\MagicStarter;
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
            'magic-starter.models.team_invitation' => TeamInvitationControllerTestInvitation::class,
            'magic-starter.models.membership' => TeamInvitationControllerTestMembership::class,
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

        \call_user_func('app', 'gate')->policy(
            TeamInvitationControllerTestTeam::class,
            \FlutterSdk\MagicStarter\Policies\TeamPolicy::class,
        );

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

        $invitationModelClass = MagicStarter::teamInvitationModel();
        $this->assertNull($invitationModelClass::query()->find($invitation->id));
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
        $invitationModelClass = MagicStarter::teamInvitationModel();
        $this->assertNull($invitationModelClass::query()->find($invitation->id));
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

        $invitationModelClass = MagicStarter::teamInvitationModel();
        $this->assertNull($invitationModelClass::query()->find($invitation->id));
    }

    /**
     * Accepting an invitation you no longer need is a 200, not an error, and it
     * clears the invitation.
     *
     * The row can outlive the reason for it: an admin adds the person directly
     * while the mail is in their inbox, or the same link is opened twice. The
     * person did nothing wrong and is already where the link was taking them,
     * so the endpoint tidies up rather than refusing. Without the delete the
     * invitation would sit in the team's pending list for good, since accepting
     * it again lands here again.
     */
    public function test_accept_clears_the_invitation_when_the_user_already_joined(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $invitee = TeamInvitationControllerTestUser::query()->create(['name' => 'Invitee', 'email' => 'invitee@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $invitation = $team->invitations()->create([
            'email' => $invitee->email,
            'role' => 'admin',
            'token' => 'already-joined-token',
        ]);

        // The admin added them directly while the invitation was in flight.
        $team->users()->attach($invitee->id, ['role' => 'member']);

        $this->actingAs($invitee)
            ->postJson('/invitations/already-joined-token/accept')
            ->assertOk()
            ->assertJsonPath('message', 'You are already a member of this team.');

        $invitationModelClass = MagicStarter::teamInvitationModel();
        $this->assertNull($invitationModelClass::query()->find($invitation->id));

        $this->assertSame(1, $team->fresh()->users()->count(), 'The membership must not be duplicated.');
        $this->assertSame('member', $team->fresh()->users()->find($invitee->id)?->pivot?->role, 'The existing role must survive.');
    }

    /**
     * The owner reaches the same answer, and by the other half of the same
     * check: ownership lives on `teams.user_id` and writes no pivot row, so a
     * membership query alone would let the owner attach themselves as a member
     * of their own team.
     */
    public function test_accept_clears_the_invitation_when_the_user_owns_the_team(): void
    {
        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $invitation = $team->invitations()->create([
            'email' => $owner->email,
            'role' => 'admin',
            'token' => 'owner-token',
        ]);

        $this->actingAs($owner)
            ->postJson('/invitations/owner-token/accept')
            ->assertOk()
            ->assertJsonPath('message', 'You are already a member of this team.');

        $invitationModelClass = MagicStarter::teamInvitationModel();
        $this->assertNull($invitationModelClass::query()->find($invitation->id));

        $this->assertSame(0, $team->fresh()->users()->count(), 'The owner must not gain a pivot row.');
    }

    /**
     * The same answer reaches the caller in the caller's language.
     *
     * Under `en` a key that resolved to nothing is indistinguishable from one
     * that resolved: `__()` returns its argument on a miss and the English line
     * happens to be the sentence the tests above expect.
     */
    public function test_accept_answers_the_already_joined_case_in_the_callers_locale(): void
    {
        \call_user_func('app')->setLocale('tr');

        $owner = TeamInvitationControllerTestUser::query()->create(['name' => 'Owner', 'email' => 'owner@test.dev']);
        $team = TeamInvitationControllerTestTeam::query()->create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
        $team->invitations()->create([
            'email' => $owner->email,
            'role' => 'admin',
            'token' => 'owner-token-tr',
        ]);

        $this->actingAs($owner)
            ->postJson('/invitations/owner-token-tr/accept')
            ->assertOk()
            ->assertJsonPath('message', 'Bu takımın zaten üyesisiniz.');
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
            ->using(\FlutterSdk\MagicStarter\MagicStarter::membershipModel())
            ->withPivot('role')
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(\FlutterSdk\MagicStarter\MagicStarter::teamInvitationModel(), 'team_id');
    }
}

final class TeamInvitationControllerTestInvitation extends \FlutterSdk\MagicStarter\Models\TeamInvitation
{
    protected $table = 'team_invitations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

final class TeamInvitationControllerTestMembership extends \FlutterSdk\MagicStarter\Models\TeamUser
{
    protected $table = 'team_user';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
