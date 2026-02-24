<?php

namespace FlutterSdk\MagicStarter\Contracts;

interface CreatesUsers
{
    /**
     * Create a newly registered user.
     */
    public function create(array $input): mixed;
}
