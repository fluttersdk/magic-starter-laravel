<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\ConnectsSocialAccounts;
use FlutterSdk\MagicStarter\Contracts\ResolvesSocialUsers;
use FlutterSdk\MagicStarter\Http\Controllers\Concerns\AuthenticatesUsers;
use FlutterSdk\MagicStarter\Http\Requests\SocialTokenRequest;
use FlutterSdk\MagicStarter\Social\AppleProviderFactory;
use FlutterSdk\MagicStarter\Social\IdTokenVerifier;
use FlutterSdk\MagicStarter\Social\InvalidIdentityException;
use FlutterSdk\MagicStarter\Social\KeySetUnavailableException;
use FlutterSdk\MagicStarter\Social\ProviderIdentity;
use FlutterSdk\MagicStarter\Social\SocialFlowStore;
use FlutterSdk\MagicStarter\Social\SocialSignInRefused;
use FlutterSdk\MagicStarter\Social\VerifiedIdentity;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Throwable;

/**
 * Signs a native client in with a Google or Apple ID token, or links or
 * confirms the identity it carries for the caller's bearer.
 *
 * The token is the whole credential, so it is accepted only once
 * {@see IdTokenVerifier} has checked its signature, issuer, audience, expiry,
 * Apple's nonce and that it was never presented before. Apple may add its
 * one-time authorization code: redeemed for the refresh token that account
 * deletion revokes, and best effort, because a sign-in must not depend on it.
 * It is redeemed only once the sign-in or connect has been accepted, so a
 * refused one never mints a refresh token nobody stores.
 *
 * A sign-in ends like every other sign-in, in a 2FA challenge when the account
 * confirmed one. A connect and a confirm need the caller's bearer, read here
 * rather than through middleware because a sign-in on the same route has none.
 */
class SocialTokenController
{
    use AuthenticatesUsers;

    /**
     * The providers whose SDKs hand a native client an ID token.
     */
    public const PROVIDERS = [
        'google',
        'apple',
    ];

    /**
     * Verify the ID token and finish the flow the caller asked for.
     */
    public function __invoke(SocialTokenRequest $request, string $provider): JsonResponse
    {
        // 1. The deployment's allowlist narrows the providers the route admits.
        if (! app(ProviderIdentity::class)->supports($provider)) {
            return $this->socialRefusal('provider_not_supported', 404);
        }

        // 2. A connect or a confirm without a bearer is refused before the token is spent.
        $intent = $request->intent();
        $bearer = $intent === SocialFlowStore::INTENT_SIGNIN ? null : Auth::guard('sanctum')->user();

        if ($intent !== SocialFlowStore::INTENT_SIGNIN && $bearer === null) {
            return $this->unauthenticated();
        }

        // 3. Verify. A provider that cannot be reached is an outage, not a verdict on the
        //    token; a configuration or programming error is neither, and propagates.
        try {
            $identity = $this->verify($request, $provider);
        } catch (InvalidIdentityException $exception) {
            return $this->invalidIdentity($exception);
        } catch (ConnectionException|RequestException|GuzzleException|KeySetUnavailableException $exception) {
            report($exception);

            return $this->providerUnavailable($exception);
        }

        // 4. Finish the flow; only a sign-in has no bearer by now.
        try {
            if ($bearer === null) {
                return $this->signIn($request, $identity);
            }

            return $intent === SocialFlowStore::INTENT_CONNECT
                ? $this->connect($request, $bearer, $identity)
                : $this->confirmSocialIdentity($bearer, $identity);
        } catch (SocialSignInRefused $refusal) {
            return $this->socialRefusal($refusal->code(), 409);
        }
    }

    /**
     * Verify the token against the provider's published keys.
     *
     * @throws InvalidIdentityException When the token fails any check or was already used.
     * @throws ConnectionException When Google's key set cannot be reached.
     * @throws RequestException When Google answers the key set request with an error.
     * @throws GuzzleException When Apple's key set cannot be fetched.
     * @throws KeySetUnavailableException When a provider answers without a key set.
     */
    private function verify(SocialTokenRequest $request, string $provider): VerifiedIdentity
    {
        $verifier = app(IdTokenVerifier::class);
        $idToken = (string) $request->validated('id_token');

        if ($provider === 'google') {
            return $verifier->google($idToken);
        }

        // Apple's provider maps a posted `user` from the container's request and
        // TypeErrors on anything that is not a JSON object; nothing here reads it.
        $this->withoutAppleUser(request());

        return $verifier->apple($idToken, (string) $request->validated('nonce'));
    }

    /**
     * Resolve the identity to its user and issue a token, or a 2FA challenge.
     *
     * @throws SocialSignInRefused When the identity may not sign in.
     * @throws LogicException When a rebound resolver answers with a user that is not an Eloquent model.
     */
    private function signIn(SocialTokenRequest $request, VerifiedIdentity $identity): JsonResponse
    {
        $user = app(ResolvesSocialUsers::class)->resolve($identity, $request);

        // Apple's refresh token is what account deletion revokes, so a sign-in
        // keeps it on the link the resolver just accepted.
        $this->keepAppleGrant($request, $user, $identity);

        // The token and 2FA response read Eloquent state the Authenticatable contract does not promise.
        if (! $user instanceof Model) {
            throw new LogicException('The social user resolver must return an Eloquent model.');
        }

        return $this->signInResponse($user, $request);
    }

    /**
     * Link the identity to the caller.
     *
     * @throws SocialSignInRefused When another user owns the identity or the caller holds another of that provider.
     */
    private function connect(
        SocialTokenRequest $request,
        Authenticatable $bearer,
        VerifiedIdentity $identity,
    ): JsonResponse {
        $account = app(ConnectsSocialAccounts::class)->connect($bearer, $identity);
        $account = $this->keepAppleGrant($request, $bearer, $identity) ?? $account;

        return response()->json([
            'data' => [
                'provider' => $account->getAttribute('provider'),
                'email' => $account->getAttribute('email_at_link'),
            ],
        ]);
    }

    /**
     * Redeem Apple's code and keep its refresh token on the user's accepted link.
     *
     * Called only after the link was accepted, so the code is never spent on a
     * refused flow; re-connecting the same identity refreshes that link.
     *
     * @return Model|null the refreshed link, or null when nothing was kept
     *
     * @throws SocialSignInRefused When the link was taken between the accept and the refresh.
     */
    private function keepAppleGrant(
        SocialTokenRequest $request,
        Authenticatable $user,
        VerifiedIdentity $identity,
    ): ?Model {
        if ($identity->provider !== 'apple') {
            return null;
        }

        $refreshToken = $this->appleRefreshToken($request->validated('authorization_code'));

        if ($refreshToken === null) {
            return null;
        }

        return app(ConnectsSocialAccounts::class)->connect($user, $identity, $refreshToken, $this->appleBundleId());
    }

    /**
     * Redeem Apple's one-time code for the grant's refresh token.
     *
     * A failed exchange is reported and answered with null: the ID token has
     * already proved the identity, and the refresh token only serves a later
     * revocation.
     */
    private function appleRefreshToken(mixed $code): ?string
    {
        if (! is_string($code) || $code === '') {
            return null;
        }

        try {
            $response = app(AppleProviderFactory::class)
                ->make($this->appleBundleId())
                ->getAccessTokenResponse($code);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $refreshToken = is_array($response) ? ($response['refresh_token'] ?? null) : null;

        return is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null;
    }

    /**
     * The native client id; the verifier has already refused a token when it is not configured.
     */
    private function appleBundleId(): string
    {
        return (string) config('magic-starter.social.apple.bundle_id');
    }

    /**
     * Remove a posted `user` from every input source the Apple provider reads.
     */
    private function withoutAppleUser(Request $request): void
    {
        $request->query->remove('user');
        $request->request->remove('user');
        $request->json()->remove('user');
    }

    private function invalidIdentity(InvalidIdentityException $exception): JsonResponse
    {
        $payload = [
            'message' => $exception->getMessage(),
            'code' => InvalidIdentityException::ERROR_CODE,
        ];

        if (config('app.debug')) {
            $payload['error'] = $exception->reason;
        }

        return response()->json($payload, 401);
    }

    /**
     * The provider could not be asked; the client may retry later.
     */
    private function providerUnavailable(Throwable $exception): JsonResponse
    {
        $payload = [
            'message' => __('magic-starter::social.provider_unavailable'),
            'code' => 'provider_unavailable',
        ];

        if (config('app.debug')) {
            $payload['error'] = $exception->getMessage();
        }

        return response()->json($payload, 503);
    }
}
