<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Tests\Fixtures;

use FlutterSdk\MagicStarter\Models\TeamInvitation;

class ConcreteTeamInvitation extends TeamInvitation
{
    protected $table = 'team_invitations';
}
