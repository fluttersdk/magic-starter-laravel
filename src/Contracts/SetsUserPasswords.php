<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Social\SocialSignInRefused;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Gives an account that signs in only through a provider its first password.
 *
 * Unlike {@see UpdatesUserPasswords} there is no current password to prove,
 * so this may only ever write the first one: a session that could overwrite an
 * existing password without knowing it would turn a stolen token into a
 * stolen account.
 */
interface SetsUserPasswords
{
    /**
     * Validate and save the user's first password.
     *
     * @param  array<string, mixed>  $input  `password` and `password_confirmation`
     *
     * @throws SocialSignInRefused `password_already_set` when the user already has one.
     * @throws ValidationException When the password breaks the package rules.
     */
    public function set(Authenticatable $user, array $input): void;
}
