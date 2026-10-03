<?php

namespace FlutterSdk\MagicStarter\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Whether a user can sign in with a password.
 *
 * `hasPassword()` comes from the package's `HasSocialAccounts` trait, which a
 * consumer User need not use; calling it unguarded breaks every such app. A
 * model that defines it answers for itself, any other is judged by its stored
 * password.
 */
final class UserPassword
{
    public static function isSet(Authenticatable $user): bool
    {
        if (method_exists($user, 'hasPassword')) {
            return (bool) $user->hasPassword();
        }

        return (string) $user->getAuthPassword() !== '';
    }
}
