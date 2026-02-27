<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for creating or finding a guest user by device ID.
 */
interface CreatesGuestUsers
{
    /**
     * Create or find a guest user.
     *
     * @param  array<string, mixed>  $input  The guest user data (requires device_id).
     * @return Authenticatable The guest user instance.
     */
    public function create(array $input): Authenticatable;
}
