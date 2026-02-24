<?php

namespace FlutterSdk\MagicStarter\Contracts;

interface DeletesUsers
{
    /**
     * Delete the given user.
     */
    public function delete(mixed $user): void;
}
