<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Social\VerifiedIdentity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Creates the account a brand new provider identity signs in as.
 *
 * Called only once the resolver has established that no link exists for the
 * identity and no account holds its address. The user gets no password, and an
 * implementation must write the user, its `social_accounts` row and whatever
 * `Registered` triggers atomically, so a failed link never leaves an account
 * nobody can sign in to.
 */
interface CreatesUsersFromProvider
{
    /**
     * Create the user and link the identity to it.
     *
     * @param  Request  $request  read for the new user's locale and timezone
     * @return Authenticatable the created user
     */
    public function create(VerifiedIdentity $identity, Request $request): Authenticatable;
}
