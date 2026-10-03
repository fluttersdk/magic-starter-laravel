<?php

namespace FlutterSdk\MagicStarter\Social;

use RuntimeException;
use Throwable;

/**
 * A verified provider identity that may not sign in or link as asked.
 *
 * Unlike {@see InvalidIdentityException}, the credential itself was fine: the
 * refusal is about what the identity would do to an account (claim an address
 * someone already holds, take over another user's link). The message is the
 * translated `magic-starter::social.<code>` sentence a client shows verbatim,
 * and `code()` is the stable machine code it switches on, so a controller
 * answers `{message, code}` without knowing which refusal it caught.
 */
class SocialSignInRefused extends RuntimeException
{
    /**
     * @param  string  $refusal  a key of `lang/<locale>/social.php`, such as `social_email_taken`
     */
    public function __construct(
        private readonly string $refusal,
        ?Throwable $previous = null,
    ) {
        parent::__construct((string) __('magic-starter::social.' . $refusal), 0, $previous);
    }

    /**
     * The stable snake_case code clients switch on.
     */
    public function code(): string
    {
        return $this->refusal;
    }
}
