<?php

namespace FlutterSdk\MagicStarter\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string $user_id
 * @property string|null $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
abstract class TeamUser extends Pivot
{
    use ConditionallyUsesUuids;

    protected $table = 'team_user';
}
