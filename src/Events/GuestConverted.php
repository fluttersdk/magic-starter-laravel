<?php

namespace FlutterSdk\MagicStarter\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A guest has been promoted to a registered account.
 *
 * Fired once per promotion, from whichever action completed it: a profile
 * update that supplies the last missing credential, or a guest setting its
 * first password. The seam for a host that treats a registered account
 * differently, such as a welcome message or a conversion metric.
 *
 * Dispatched after the enclosing transaction commits, so a listener reads the
 * promoted row and a rolled back promotion never reaches one.
 */
class GuestConverted implements ShouldDispatchAfterCommit
{
    /**
     * @param  Authenticatable  $user  The promoted account, `is_guest` already false and `device_id` released.
     */
    public function __construct(
        public readonly Authenticatable $user,
    ) {}
}
