<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Contracts\RemovesTeamMembers;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * A removed member must not keep pointing at the team they were removed from,
 * whoever asked for the removal: the endpoints and the admin panel all call the
 * action, so the reset lives here.
 */
final class RemoveTeamMemberTest extends TestCase
{
    use RefreshDatabase;

    private ConcreteUser $owner;

    private ConcreteUser $admin;

    private ConcreteUser $member;

    private ConcreteUser $bystander;

    private ConcreteTeam $team;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('current_team_id')->nullable();
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
        $this->admin = ConcreteUser::query()->create(['name' => 'Admin']);
        $this->member = ConcreteUser::query()->create(['name' => 'Member']);
        $this->bystander = ConcreteUser::query()->create(['name' => 'Bystander']);
        $this->team = ConcreteTeam::query()->create([
            'user_id' => $this->owner->getKey(),
            'name' => 'Shared',
            'personal_team' => false,
        ]);
        $this->team->users()->attach($this->admin->getKey(), ['role' => 'admin']);
        $this->team->users()->attach($this->member->getKey(), ['role' => 'member']);
        $this->team->users()->attach($this->bystander->getKey(), ['role' => 'member']);
    }

    public function test_removing_a_member_clears_a_pointer_to_that_team(): void
    {
        $this->member->forceFill(['current_team_id' => $this->team->getKey()])->save();

        app(RemovesTeamMembers::class)->remove($this->admin, $this->team, $this->member);

        $this->assertNull($this->member->fresh()->current_team_id);
    }

    public function test_removing_a_member_keeps_a_pointer_to_another_team(): void
    {
        $other = ConcreteTeam::query()->create([
            'user_id' => $this->owner->getKey(),
            'name' => 'Other',
            'personal_team' => false,
        ]);
        $this->member->forceFill(['current_team_id' => $other->getKey()])->save();

        app(RemovesTeamMembers::class)->remove($this->admin, $this->team, $this->member);

        $this->assertSame($other->getKey(), $this->member->fresh()->current_team_id);
    }

    public function test_removing_a_member_keeps_the_pointer_of_everyone_else_on_the_team(): void
    {
        $this->member->forceFill(['current_team_id' => $this->team->getKey()])->save();
        $this->bystander->forceFill(['current_team_id' => $this->team->getKey()])->save();

        app(RemovesTeamMembers::class)->remove($this->admin, $this->team, $this->member);

        $this->assertSame($this->team->getKey(), $this->bystander->fresh()->current_team_id);
    }

    public function test_a_member_leaving_clears_a_pointer_to_that_team(): void
    {
        $this->member->forceFill(['current_team_id' => $this->team->getKey()])->save();

        app(RemovesTeamMembers::class)->remove($this->member, $this->team, $this->member);

        $this->assertNull($this->member->fresh()->current_team_id);
    }
}
