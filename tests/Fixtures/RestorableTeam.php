<?php

namespace FlutterSdk\MagicStarter\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A team model that opted in to trashing: it uses SoftDeletes, as the published
 * `App\Models\Team` stub does, where {@see ConcreteTeam} does not.
 */
class RestorableTeam extends ConcreteTeam
{
    use SoftDeletes;
}
