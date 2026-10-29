<?php

namespace FlutterSdk\MagicStarter\Tests\Fixtures;

use Laravel\Sanctum\HasApiTokens;

/**
 * A user whose `current_team_id` is kept out of `$fillable`, as a real
 * application keeps it: a write that goes through the model drops the pointer
 * silently, so only a query-builder update moves it.
 */
class RestorableTeamUser extends ConcreteUser
{
    use HasApiTokens;

    protected $fillable = [
        'name',
        'email',
    ];
}
