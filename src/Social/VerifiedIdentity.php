<?php

namespace FlutterSdk\MagicStarter\Social;

/**
 * Who a provider vouched for, after its credential passed verification.
 *
 * Only a verifier constructs one, so holding an instance means the signature,
 * issuer, audience, expiry and replay checks already passed. `providerUserId`
 * is the identity; the email is what the provider reported this time and is
 * lower-cased so comparisons against stored addresses are exact.
 */
final readonly class VerifiedIdentity
{
    /**
     * The address as the provider reported it, lower-cased, or null when withheld.
     */
    public ?string $email;

    /**
     * @param  string  $provider  the provider key (`google`, `apple`, ...)
     * @param  string  $providerUserId  the provider's stable subject id
     * @param  bool  $emailVerified  whether the provider vouches for the email
     * @param  string|null  $tenantId  the directory tenant, for providers that have one (Microsoft)
     */
    public function __construct(
        public string $provider,
        public string $providerUserId,
        ?string $email,
        public bool $emailVerified,
        public ?string $name = null,
        public ?string $avatar = null,
        public ?string $tenantId = null,
    ) {
        $this->email = $email === null ? null : mb_strtolower($email);
    }
}
