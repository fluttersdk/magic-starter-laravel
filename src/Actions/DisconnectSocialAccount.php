<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\DisconnectsSocialAccounts;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Models\SocialAccount;
use FlutterSdk\MagicStarter\Social\AppleProviderFactory;
use FlutterSdk\MagicStarter\Social\SocialSignInRefused;
use FlutterSdk\MagicStarter\Support\UserPassword;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Default action unlinking a provider identity, never the last way into a
 * password-less account.
 *
 * A revoked identity (`revoked_at` set) signs nobody in, so it neither counts
 * as another method nor needs the guard to be removed.
 */
class DisconnectSocialAccount implements DisconnectsSocialAccounts
{
    public function __construct(
        protected AppleProviderFactory $apple,
    ) {}

    /**
     * Unlink the user's identity at the provider.
     *
     * @throws SocialSignInRefused
     */
    public function disconnect(Authenticatable $user, string $provider): void
    {
        $account = DB::transaction(function () use ($user, $provider): Model {
            // 1. Lock the user row. Two concurrent disconnects of a password-less
            //    user's last two identities would otherwise each count the other
            //    as still linked, and both would succeed.
            $userModel = MagicStarter::userModel();

            $locked = $userModel::query()
                ->whereKey($user->getAuthIdentifier())
                ->lockForUpdate()
                ->firstOrFail();

            $socialAccountModel = MagicStarter::socialAccountModel();

            $account = $socialAccountModel::query()
                ->where('user_id', $locked->getKey())
                ->where('provider', $provider)
                ->firstOrFail();

            // 2. Refuse to remove the last active way in: the server holds this
            //    line, whatever the client chose to show.
            $removesAMethod = $account->getAttribute('revoked_at') === null;

            if ($removesAMethod && ! UserPassword::isSet($locked) && ! $this->holdsAnotherActiveAccount($account)) {
                throw new SocialSignInRefused('last_login_method');
            }

            $account->delete();

            return $account;
        });

        // 3. Revoke the Apple grant once the unlink has committed, so a refused
        //    or rolled-back disconnect never revokes a grant whose link stays,
        //    and no provider call runs under the row lock. A failed revoke is
        //    reported by the factory and does not undo the disconnect.
        if ($account instanceof SocialAccount && $account->provider === 'apple') {
            $this->apple->revoke($account);
        }
    }

    /**
     * Whether the account's user holds another identity that still signs in.
     */
    private function holdsAnotherActiveAccount(Model $account): bool
    {
        return $account->newQuery()
            ->where('user_id', $account->getAttribute('user_id'))
            ->whereKeyNot($account->getKey())
            ->whereNull('revoked_at')
            ->exists();
    }
}
