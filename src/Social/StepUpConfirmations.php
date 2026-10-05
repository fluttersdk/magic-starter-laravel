<?php

namespace FlutterSdk\MagicStarter\Social;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Single-use proof that a user just re-authenticated, for a sensitive action
 * that cannot ask a social-only account for a password.
 *
 * A confirmation is minted after a `confirm` flow proved the caller still
 * controls an identity linked to the account, and the sensitive endpoint
 * consumes it. It is bound to the user it was minted for, so a token leaked
 * from one account confirms nothing on another, and it lives for
 * `magic-starter.social.confirmation_ttl` seconds.
 */
class StepUpConfirmations
{
    public function __construct(
        protected SocialFlowStore $store,
    ) {}

    /**
     * Mint a confirmation for the user.
     *
     * @return string the opaque token the client presents to the sensitive endpoint
     */
    public function mint(Authenticatable $user): string
    {
        return $this->store->put('confirmation', [
            'user_id' => (string) $user->getAuthIdentifier(),
        ], $this->ttl());
    }

    /**
     * Spend a confirmation; true only when it was live and minted for this user.
     *
     * The token is spent whoever presents it, so a token offered on the wrong
     * account cannot be retried on the right one.
     */
    public function consume(Authenticatable $user, string $token): bool
    {
        $confirmation = $this->store->pull('confirmation', $token, $this->ttl());

        return $confirmation !== null
            && hash_equals((string) $confirmation['user_id'], (string) $user->getAuthIdentifier());
    }

    protected function ttl(): int
    {
        return (int) config('magic-starter.social.confirmation_ttl', 600);
    }
}
