<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Contract for claiming a guest session into an existing account.
 */
interface ClaimsGuestAccounts
{
    /**
     * Claim the guest session the input names into the given account.
     *
     * @param  Authenticatable  $target  The account the rows move to. Must be the authenticated caller.
     * @param  array<string, mixed>  $input  The request payload (requires guest_token).
     * @return bool True when a guest was claimed, false when there was no live guest session to claim.
     *
     * @throws ValidationException When the presented token names something that cannot be claimed.
     */
    public function claim(Authenticatable $target, array $input): bool;
}
