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
 * Every provider gets a client secret signed for its own client id and an
 * empty `private_key`, which keeps the provider from generating a secret of
 * its own and writing it into the global `services.apple.client_secret`.
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
     * @param  string|null  $redirectUrl  the callback for the web code flow; null for token-only use
     *
     * @throws RuntimeException When Sign in with Apple is not configured.
     */
    public function make(string $clientId, ?string $redirectUrl = null): Provider
    {
        $secret = $this->secret->for($clientId);
        $redirect = $redirectUrl ?? '';

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
}
