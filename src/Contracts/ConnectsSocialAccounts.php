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
     * `owner_confirmed` records whether the link is known to belong to the owner
     * of the user's mailbox; a proof of mailbox control severs every link where
     * it is false. Null leaves the decision to the link's history: a link the
     * user already holds keeps its stored value, because a sign-in through an
     * identity proves nothing about the mailbox; a new link is unconfirmed while
     * the user still holds an unconfirmed link, since whoever added it may be the
     * person that link let in, and confirmed otherwise. A bool is the caller's
     * word and is written on a new link and on a refresh alike.
     *
     * @param  string|null  $refreshToken  the Apple refresh token, kept only to revoke the grant later
     * @param  string|null  $clientId  the provider client id the credential was issued to
     * @param  bool|null  $ownerConfirmed  true only where the caller has proof of mailbox control
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
        ?bool $ownerConfirmed = null,
    ): Model;
}
