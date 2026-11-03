<?php

namespace FlutterSdk\MagicStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for minting the one-time code a sign-in handoff carries to another origin.
 */
interface IssuesHandoffCodes
{
    /**
     * Mint a single-use code that signs in the given user.
     *
     * @param  Authenticatable  $user  The signed-in caller; a code never names anyone else.
     * @param  string|null  $next  A same-app path the app opens after the sign-in, already validated.
     * @return array{code: string, expires_in: int} The raw code, never stored as is, and its lifetime in seconds.
     */
    public function issue(Authenticatable $user, ?string $next): array;
}
