<?php

namespace FlutterSdk\MagicStarter\Social;

use FlutterSdk\MagicStarter\Models\SocialAccount;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Severs the provider links a user's mailbox owner never confirmed.
 *
 * An account may be created from a provider identity whose address the
 * provider did not verify, so it can sit on somebody else's address. Once the
 * mailbox owner proves control (a password reset, the verification link), the
 * links that came without that proof go, and every session with them: a token
 * issued to whoever held the identity would otherwise outlive the recovery.
 */
class UnconfirmedLinks
{
    public function __construct(
        protected AppleProviderFactory $apple,
    ) {}

    /**
     * Delete the user's unconfirmed links, and revoke all its tokens when any went.
     *
     * A user model without `HasSocialAccounts`, or an application without the
     * `social_accounts` table, holds no link to sever.
     *
     * @return int the number of links severed
     */
    public function sever(Authenticatable $user): int
    {
        if (! method_exists($user, 'socialAccounts')) {
            return 0;
        }

        if (! Schema::hasTable($user->socialAccounts()->getRelated()->getTable())) {
            return 0;
        }

        $severed = DB::transaction(function () use ($user): Collection {
            // 1. Lock the rows first, so a concurrent sign-in cannot refresh a
            //    link between the read and the delete.
            $links = $user->socialAccounts()
                ->where('owner_confirmed', false)
                ->lockForUpdate()
                ->get();

            if ($links->isEmpty()) {
                return $links;
            }

            // 2. Delete exactly the rows read, and end every session: none of
            //    them can be told apart from one the link's holder signed in.
            $user->socialAccounts()->whereKey($links->modelKeys())->delete();

            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }

            return $links;
        });

        // 3. Revoke Apple grants only once the unlink has committed, and outside
        //    the transaction; the factory reports a failure rather than throwing.
        $severed->each(function (Model $link): void {
            if ($link instanceof SocialAccount && $link->provider === 'apple') {
                $this->apple->revoke($link);
            }
        });

        return $severed->count();
    }
}
