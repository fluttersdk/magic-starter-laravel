<?php

namespace FlutterSdk\MagicStarter\Social;

use FlutterSdk\MagicStarter\Models\SocialAccount;
use GuzzleHttp\Client;
use RuntimeException;
use SocialiteProviders\Apple\Provider;
use SocialiteProviders\Manager\Config;
use Throwable;

/**
 * Builds the Sign in with Apple provider for one client id, and is the single
 * path that revokes an Apple grant.
 *
 * A provider that talks to Apple gets a client secret signed for its own
 * client id; a verifier gets none. Both get an empty `private_key`, which
 * keeps the provider from generating a secret of its own and writing it into
 * the global `services.apple.client_secret`.
 * Audiences come from `magic-starter.social.audiences.apple`, so an identity
 * token minted for the bundle id or the Services ID is accepted by either.
 *
 * Bound as a container singleton by MagicStarterServiceProvider so a test can
 * hand every provider it builds one mocked HTTP client.
 */
class AppleProviderFactory
{
    /**
     * The client every built provider uses; null lets Socialite create its own.
     */
    protected ?Client $httpClient = null;

    public function __construct(
        protected AppleClientSecret $secret,
    ) {}

    /**
     * Build a provider that talks to Apple as the given client.
     *
     * @param  string  $clientId  the bundle id (native) or Services ID (web)
     * @param  string|null  $redirectUrl  the callback for the web code flow; null for a native code or a revocation
     *
     * @throws RuntimeException When Sign in with Apple is not configured.
     */
    public function make(string $clientId, ?string $redirectUrl = null): Provider
    {
        return $this->build($clientId, $this->secret->for($clientId), $redirectUrl ?? '');
    }

    /**
     * Build a provider that only verifies identity tokens issued to the given client.
     *
     * Verification needs Apple's public keys and nothing of ours, so no client
     * secret is signed and a deployment without a signing key still verifies.
     * The provider cannot redeem a code or revoke a grant.
     *
     * @param  string  $clientId  the client whose tokens are accepted, besides the configured audiences
     */
    public function verifier(string $clientId): Provider
    {
        return $this->build($clientId, '', '');
    }

    /**
     * Revoke the Apple grant behind a linked account, as App Store review
     * requires on account deletion.
     *
     * A failure is reported, never thrown: Apple's revocation is best effort
     * and must not block the deletion that asked for it.
     *
     * @return bool true when Apple accepted the revocation; false when the row
     *              holds nothing to revoke or the call failed
     */
    public function revoke(SocialAccount $account): bool
    {
        if ($account->refresh_token === null || $account->client_id === null) {
            return false;
        }

        try {
            $this->make($account->client_id)->revokeToken($account->refresh_token, 'refresh_token');
        } catch (Throwable $failure) {
            report($failure);

            return false;
        }

        return true;
    }

    /**
     * Use the given HTTP client for every provider built from now on.
     */
    public function setHttpClient(Client $client): static
    {
        $this->httpClient = $client;

        return $this;
    }

    /**
     * The provider for one client, with the transport and token-request fixes every Apple call needs.
     *
     * @param  string  $secret  the signed client secret; empty for a verifier
     * @param  string  $redirect  the callback the authorization request carried; empty when it carried none
     */
    protected function build(string $clientId, string $secret, string $redirect): Provider
    {
        $provider = new class(request(), $clientId, $secret, $redirect) extends Provider
        {
            /**
             * The vendor fetches Apple's keys with a bare `new Client`, out of
             * reach of `setHttpClient()`; routing it through the provider's
             * client keeps one injectable transport for every Apple call.
             */
            protected function getJwkHttpClient(): Client
            {
                return $this->getHttpClient();
            }

            /**
             * Apple accepts `redirect_uri` on the token request only when the
             * authorization request carried one, and a native authorization
             * never does; Socialite always sends it, empty or not.
             *
             * @param  string  $code
             * @return array<string, mixed>
             */
            protected function getTokenFields($code): array
            {
                $fields = parent::getTokenFields($code);

                if ($this->redirectUrl === '') {
                    unset($fields['redirect_uri']);
                }

                return $fields;
            }
        };

        $provider->setConfig(new Config($clientId, $secret, $redirect, [
            'private_key' => '',
            'audiences' => (array) config('magic-starter.social.audiences.apple', []),
        ]));

        if ($this->httpClient !== null) {
            $provider->setHttpClient($this->httpClient);
        }

        return $provider;
    }
}
