<?php

namespace FlutterSdk\MagicStarter\Tests\Actions;

use FlutterSdk\MagicStarter\Actions\UpdateTeam;
use FlutterSdk\MagicStarter\Tests\Fixtures\BuildsRestorableTeamSchema;
use FlutterSdk\MagicStarter\Tests\Fixtures\VisibilityRecordingDisk;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The team photo half of `UpdatesTeams`: the photo goes to the team disk under
 * a random name, without an ACL, and replaces the one before it.
 */
final class UpdateTeamTest extends TestCase
{
    use BuildsRestorableTeamSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildRestorableTeamSchema();
        config([
            'magic-starter.team_photo_disk' => 'team-disk',
            'magic-starter.profile_photo_disk' => 'public',
        ]);
        Storage::fake('public');
    }

    public function test_a_photo_is_stored_on_the_team_disk_under_a_random_name_without_an_acl(): void
    {
        $disk = VisibilityRecordingDisk::fake('team-disk');
        $owner = $this->makeUser();
        $team = $this->makeTeam($owner);

        (new UpdateTeam)->update($owner, $team, [
            'name' => 'Renamed',
            'photo' => UploadedFile::fake()->image('logo.png'),
        ]);
        $first = (string) $team->profile_photo_path;

        (new UpdateTeam)->update($owner, $team, [
            'name' => 'Renamed',
            'photo' => UploadedFile::fake()->image('logo.png'),
        ]);
        $second = (string) $team->profile_photo_path;

        $this->assertSame('Renamed', $team->fresh()?->name);
        $this->assertSame([], $disk->visibilityCalls);
        $this->assertMatchesRegularExpression('#^team-photos/[A-Za-z0-9]{40}\.png$#', $first);
        $this->assertNotSame($first, $second);
        $disk->assertMissing($first);
        $this->assertNotSame('', $disk->get($second));
        Storage::disk('public')->assertMissing($second);
    }
}
