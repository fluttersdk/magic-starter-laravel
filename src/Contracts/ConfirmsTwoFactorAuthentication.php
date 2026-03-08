<?php

namespace FlutterSdk\MagicStarter\Contracts;

interface ConfirmsTwoFactorAuthentication
{
    /**
     * Confirm two factor authentication for the user.
     */
    public function confirm(mixed $user, string $code): void;
}
