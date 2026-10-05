<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Contracts\RemovesTeamMembers;
use FlutterSdk\MagicStarter\Contracts\UpdatesTeamMemberRoles;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The owner rules the member endpoints used to enforce on their own now live in
 * the default actions, so a caller that reaches a contract directly (an admin
 * panel, a console command) is held to them too.
 */
final class TeamOwnerGuardsTest extends TestCase
{
    use RefreshDatabase;

    private ConcreteUser $owner;

    private ConcreteUser $staff;

    private ConcreteTeam $team;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        $this->owner = ConcreteUser::query()->create(['name' => 'Owner']);
        $this->staff = ConcreteUser::query()->create(['name' => 'Staff']);
        $this->team = ConcreteTeam::query()->create([
            'user_id' => $this->owner->getKey(),
            'name' => 'Shared',
            'personal_team' => false,
        ]);
        $this->team->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->team->users()->attach($this->staff->getKey(), ['role' => 'admin']);
    }

    public function test_the_owner_cannot_be_removed_by_someone_else(): void
    {
        $refusal = $this->refusalOf(
            fn () => app(RemovesTeamMembers::class)->remove($this->staff, $this->team, $this->owner),
        );

        $this->assertSame(__('magic-starter::teams.members.owner_not_removable'), $refusal->getMessage());
        $this->assertSame('owner_not_removable', $refusal->response?->getData(true)['code']);
        $this->assertSame('owner', $this->roleOf($this->owner));
    }

    public function test_the_owner_cannot_remove_themselves(): void
    {
        $refusal = $this->refusalOf(
            fn () => app(RemovesTeamMembers::class)->remove($this->owner, $this->team, $this->owner),
        );

        $this->assertSame(__('magic-starter::teams.members.owner_cannot_leave'), $refusal->getMessage());
        $this->assertSame('owner_cannot_leave', $refusal->response?->getData(true)['code']);
        $this->assertSame('owner', $this->roleOf($this->owner));
    }

    public function test_a_member_who_is_not_the_owner_is_removed(): void
    {
        app(RemovesTeamMembers::class)->remove($this->owner, $this->team, $this->staff);

        $this->assertNull($this->roleOf($this->staff));
    }

    public function test_the_owners_role_cannot_be_changed(): void
    {
        $refusal = $this->refusalOf(
            fn () => app(UpdatesTeamMemberRoles::class)->update($this->staff, $this->team, $this->owner, 'member'),
        );

        $this->assertSame(__('magic-starter::teams.members.owner_role_locked'), $refusal->getMessage());
        $this->assertSame('owner_role_locked', $refusal->response?->getData(true)['code']);
        $this->assertSame('owner', $this->roleOf($this->owner));
    }

    public function test_the_owner_role_cannot_be_assigned(): void
    {
        $refusal = $this->refusalOf(
            fn () => app(UpdatesTeamMemberRoles::class)->update($this->owner, $this->team, $this->staff, 'owner'),
        );

        $this->assertSame(__('magic-starter::teams.members.role_not_assignable'), $refusal->getMessage());
        $this->assertSame('role_not_assignable', $refusal->response?->getData(true)['code']);
        $this->assertSame('admin', $this->roleOf($this->staff));
    }

    public function test_an_assignable_role_is_written(): void
    {
        app(UpdatesTeamMemberRoles::class)->update($this->owner, $this->team, $this->staff, 'editor');

        $this->assertSame('editor', $this->roleOf($this->staff));
    }

    private function refusalOf(callable $call): ValidationException
    {
        try {
            $call();
        } catch (ValidationException $refusal) {
            return $refusal;
        }

        $this->fail('Expected the action to refuse with a ValidationException.');
    }

    private function roleOf(ConcreteUser $user): ?string
    {
        return DB::table('team_user')
            ->where('team_id', $this->team->getKey())
            ->where('user_id', $user->getKey())
            ->value('role');
    }
}
