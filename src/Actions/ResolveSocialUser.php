<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\ConnectsSocialAccounts;
use FlutterSdk\MagicStarter\Contracts\CreatesUsersFromProvider;
use FlutterSdk\MagicStarter\Contracts\ResolvesSocialUsers;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Social\SocialSignInRefused;
use FlutterSdk\MagicStarter\Social\VerifiedIdentity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

/**
 * Default action deciding which user a verified provider identity signs in as.
 *
 * The order is the security property. A link answers first; an address an
 * unlinked account already holds is refused and never linked, because a
 * provider's email claim does not prove the person owns that account (the
 * owner signs in and links the provider from their profile instead); only an
 * identity that is new on both counts creates a user.
 *
 * Two first sign-ins of one identity can race past the empty reads. Both
 * creations run in a transaction guarded by the unique indexes on
 * `users.email` and `social_accounts (provider, provider_user_id)`, so the
 * loser's write fails and rolls back whole, and the loser answers with the
 * winner's user rather than an error.
 */
class ResolveSocialUser implements ResolvesSocialUsers
{
    public function __construct(
        protected CreatesUsersFromProvider $creator,
        protected ConnectsSocialAccounts $connector,
    ) {}

    /**
     * Resolve the identity to its linked user, or create one for a new identity.
     *
     * @param  Request  $request  the sign-in request, read for locale and timezone of a new user
     * @return Authenticatable the user to issue a token for
     *
     * @throws SocialSignInRefused
     */
    public function resolve(VerifiedIdentity $identity, Request $request): Authenticatable
    {
        // 1. A linked identity signs in as its user, whatever address it reports now.
        $linked = $this->linkedUser($identity);

        if ($linked !== null) {
            return $linked;
        }

        // 2. An unlinked account holding the address is refused, never linked.
        $this->refuseTakenAddress($identity);

        // 3. A new account needs an address to recover it by.
        if ($identity->email === null) {
            throw new SocialSignInRefused('provider_email_missing');
        }

        // 4. Create. When a concurrent first sign-in committed first, this
        //    write trips a unique index (or the connector's own read of the
        //    winner's link) and rolls back; the winner's link then answers.
        try {
            return $this->creator->create($identity, $request);
        } catch (UniqueConstraintViolationException|SocialSignInRefused $lost) {
            $winner = $this->linkedUser($identity);

            if ($winner !== null) {
                return $winner;
            }

            // A password registration took the address in the meantime.
            $this->refuseTakenAddress($identity);

            throw $lost;
        }
    }

    /**
     * The user the identity is linked to, with the link re-confirmed, or null.
     */
    private function linkedUser(VerifiedIdentity $identity): ?Authenticatable
    {
        $link = MagicStarter::socialAccountModel()::query()
            ->where('provider', $identity->provider)
            ->where('provider_user_id', $identity->providerUserId)
            ->first();

        if ($link === null) {
            return null;
        }

        // The user_id foreign key cascades, so a link always has its user.
        $user = MagicStarter::userModel()::query()->findOrFail($link->user_id);

        $this->connector->connect($user, $identity);

        return $user;
    }

    /**
     * Refuse an identity whose address an account already holds.
     *
     * Exact comparison is enough: the identity lower-cases its address and
     * every package write path stores addresses lower-cased.
     *
     * @throws SocialSignInRefused
     */
    private function refuseTakenAddress(VerifiedIdentity $identity): void
    {
        if ($identity->email === null) {
            return;
        }

        $taken = MagicStarter::userModel()::query()
            ->where('email', $identity->email)
            ->exists();

        if ($taken) {
            throw new SocialSignInRefused('social_email_taken');
        }
    }
}
