<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\DeleteTeam;
use FlutterSdk\MagicStarter\Actions\TrashTeam;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Tests\Fixtures\BuildsRestorableTeamSchema;
use FlutterSdk\MagicStarter\Tests\Fixtures\ConcreteTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\RestorableTeam;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The purge half of a team delete: `DeletesTeams` removes a team for good,
 * with its photo, its memberships and its invitations, whether the team model
 * soft deletes or not.
 */
final class DeleteTeamTest extends TestCase
{
    use BuildsRestorableTeamSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildRestorableTeamSchema();
        Storage::fake('public');
    }

    public function test_a_soft_deleting_team_is_deleted_for_good_not_trashed(): void
    {
        $team = $this->makeTeam($this->makeUser());

        (new DeleteTeam)->delete($team);

        $this->assertNull(RestorableTeam::withTrashed()->find($team->getKey()));
    }

    public function test_a_trashed_team_is_purged_for_good(): void
    {
        $team = $this->makeTeam($this->makeUser());
        (new TrashTeam)->trash($team);

        (new DeleteTeam)->delete(RestorableTeam::onlyTrashed()->findOrFail($team->getKey()));

        $this->assertNull(RestorableTeam::withTrashed()->find($team->getKey()));
    }

    public function test_the_purge_removes_the_photo_the_members_and_the_invitations(): void
    {
        $team = $this->makeTeam($this->makeUser(), ['profile_photo_path' => 'team-photos/home.png']);
        $this->addMember($team, $this->makeUser('Member'));
        $team->invitations()->create([
            'email' => 'guest@example.test',
            'role' => 'member',
            'token' => 'token-1',
        ]);
        Storage::disk('public')->put('team-photos/home.png', 'png');

        (new DeleteTeam)->delete($team);

        Storage::disk('public')->assertMissing('team-photos/home.png');
        $this->assertSame(0, DB::table('team_user')->where('team_id', $team->getKey())->count());
        $this->assertSame(0, DB::table('team_invitations')->count());
    }

    public function test_the_purge_removes_the_photo_from_the_team_disk_when_it_differs_from_the_profile_disk(): void
    {
        config([
            'magic-starter.team_photo_disk' => 'team-disk',
            'magic-starter.profile_photo_disk' => 'public',
        ]);
        Storage::fake('team-disk');
        $team = $this->makeTeam($this->makeUser(), ['profile_photo_path' => 'team-photos/home.png']);
        Storage::disk('team-disk')->put('team-photos/home.png', 'png');
        Storage::disk('public')->put('team-photos/home.png', 'unrelated');

        (new DeleteTeam)->delete($team);

        Storage::disk('team-disk')->assertMissing('team-photos/home.png');
        Storage::disk('public')->assertExists('team-photos/home.png');
    }

    public function test_a_team_model_without_soft_deletes_is_deleted_as_before(): void
    {
        MagicStarter::useTeamModel(ConcreteTeam::class);
        // The invitations key is inferred from the runtime class name.
        Schema::table('team_invitations', function (Blueprint $table): void {
            $table->uuid('concrete_team_id')->nullable();
        });
        $team = ConcreteTeam::query()->create([
            'user_id' => $this->makeUser()->getKey(),
            'name' => 'Home',
            'personal_team' => false,
        ]);

        (new DeleteTeam)->delete($team);

        $this->assertNull(ConcreteTeam::query()->find($team->getKey()));
    }
}
