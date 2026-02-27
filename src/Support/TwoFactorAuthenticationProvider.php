<?php

namespace FlutterSdk\MagicStarter\Support;

use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP-based two-factor authentication provider.
 *
 * Wraps the Google2FA engine to generate secrets, verify one-time codes,
 * and produce QR code URLs for authenticator app enrollment.
 */
class TwoFactorAuthenticationProvider
{
    /**
     * Create a new two-factor authentication provider instance.
     *
     * @param  Google2FA  $engine  The Google2FA TOTP engine.
     */
    public function __construct(
        public Google2FA $engine,
    ) {}

    /**
     * Generate a new secret key for the user.
     *
     * @return string The base32-encoded secret key.
     */
    public function generateSecretKey(): string
    {
        return $this->engine->generateSecretKey();
    }

    /**
     * Verify the given TOTP code against the secret.
     *
     * Uses a window of 1 to allow for slight clock drift between
     * the server and the user's authenticator application.
     *
     * @param  string  $secret  The user's base32-encoded secret key.
     * @param  string  $code  The TOTP code to verify.
     * @return bool Whether the code is valid.
     */
    public function verify(string $secret, string $code): bool
    {
        return (bool) $this->engine->verifyKey($secret, $code, 1);
    }

    /**
     * Generate the otpauth:// URI for QR code enrollment.
     *
     * @param  string  $companyName  The issuer name displayed in the authenticator app.
     * @param  string  $userEmail  The user's email address used as the account identifier.
     * @param  string  $secret  The base32-encoded secret key.
     * @return string The otpauth:// URI.
     */
    public function qrCodeUrl(
        string $companyName,
        string $userEmail,
        string $secret,
    ): string {
        return $this->engine->getQRCodeUrl($companyName, $userEmail, $secret);
    }
}
