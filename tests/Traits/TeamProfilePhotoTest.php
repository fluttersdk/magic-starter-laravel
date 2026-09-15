<?php

namespace FlutterSdk\MagicStarter\Tests\Traits;

use FlutterSdk\MagicStarter\Models\Team;
use FlutterSdk\MagicStarter\Tests\TestCase;

/**
 * The team half of the same switch.
 *
 * Both sides move together on purpose: a client that stops receiving one and
 * keeps receiving the other has two kinds of avatar on a single screen, so the
 * team branch is worth its own assertion rather than being assumed from the
 * user's.
 */
final class TeamProfilePhotoTest extends TestCase
{
    public function test_a_team_falls_back_to_a_generated_avatar_by_default(): void
    {
        $team = new Team;
        $team->name = 'Acme Ops';

        $this->assertStringStartsWith(
            'https://ui-avatars.com/api/?name=A+O',
            (string) $team->profile_photo_url,
        );
    }

    public function test_an_empty_ui_avatars_url_sends_null_for_a_team_too(): void
    {
        config(['magic-starter.ui_avatars_url' => '']);

        $team = new Team;
        $team->name = 'Acme Ops';

        $this->assertNull($team->profile_photo_url);
    }
}
