<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for creating a newly registered user.
 */
interface CreatesUsers
{
    /**
     * Create a newly registered user.
     *
     * @param  array<string, mixed>  $input  The validated registration data.
     * @return Authenticatable The created user instance.
     */
    public function create(array $input): Authenticatable;
}
