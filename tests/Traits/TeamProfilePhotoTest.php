<?php

namespace FlutterSdk\MagicStarter\Tests\Traits;

use FlutterSdk\MagicStarter\Models\Team;
use FlutterSdk\MagicStarter\Tests\TestCase;

/**
 * The team half of the user's photo contract.
 *
 * Both sides move together on purpose: a client that stops receiving one and
 * keeps receiving the other has two kinds of avatar on a single screen, so the
 * team branch is worth its own assertion rather than being assumed from the
 * user's.
 */
final class TeamProfilePhotoTest extends TestCase
{
    public function test_a_team_with_no_photo_answers_null(): void
    {
        $team = new Team;
        $team->name = 'Acme Ops';

        $this->assertNull($team->profile_photo_url);
    }

    public function test_a_published_config_still_naming_ui_avatars_generates_nothing_for_a_team(): void
    {
        config(['magic-starter.ui_avatars_url' => 'https://ui-avatars.com/api/']);

        $team = new Team;
        $team->name = 'Acme Ops';

        $this->assertNull($team->profile_photo_url);
    }

    public function test_a_team_with_a_stored_photo_answers_its_url(): void
    {
        config([
            'magic-starter.profile_photo_disk' => 'team-photos',
            'filesystems.disks.team-photos' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/team-photos'),
                'url' => 'https://cdn.example.test/team-photos',
                'visibility' => 'public',
            ],
        ]);

        $team = new Team;
        $team->name = 'Acme Ops';
        $team->profile_photo_path = 'acme.png';

        $this->assertSame('https://cdn.example.test/team-photos/acme.png', $team->profile_photo_url);
    }
}
