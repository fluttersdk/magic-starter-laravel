<?php

namespace FlutterSdk\MagicStarter\Traits;

/**
 * Trait HasGuestSupport
 *
 * Provides helper methods to determine if a user is a guest or a registered user.
 */
trait HasGuestSupport
{
    /**
     * Determine if the user is a guest.
     */
    public function isGuest(): bool
    {
        return (bool) $this->is_guest;
    }

    /**
     * Determine if the user is a registered user.
     *
     * A registered user is not a guest and has either an email/password combination
     * or a phone/password combination.
     */
    public function isRegistered(): bool
    {
        return ! $this->isGuest() &&
            (
                ($this->email !== null && $this->password !== null) ||
                ($this->phone !== null && $this->password !== null)
            );
    }
}
