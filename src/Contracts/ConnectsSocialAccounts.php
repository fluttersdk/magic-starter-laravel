<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Social\SocialSignInRefused;
use FlutterSdk\MagicStarter\Social\VerifiedIdentity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Links a verified provider identity to a user.
 *
 * An identity belongs to exactly one user and a user holds one identity per
 * provider; an implementation refuses rather than moves a link, because moving
 * one would hand an account to whoever controls the provider identity.
 */
interface ConnectsSocialAccounts
{
    /**
     * Link the identity to the user and return the `social_accounts` row.
     *
     * @param  string|null  $refreshToken  the Apple refresh token, kept only to revoke the grant later
     * @param  string|null  $clientId  the provider client id the credential was issued to
     * @return Model the link, an instance of `MagicStarter::socialAccountModel()`
     *
     * @throws SocialSignInRefused `social_account_taken` when another user owns the identity
     *                             or the user already holds another identity of that provider
     */
    public function connect(
        Authenticatable $user,
        VerifiedIdentity $identity,
        ?string $refreshToken = null,
        ?string $clientId = null,
    ): Model;
}
