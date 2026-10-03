<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\ConnectsSocialAccounts;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Social\SocialSignInRefused;
use FlutterSdk\MagicStarter\Social\VerifiedIdentity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Default action linking a verified provider identity to a user.
 *
 * Linking the identity the user already holds re-confirms that link instead of
 * refusing it: the revocation mark clears and the address and Apple secrets
 * the provider sent this time replace the recorded ones. That is also how the
 * resolver refreshes a link on every sign-in, which is why a refresh keeps the
 * stored `owner_confirmed` unless the caller states one.
 */
class ConnectSocialAccount implements ConnectsSocialAccounts
{
    /**
     * Link the identity to the user, or re-confirm the link the user already holds.
     *
     * @param  string|null  $refreshToken  the Apple refresh token, kept only to revoke the grant later
     * @param  string|null  $clientId  the provider client id the credential was issued to
     * @param  bool|null  $ownerConfirmed  null keeps a held link's value and derives a new link's
     * @return Model the link, an instance of `MagicStarter::socialAccountModel()`
     *
     * @throws SocialSignInRefused
     */
    public function connect(
        Authenticatable $user,
        VerifiedIdentity $identity,
        ?string $refreshToken = null,
        ?string $clientId = null,
        ?bool $ownerConfirmed = null,
    ): Model {
        $socialAccountModel = MagicStarter::socialAccountModel();
        $userId = (string) $user->getAuthIdentifier();

        // 1. The identity's own link decides first: it is either this user's,
        //    which is re-confirmed, or someone else's, which is never moved.
        $link = $socialAccountModel::query()
            ->where('provider', $identity->provider)
            ->where('provider_user_id', $identity->providerUserId)
            ->first();

        if ($link !== null) {
            if ((string) $link->user_id !== $userId) {
                throw new SocialSignInRefused('social_account_taken');
            }

            return $this->reconfirm($link, $identity, $refreshToken, $clientId, $ownerConfirmed);
        }

        // 2. One identity per provider: a second Google account on the same
        //    user would make "which one signs in" ambiguous.
        $holdsProvider = $socialAccountModel::query()
            ->where('user_id', $userId)
            ->where('provider', $identity->provider)
            ->exists();

        if ($holdsProvider) {
            throw new SocialSignInRefused('social_account_taken');
        }

        // 3. Write the link. A concurrent connect that got past the same reads
        //    trips one of the two unique indexes, and is refused the same way.
        try {
            return $socialAccountModel::query()->create([
                'user_id' => $user->getAuthIdentifier(),
                'provider' => $identity->provider,
                'provider_user_id' => $identity->providerUserId,
                'tenant_id' => $identity->tenantId,
                'email_at_link' => $identity->email,
                'client_id' => $clientId,
                'refresh_token' => $refreshToken,
                'owner_confirmed' => $ownerConfirmed ?? ! $this->isProvisional($user),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new SocialSignInRefused('social_account_taken', $exception);
        }
    }

    /**
     * Clear the revocation mark and take what the provider sent this time.
     *
     * A value the provider withheld keeps the recorded one: Apple sends the
     * address only on the first authorisation, and no refresh token on a plain
     * sign-in. So does a null `$ownerConfirmed`.
     */
    private function reconfirm(
        Model $link,
        VerifiedIdentity $identity,
        ?string $refreshToken,
        ?string $clientId,
        ?bool $ownerConfirmed,
    ): Model {
        $link->forceFill(array_filter(
            [
                'email_at_link' => $identity->email,
                'tenant_id' => $identity->tenantId,
                'client_id' => $clientId,
                'refresh_token' => $refreshToken,
            ],
            fn (?string $value): bool => $value !== null,
        ));
        $link->forceFill([
            'revoked_at' => null,
        ]);

        if ($ownerConfirmed !== null) {
            $link->forceFill([
                'owner_confirmed' => $ownerConfirmed,
            ]);
        }

        $link->save();

        return $link;
    }

    /**
     * Whether a new link on this account waits for the mailbox owner.
     *
     * It does while the account holds an unconfirmed link, and while its
     * address is unverified: otherwise whoever signed up through an unverified
     * identity could drop that link, connect a fresh one, and keep it through
     * the owner's password reset.
     */
    private function isProvisional(Authenticatable $user): bool
    {
        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return true;
        }

        return MagicStarter::socialAccountModel()::query()
            ->where('user_id', (string) $user->getAuthIdentifier())
            ->where('owner_confirmed', false)
            ->exists();
    }
}
