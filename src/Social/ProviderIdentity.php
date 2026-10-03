<?php

namespace FlutterSdk\MagicStarter\Social;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\SocialiteManager;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use RuntimeException;
use SocialiteProviders\Apple\Provider as AppleProvider;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

/**
 * Both Socialite legs of a backend-hosted flow, and who each provider vouched for.
 *
 * Every provider runs `stateless()`: the flow has no session, so the state,
 * the provider PKCE verifier and Apple's nonce live in {@see SocialFlowStore}
 * and reach Socialite through ONE `with()` call per leg, because `with()`
 * replaces rather than merges, and its parameters are added to both the
 * authorize url and the token request. `enablePKCE()` and Apple's
 * `cookieNonce()` are not used: both keep their secret in the session.
 * Apple takes `setNonce()` on both legs and no PKCE.
 *
 * The redirect url is the fixed callback route on both legs, since every
 * provider refuses a token request whose redirect_uri differs from the one it
 * authorised.
 *
 * Identity is the provider's stable subject and never the email:
 * GitHub's numeric id, Google's `sub`, Apple's `sub`, and Microsoft's `oid`
 * within its `tid` read from the id_token the provider has just verified
 * (the Graph `id` Socialite maps is a different, per-app value).
 */
class ProviderIdentity
{
    /**
     * The callback path, registered in each provider's console; outside the route prefix so it never moves.
     */
    public const CALLBACK_PATH = 'magic-starter/social/{provider}/callback';

    /**
     * The providers this flow knows how to map; `magic-starter.social.providers` narrows them.
     */
    public const PROVIDERS = [
        'google',
        'apple',
        'github',
        'microsoft',
    ];

    public function __construct(
        protected SocialiteFactory $socialite,
        protected AppleProviderFactory $apple,
    ) {}

    /**
     * Whether the provider is one this flow maps and the deployment allows.
     */
    public function supports(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true)
            && in_array($provider, (array) config('magic-starter.social.providers', []), true);
    }

    /**
     * Whether the deployment configured what the web flow needs; Apple's web flow runs as the Services ID.
     */
    public function isConfigured(string $provider): bool
    {
        return $provider !== 'apple' || $this->appleClientId() !== null;
    }

    /**
     * The per-flow secrets to record with the state: a PKCE verifier, or Apple's nonce.
     *
     * @return array{code_verifier: string|null, nonce: string|null}
     */
    public function secrets(string $provider): array
    {
        if ($provider === 'apple') {
            return [
                'code_verifier' => null,
                'nonce' => Str::random(40),
            ];
        }

        return [
            'code_verifier' => Str::random(64),
            'nonce' => null,
        ];
    }

    /**
     * Send the browser to the provider's authorize endpoint for this flow.
     *
     * @param  string|null  $codeVerifier  our PKCE verifier; only its S256 challenge leaves the server here
     * @param  string|null  $nonce  Apple's nonce
     */
    public function redirect(
        string $provider,
        Request $request,
        string $state,
        ?string $codeVerifier,
        ?string $nonce,
    ): RedirectResponse {
        $driver = $this->driver($provider, $request);

        if ($driver instanceof AppleProvider) {
            return $driver->setNonce((string) $nonce)->with([
                'state' => $state,
            ])->redirect();
        }

        return $driver->with([
            'state' => $state,
            'code_challenge' => SocialFlowStore::challenge((string) $codeVerifier),
            'code_challenge_method' => 'S256',
        ])->redirect();
    }

    /**
     * Redeem the provider's authorization code from the callback and map who it vouched for.
     *
     * Only Apple's refresh token is returned, because it is the one provider
     * token kept (to revoke the grant on account deletion).
     *
     * @param  string|null  $codeVerifier  the verifier recorded at the redirect
     * @param  string|null  $nonce  Apple's nonce recorded at the redirect
     * @return array{identity: VerifiedIdentity, refresh_token: string|null, client_id: string|null}
     *
     * @throws InvalidIdentityException When the provider's answer names no stable subject.
     */
    public function identity(string $provider, Request $request, ?string $codeVerifier, ?string $nonce): array
    {
        // 1. Apple's provider json-decodes the posted `user` into an array-typed
        //    parameter and TypeErrors on anything that is not a JSON object.
        if ($provider === 'apple') {
            $this->dropMalformedAppleUser($request);
        }

        // 2. Redeem the code with the secret only this server holds.
        $driver = $this->driver($provider, $request);
        $user = $driver instanceof AppleProvider
            ? $driver->setNonce((string) $nonce)->user()
            : $driver->with([
                'code_verifier' => (string) $codeVerifier,
            ])->user();

        if (! $user instanceof SocialiteUser) {
            throw new InvalidIdentityException("The [{$provider}] provider answered with no OAuth 2 user.");
        }

        // 3. Map the stable subject per provider.
        $identity = match ($provider) {
            'github' => $this->github($user),
            'google' => $this->google($user),
            'microsoft' => $this->microsoft($driver, $user),
            'apple' => $this->appleIdentity($user),
            default => throw new InvalidIdentityException("No identity mapping for [{$provider}]."),
        };

        return [
            'identity' => $identity,
            'refresh_token' => $provider === 'apple' ? $this->optionalString($user->refreshToken) : null,
            'client_id' => $provider === 'apple' ? $this->appleClientId() : null,
        ];
    }

    /**
     * The absolute callback url for a provider, identical on both legs.
     */
    public function callbackUrl(string $provider): string
    {
        return url(str_replace('{provider}', $provider, self::CALLBACK_PATH));
    }

    /**
     * A fresh, stateless provider bound to this request and the fixed callback.
     */
    protected function driver(string $provider, Request $request): AbstractProvider
    {
        $driver = $provider === 'apple'
            ? $this->apple->make((string) $this->appleClientId(), $this->callbackUrl($provider))
            : $this->socialiteDriver($provider);

        return $driver->setRequest($request)->stateless()->redirectUrl($this->callbackUrl($provider));
    }

    /**
     * Resolve a Socialite driver that has not served another request.
     *
     * The manager caches the providers it builds, and a cached one keeps the
     * user it last resolved: in a long-lived worker (Octane, a queue, a test)
     * the second callback would be answered with the first callback's user.
     *
     * @throws RuntimeException When the driver is not an OAuth 2 provider.
     */
    protected function socialiteDriver(string $provider): AbstractProvider
    {
        if ($this->socialite instanceof SocialiteManager) {
            $this->socialite->forgetDrivers();
        }

        $driver = $this->socialite->driver($provider);

        if (! $driver instanceof AbstractProvider) {
            throw new RuntimeException("The Socialite driver for [{$provider}] is not an OAuth 2 provider.");
        }

        return $driver;
    }

    /**
     * Socialite reports only the primary address GitHub marked verified, else null.
     */
    protected function github(SocialiteUser $user): VerifiedIdentity
    {
        $email = $this->optionalString($user->getEmail());

        return new VerifiedIdentity(
            provider: 'github',
            providerUserId: $this->subject($user->getId()),
            email: $email,
            emailVerified: $email !== null,
            name: $this->optionalString($user->getName()) ?? $this->optionalString($user->getNickname()),
            avatar: $this->optionalString($user->getAvatar()),
        );
    }

    protected function google(SocialiteUser $user): VerifiedIdentity
    {
        return new VerifiedIdentity(
            provider: 'google',
            providerUserId: $this->subject($user->getId()),
            email: $this->optionalString($user->getEmail()),
            emailVerified: $this->isTrue($user->getRaw()['email_verified'] ?? null),
            name: $this->optionalString($user->getName()),
            avatar: $this->optionalString($user->getAvatar()),
        );
    }

    /**
     * `getClaims()` answers only after the provider verified the id_token's
     * signature against the tenant's keys, its issuer, audience and expiry.
     * Microsoft does not vouch for the address, so it is never treated as verified.
     *
     * @throws InvalidIdentityException When no verified id_token came back.
     */
    protected function microsoft(AbstractProvider $driver, SocialiteUser $user): VerifiedIdentity
    {
        $claims = $driver instanceof MicrosoftProvider ? $driver->getClaims() : null;

        if ($claims === null) {
            throw new InvalidIdentityException('Microsoft returned no verifiable id_token.');
        }

        return new VerifiedIdentity(
            provider: 'microsoft',
            providerUserId: $this->subject($claims->oid ?? null),
            email: $this->optionalString($user->getEmail()),
            emailVerified: false,
            name: $this->optionalString($user->getName()),
            tenantId: $this->subject($claims->tid ?? null),
        );
    }

    /**
     * The provider validated the id_token (signature, audience, nonce) before handing these claims over.
     */
    protected function appleIdentity(SocialiteUser $user): VerifiedIdentity
    {
        $email = $this->optionalString($user->getEmail());

        return new VerifiedIdentity(
            provider: 'apple',
            providerUserId: $this->subject($user->getId()),
            email: $email,
            emailVerified: $email !== null && $this->isTrue($user->getRaw()['email_verified'] ?? null),
            name: $this->optionalString($user->getName()),
        );
    }

    /**
     * Remove a posted `user` that is not a JSON object; the name it carries is optional.
     */
    protected function dropMalformedAppleUser(Request $request): void
    {
        $user = $request->input('user');

        if ($user === null || is_array($user) || (is_string($user) && is_array(json_decode($user, true)))) {
            return;
        }

        $request->request->remove('user');
        $request->query->remove('user');
    }

    protected function appleClientId(): ?string
    {
        return $this->optionalString(config('magic-starter.social.apple.services_id'));
    }

    /**
     * @throws InvalidIdentityException When the provider named no subject.
     */
    protected function subject(mixed $subject): string
    {
        if ((! is_string($subject) && ! is_int($subject)) || (string) $subject === '') {
            throw new InvalidIdentityException('The provider named no subject.');
        }

        return (string) $subject;
    }

    /**
     * Providers send `email_verified` as a boolean or as the string "true".
     */
    protected function isTrue(mixed $value): bool
    {
        return $value === true || $value === 'true';
    }

    protected function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
