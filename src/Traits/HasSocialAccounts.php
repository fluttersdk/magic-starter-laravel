<?php

namespace FlutterSdk\MagicStarter\Traits;

use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Gives a user model the provider identities linked to it.
 *
 * Sign-in is keyed on a `social_accounts` row (provider plus the provider's own
 * user id), never on the email address, so this is the relation every social
 * flow starts from.
 */
trait HasSocialAccounts
{
    /**
     * Get the provider identities linked to the user.
     *
     * @return HasMany<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(MagicStarter::socialAccountModel(), 'user_id');
    }

    /**
     * Determine whether the user can sign in with a password.
     *
     * A social-only account carries a null or empty password, and the client
     * needs to know so it offers "set a password" instead of "change password".
     */
    public function hasPassword(): bool
    {
        return (string) $this->getAuthPassword() !== '';
    }
}
