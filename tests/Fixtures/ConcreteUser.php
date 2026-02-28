<?php

namespace FlutterSdk\MagicStarter\Tests\Fixtures;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;

class ConcreteUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use Authorizable;
    use ConditionallyUsesUuids;
    use \FlutterSdk\MagicStarter\Traits\HasGuestSupport;
    use \FlutterSdk\MagicStarter\Traits\HasNotifications;
    use \FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
    use \FlutterSdk\MagicStarter\Traits\HasTeams;

    protected $table = 'users';

    protected $guarded = [];
}
