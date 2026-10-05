<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Contracts\TransfersTeamOwnership;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteUser;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Ownership is `teams.user_id` plus the `owner` pivot role, so a transfer moves
 * both together and leaves the outgoing owner on the team as an admin.
 */
final class TransferTeamOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private ConcreteUser $owner;

    private ConcreteUser $heir;

    private ConcreteTeam $team;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
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
        $this->heir = ConcreteUser::query()->create(['name' => 'Heir']);
        $this->team = ConcreteTeam::query()->create([
            'user_id' => $this->owner->getKey(),
            'name' => 'Owner Personal',
            'personal_team' => true,
        ]);
        $this->team->users()->attach($this->owner->getKey(), ['role' => 'owner']);
    }

    public function test_a_member_becomes_owner_and_the_outgoing_owner_an_admin(): void
    {
        $this->team->users()->attach($this->heir->getKey(), ['role' => 'editor']);

        app(TransfersTeamOwnership::class)->transfer($this->owner, $this->team, $this->heir);

        $team = ConcreteTeam::query()->findOrFail($this->team->getKey());
        $this->assertSame($this->heir->getKey(), $team->user_id);
        $this->assertFalse($team->personal_team, 'A transferred team is nobody\'s personal team.');
        $this->assertSame('owner', $this->roleOf($this->heir));
        $this->assertSame('admin', $this->roleOf($this->owner));
    }

    public function test_a_non_member_is_refused_and_nothing_changes(): void
    {
        try {
            app(TransfersTeamOwnership::class)->transfer($this->owner, $this->team, $this->heir);
            $this->fail('A transfer to a non-member must be refused.');
        } catch (ValidationException $refusal) {
            $this->assertSame(__('magic-starter::teams.members.new_owner_not_a_member'), $refusal->getMessage());
            $this->assertSame('new_owner_not_a_member', $refusal->response?->getData(true)['code']);
        }

        $team = ConcreteTeam::query()->findOrFail($this->team->getKey());
        $this->assertSame($this->owner->getKey(), $team->user_id);
        $this->assertTrue($team->personal_team);
        $this->assertSame('owner', $this->roleOf($this->owner));
        $this->assertNull($this->roleOf($this->heir));
    }

    private function roleOf(ConcreteUser $user): ?string
    {
        return DB::table('team_user')
            ->where('team_id', $this->team->getKey())
            ->where('user_id', $user->getKey())
            ->value('role');
    }
}
