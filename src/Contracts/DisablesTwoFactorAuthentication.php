<?php

namespace FlutterSdk\MagicStarter\Contracts;

interface DisablesTwoFactorAuthentication
{
    /**
     * Disable two factor authentication for the user.
     */
    public function disable(mixed $user): void;
}
