<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\RevokesApiTokens;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Default action for revoking a user's Sanctum tokens.
 */
class RevokeApiTokens implements RevokesApiTokens
{
    /**
     * Revoke one of the user's tokens, or all of them.
     *
     * Scoped through the user's own `tokens()` relation, so an id belonging to
     * another account matches nothing.
     *
     * @param  Authenticatable  $user  A user with Sanctum's `HasApiTokens`.
     * @param  string|null  $tokenId  The token to revoke; null revokes every token.
     */
    public function revoke(Authenticatable $user, ?string $tokenId = null): void
    {
        $tokens = $user->tokens();

        if ($tokenId !== null) {
            $tokens->whereKey($tokenId);
        }

        $tokens->delete();
    }
}
