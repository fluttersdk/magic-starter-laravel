<?php

namespace FlutterSdk\MagicStarter\Support;

use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

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
     * Verify a sign-in code and spend it.
     *
     * Records the time step the code matched, so that code and every earlier
     * one are refused afterwards (RFC 6238 section 5.2): an observed code
     * cannot sign in a second time, through the API or the admin panel. The
     * check and the write run under one lock, so two concurrent submissions
     * of the same code cannot both pass.
     *
     * @param  string  $secret  The user's base32-encoded secret key.
     * @param  string  $code  The TOTP code to verify.
     * @return bool Whether the code is valid and was not spent before.
     */
    public function verifyOnce(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code): bool
    {
        $key = 'magic-starter.two-factor-step.' . hash('sha256', $secret);

        return Cache::lock($key . '.lock', 10)->block(10, function () use ($key, $secret, $code): bool {
            // An initial step makes verifyKeyNewer() answer the matched step
            // instead of `true`; one before the window accepts any fresh code.
            $lastStep = Cache::get($key) ?? ($this->engine->getTimestamp() - 2);

            $step = $this->engine->verifyKeyNewer($secret, $code, $lastStep, 1);

            if ($step === false) {
                return false;
            }

            // Two steps outlive the one-step window on either side.
            Cache::put($key, $step, 3 * $this->engine->getKeyRegeneration());

            return true;
        });
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
