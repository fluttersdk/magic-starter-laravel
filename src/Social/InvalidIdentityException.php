<?php

namespace FlutterSdk\MagicStarter\Social;

use RuntimeException;
use Throwable;

/**
 * A sign-in credential that could not be verified as coming from the provider.
 *
 * The message is the translated sentence a client may show. Why the identity
 * was refused (a foreign audience, a reused nonce, a bad signature) lives in
 * `reason`, which is for logs and for `app.debug` responses only: telling a
 * caller which check failed helps an attacker more than it helps a user.
 */
class InvalidIdentityException extends RuntimeException
{
    /**
     * The stable machine code clients switch on.
     */
    public const ERROR_CODE = 'invalid_identity';

    /**
     * @param  string  $reason  which check refused the credential, never shown to the user
     */
    public function __construct(
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct((string) __('magic-starter::social.invalid_identity'), 0, $previous);
    }
}
