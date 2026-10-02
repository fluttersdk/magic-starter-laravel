<?php

namespace FlutterSdk\MagicStarter\Social;

use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use RuntimeException;
use SocialiteProviders\Apple\Provider;

/**
 * Signs the client secret Apple expects for one client id.
 *
 * Apple's client secret is an ES256 JWT whose `sub` must equal the client id
 * the request is made as. The app talks to Apple as two clients (the bundle id
 * for native sign-in, the Services ID for the web flow), while the provider's
 * own `AppleToken` signs `sub` from the single global `services.apple.client_id`.
 * Signing here keeps each secret bound to its client without mutating config.
 */
class AppleClientSecret
{
    /**
     * Apple accepts a secret for at most six months; one hour keeps a leaked
     * secret short-lived and costs one signature per provider build.
     */
    public const TTL_SECONDS = 3600;

    /**
     * Sign a fresh secret for the given client.
     *
     * @param  string  $clientId  the bundle id or Services ID the request is made as
     *
     * @throws RuntimeException When the team id, key id or private key is not configured.
     */
    public function for(string $clientId): string
    {
        $issuedAt = Carbon::now()->getTimestamp();

        return JWT::encode(
            [
                'iss' => $this->setting('team_id'),
                'iat' => $issuedAt,
                'exp' => $issuedAt + self::TTL_SECONDS,
                'aud' => Provider::URL,
                'sub' => $clientId,
            ],
            $this->privateKey(),
            'ES256',
            $this->setting('key_id'),
        );
    }

    /**
     * The `.p8` key contents; the setting holds either a filesystem path or the PEM text itself.
     */
    protected function privateKey(): string
    {
        $key = $this->setting('private_key');

        if (! is_file($key)) {
            return $key;
        }

        $contents = file_get_contents($key);

        if ($contents === false) {
            throw new RuntimeException("The Sign in with Apple private key at [{$key}] could not be read.");
        }

        return $contents;
    }

    /**
     * One `magic-starter.social.apple` setting, which must be a non-empty string.
     */
    protected function setting(string $key): string
    {
        $value = config("magic-starter.social.apple.{$key}");

        if (! is_string($value) || $value === '') {
            throw new RuntimeException(
                "Sign in with Apple is not configured: [magic-starter.social.apple.{$key}] is empty.",
            );
        }

        return $value;
    }
}
