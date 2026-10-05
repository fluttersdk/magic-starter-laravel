<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for revoking a user's Sanctum tokens, which signs the devices
 * holding them out.
 */
interface RevokesApiTokens
{
    /**
     * Revoke one of the user's tokens, or all of them.
     *
     * Refuses nothing: a token id the user does not own revokes nothing.
     *
     * @param  Authenticatable  $user  A user with Sanctum's `HasApiTokens`.
     * @param  string|null  $tokenId  The token to revoke; null revokes every token.
     */
    public function revoke(Authenticatable $user, ?string $tokenId = null): void;
}
