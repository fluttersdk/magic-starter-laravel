<?php

namespace FlutterSdk\MagicStarter\Tests\Policies;

use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Policies\TeamPolicy;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\Access\Authorizable;

final class TeamPolicyTest extends TestCase
{
    private TeamPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        MagicStarter::reset();

        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config()->set('magic-starter.models.user', TeamPolicyTestUser::class);
        config()->set('magic-starter.models.team', TeamPolicyTestTeam::class);
        config()->set('magic-starter.models.membership',
            \FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeamUser::class,
        );

        $schema = app('db.schema');

        $schema->create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('current_team_id')->nullable();
            $table->timestamps();
        });

        $schema->create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamps();
        });

        $schema->create('team_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        $this->policy = new TeamPolicy;
    }

    public function test_view_allows_owner(): void
    {
        $owner = $this->createUser('Owner', 'owner-view@example.test');
        $team = $this->createTeam($owner, 'Owner Team');

        $this->assertTrue($this->policy->view($owner, $team));
    }

    public function test_view_allows_member(): void
    {
        $owner = $this->createUser('Owner', 'owner-member-view@example.test');
        $member = $this->createUser('Member', 'member-view@example.test');
        $team = $this->createTeam($owner, 'Shared Team');

        $this->attachMember($team, $member, 'member');

        $this->assertTrue($this->policy->view($member, $team));
    }

    public function test_view_denies_non_member(): void
    {
        $owner = $this->createUser('Owner', 'owner-non-member-view@example.test');
        $stranger = $this->createUser('Stranger', 'stranger-view@example.test');
        $team = $this->createTeam($owner, 'Private Team');

        $this->assertFalse($this->policy->view($stranger, $team));
    }

    public function test_update_allows_owner(): void
    {
        $owner = $this->createUser('Owner', 'owner-update@example.test');
        $team = $this->createTeam($owner, 'Editable Team');

        $this->assertTrue($this->policy->update($owner, $team));
    }

    public function test_update_denies_member(): void
    {
        $owner = $this->createUser('Owner', 'owner-member-update@example.test');
        $member = $this->createUser('Member', 'member-update@example.test');
        $team = $this->createTeam($owner, 'Owner Team');

        $this->attachMember($team, $member, 'member');

        $this->assertFalse($this->policy->update($member, $team));
    }

    public function test_update_denies_non_member(): void
    {
        $owner = $this->createUser('Owner', 'owner-non-member-update@example.test');
        $stranger = $this->createUser('Stranger', 'stranger-update@example.test');
        $team = $this->createTeam($owner, 'Owner Team');

        $this->assertFalse($this->policy->update($stranger, $team));
    }

    public function test_delete_allows_owner(): void
    {
        $owner = $this->createUser('Owner', 'owner-delete@example.test');
        $team = $this->createTeam($owner, 'Removable Team');

        $this->assertTrue($this->policy->delete($owner, $team));
    }

    public function test_delete_denies_member(): void
    {
        $owner = $this->createUser('Owner', 'owner-member-delete@example.test');
        $member = $this->createUser('Member', 'member-delete@example.test');
        $team = $this->createTeam($owner, 'Protected Team');

        $this->attachMember($team, $member, 'member');

        $this->assertFalse($this->policy->delete($member, $team));
    }

    public function test_delete_denies_non_member(): void
    {
        $owner = $this->createUser('Owner', 'owner-non-member-delete@example.test');
        $stranger = $this->createUser('Stranger', 'stranger-delete@example.test');
        $team = $this->createTeam($owner, 'Protected Team');

        $this->assertFalse($this->policy->delete($stranger, $team));
    }

    public function test_manage_members_allows_owner(): void
    {
        $owner = $this->createUser('Owner', 'owner-manage-members@example.test');
        $team = $this->createTeam($owner, 'Core Team');

        $this->assertTrue($this->policy->manageMembers($owner, $team));
    }

    public function test_manage_members_allows_admin(): void
    {
        $owner = $this->createUser('Owner', 'owner-admin-manage-members@example.test');
        $admin = $this->createUser('Admin', 'admin-manage-members@example.test');
        $team = $this->createTeam($owner, 'Admin Team');

        $this->attachMember($team, $admin, 'admin');

        $this->assertTrue($this->policy->manageMembers($admin, $team));
    }

    public function test_manage_members_denies_regular_member(): void
    {
        $owner = $this->createUser('Owner', 'owner-regular-manage-members@example.test');
        $member = $this->createUser('Member', 'member-manage-members@example.test');
        $team = $this->createTeam($owner, 'Member Team');

        $this->attachMember($team, $member, 'member');

        $this->assertFalse($this->policy->manageMembers($member, $team));
    }

    public function test_manage_members_denies_non_member(): void
    {
        $owner = $this->createUser('Owner', 'owner-non-member-manage-members@example.test');
        $stranger = $this->createUser('Stranger', 'stranger-manage-members@example.test');
        $team = $this->createTeam($owner, 'Member Team');

        $this->assertFalse($this->policy->manageMembers($stranger, $team));
    }

    public function test_manage_invitations_allows_owner(): void
    {
        $owner = $this->createUser('Owner', 'owner-manage-invitations@example.test');
        $team = $this->createTeam($owner, 'Owner Team');

        $this->assertTrue($this->policy->manageInvitations($owner, $team));
    }

    public function test_manage_invitations_allows_admin(): void
    {
        $owner = $this->createUser('Owner', 'owner-admin-manage-invitations@example.test');
        $admin = $this->createUser('Admin', 'admin-manage-invitations@example.test');
        $team = $this->createTeam($owner, 'Invitations Team');

        $this->attachMember($team, $admin, 'admin');

        $this->assertTrue($this->policy->manageInvitations($admin, $team));
    }

    public function test_manage_invitations_denies_regular_member(): void
    {
        $owner = $this->createUser('Owner', 'owner-regular-manage-invitations@example.test');
        $member = $this->createUser('Member', 'member-manage-invitations@example.test');
        $team = $this->createTeam($owner, 'Invitations Team');

        $this->attachMember($team, $member, 'member');

        $this->assertFalse($this->policy->manageInvitations($member, $team));
    }

    public function test_manage_invitations_denies_non_member(): void
    {
        $owner = $this->createUser('Owner', 'owner-non-member-manage-invitations@example.test');
        $stranger = $this->createUser('Stranger', 'stranger-manage-invitations@example.test');
        $team = $this->createTeam($owner, 'Invitations Team');

        $this->assertFalse($this->policy->manageInvitations($stranger, $team));
    }

    public function test_switch_to_allows_owner(): void
    {
        $owner = $this->createUser('Owner', 'owner-switch@example.test');
        $team = $this->createTeam($owner, 'Switch Team');

        $this->assertTrue($this->policy->switchTo($owner, $team));
    }

    public function test_switch_to_allows_member(): void
    {
        $owner = $this->createUser('Owner', 'owner-member-switch@example.test');
        $member = $this->createUser('Member', 'member-switch@example.test');
        $team = $this->createTeam($owner, 'Shared Switch Team');

        $this->attachMember($team, $member, 'member');

        $this->assertTrue($this->policy->switchTo($member, $team));
    }

    public function test_switch_to_denies_non_member(): void
    {
        $owner = $this->createUser('Owner', 'owner-non-member-switch@example.test');
        $stranger = $this->createUser('Stranger', 'stranger-switch@example.test');
        $team = $this->createTeam($owner, 'Private Switch Team');

        $this->assertFalse($this->policy->switchTo($stranger, $team));
    }

    private function createUser(string $name, string $email): TeamPolicyTestUser
    {
        return TeamPolicyTestUser::query()->create([
            'name' => $name,
            'email' => $email,
        ]);
    }

    private function createTeam(TeamPolicyTestUser $owner, string $name): TeamPolicyTestTeam
    {
        return TeamPolicyTestTeam::query()->create([
            'user_id' => $owner->id,
            'name' => $name,
            'personal_team' => false,
        ]);
    }

    private function attachMember(TeamPolicyTestTeam $team, TeamPolicyTestUser $user, string $role): void
    {
        $team->users()->attach($user->getKey(), [
            'role' => $role,
        ]);
    }
}

final class TeamPolicyTestUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use Authorizable;
    use \FlutterSdk\MagicStarter\Traits\HasTeams;
    use HasUuids;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

final class TeamPolicyTestTeam extends \FlutterSdk\MagicStarter\Models\Team
{
    protected $table = 'teams';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(TeamPolicyTestUser::class, 'team_user', 'team_id', 'user_id')
            ->using(MagicStarter::membershipModel())
            ->withPivot('role')
            ->withTimestamps();
    }
}
