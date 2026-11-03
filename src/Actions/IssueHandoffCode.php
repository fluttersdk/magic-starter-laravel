<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\IssuesHandoffCodes;
use FlutterSdk\MagicStarter\Handoff\HandoffStore;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Default action for minting a sign-in handoff code.
 */
class IssueHandoffCode implements IssuesHandoffCodes
{
    public function __construct(
        protected HandoffStore $store,
    ) {}

    /**
     * Mint a single-use code that signs in the given user.
     *
     * @return array{code: string, expires_in: int}
     */
    public function issue(Authenticatable $user, ?string $next): array
    {
        return [
            'code' => $this->store->mint($user, $next),
            'expires_in' => $this->store->ttl(),
        ];
    }
}
