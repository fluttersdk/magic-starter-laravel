<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Social\SocialSignInRefused;
use FlutterSdk\MagicStarter\Social\VerifiedIdentity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Decides which user a verified provider identity signs in as.
 *
 * The answer is keyed on the linked `social_accounts` row, never on the email:
 * an implementation must not attach an identity to an existing account because
 * the addresses match, since a provider's email claim is not proof that the
 * person owns the account holding that address.
 */
interface ResolvesSocialUsers
{
    /**
     * Resolve the identity to its linked user, or create one for a new identity.
     *
     * @param  Request  $request  the sign-in request, read for locale and timezone of a new user
     * @return Authenticatable the user to issue a token for
     *
     * @throws SocialSignInRefused `social_email_taken` when an unlinked account holds the address,
     *                             `provider_email_missing` when a new identity carries no address
     */
    public function resolve(VerifiedIdentity $identity, Request $request): Authenticatable;
}
