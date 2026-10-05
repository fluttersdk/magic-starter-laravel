<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Social\SocialSignInRefused;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Removes a provider identity from a user.
 *
 * The server, not the client, guarantees that an account keeps a way in: an
 * implementation refuses to remove the last active identity of a user who has
 * no password, because nothing could sign that account in afterwards.
 */
interface DisconnectsSocialAccounts
{
    /**
     * Unlink the user's identity at the provider.
     *
     * @throws SocialSignInRefused `last_login_method` when the identity is the
     *                             password-less user's only active one
     * @throws ModelNotFoundException When the user holds no identity of that provider.
     */
    public function disconnect(Authenticatable $user, string $provider): void;
}
