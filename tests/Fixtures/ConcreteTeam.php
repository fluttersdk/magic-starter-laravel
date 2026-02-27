<?php

declare(strict_types=1);

namespace FlutterSdk\MagicStarter\Tests\Fixtures;

use FlutterSdk\MagicStarter\Models\Team;

class ConcreteTeam extends Team
{
    protected $table = 'teams';

    protected $fillable = [
        'name',
        'personal_team',
        'user_id',
    ];
}
