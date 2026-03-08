<?php

namespace FlutterSdk\MagicStarter\Contracts;

interface EnablesTwoFactorAuthentication
{
    /**
     * Enable two factor authentication for the user.
     *
     * @return array<string, mixed>
     */
    public function enable(mixed $user): array;
}
