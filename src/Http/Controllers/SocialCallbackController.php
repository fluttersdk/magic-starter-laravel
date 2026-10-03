<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\ConnectsSocialAccounts;
use FlutterSdk\MagicStarter\Contracts\ResolvesSocialUsers;
use FlutterSdk\MagicStarter\Http\Controllers\Concerns\AuthenticatesUsers;
use FlutterSdk\MagicStarter\Social\ProviderIdentity;
use FlutterSdk\MagicStarter\Social\SocialFlowStore;
use FlutterSdk\MagicStarter\Social\SocialSignInRefused;
use FlutterSdk\MagicStarter\Social\VerifiedIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Where the provider sends the browser back: GET for most, Apple's form_post for Apple.
 *
 * It ends every flow it can identify in a 302 to the platform's configured
 * target carrying either a one-time `code` or an `error=<code>`; no token is
 * ever placed in a url. The code is bound to the challenge the app started
 * with, so only the app holding the verifier can redeem it.
 *
 * A sign-in resolves its account here. A connect or a confirm only stages the
 * verified identity: whether it links, and to whom, is decided at the exchange
 * by the bearer presented there.
 */
class SocialCallbackController
{
    use AuthenticatesUsers;

    /**
     * Finish the provider leg and return to the app.
     */
    public function __invoke(Request $request, string $provider): JsonResponse|RedirectResponse
    {
        $store = app(SocialFlowStore::class);
        $state = $request->input('state');
        $state = is_string($state) ? $state : '';

        // 1. Spend the state before anything else, so no callback can be replayed.
        $flow = $state === '' ? null : $store->pullState($state);

        if ($flow === null || $flow['provider'] !== $provider) {
            return $this->returnToApp($flow['platform'] ?? $store->platformOf($state), [
                'error' => 'flow_expired',
            ]);
        }

        // 2. A provider `error` (the user declined) arrives without a code and vouches for nobody.
        $code = $request->input('code');

        if (! is_string($code) || $code === '') {
            return $this->returnToApp($flow['platform'], [
                'error' => 'invalid_identity',
            ]);
        }

        // 3. Redeem the provider's code with this flow's own secret and map the identity.
        try {
            $verified = app(ProviderIdentity::class)->identity(
                $provider,
                $request,
                $flow['code_verifier'],
                $flow['nonce'],
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->returnToApp($flow['platform'], [
                'error' => 'invalid_identity',
            ]);
        }

        // 4. Resolve a sign-in now; stage a connect or a confirm for the exchange.
        try {
            $oneTimeCode = $this->mintOutcome($request, $store, $flow, $verified);
        } catch (SocialSignInRefused $refusal) {
            return $this->returnToApp($flow['platform'], [
                'error' => $refusal->code(),
            ]);
        }

        return $this->returnToApp($flow['platform'], [
            'code' => $oneTimeCode,
        ]);
    }

    /**
     * Mint the one-time code for what this flow concluded.
     *
     * @param  array{provider: string, platform: string, challenge: string, intent: string, user_id: string|null, code_verifier: string|null, nonce: string|null}  $flow
     * @param  array{identity: VerifiedIdentity, refresh_token: string|null, client_id: string|null}  $verified
     *
     * @throws SocialSignInRefused When the identity may not sign in.
     */
    private function mintOutcome(Request $request, SocialFlowStore $store, array $flow, array $verified): string
    {
        if ($flow['intent'] === SocialFlowStore::INTENT_SIGNIN) {
            $user = app(ResolvesSocialUsers::class)->resolve($verified['identity'], $request);

            // Apple's refresh token is what account deletion revokes, so a sign-in
            // keeps it on the link; connect() refreshes the row it just resolved.
            if ($verified['refresh_token'] !== null) {
                app(ConnectsSocialAccounts::class)->connect(
                    $user,
                    $verified['identity'],
                    $verified['refresh_token'],
                    $verified['client_id'],
                );
            }

            return $store->mintCode(
                challenge: $flow['challenge'],
                intent: $flow['intent'],
                userId: (string) $user->getAuthIdentifier(),
            );
        }

        $connecting = $flow['intent'] === SocialFlowStore::INTENT_CONNECT;

        return $store->mintCode(
            challenge: $flow['challenge'],
            intent: $flow['intent'],
            userId: $flow['user_id'],
            identity: $verified['identity'],
            refreshToken: $connecting ? $verified['refresh_token'] : null,
            clientId: $connecting ? $verified['client_id'] : null,
        );
    }

    /**
     * Send the browser to the platform's configured target with the outcome.
     *
     * Without a target (a state that names no platform, or a target removed
     * mid-flow) there is nowhere to send it, and the refusal is answered as JSON.
     *
     * @param  array<string, string>  $outcome  `code` or `error`
     */
    private function returnToApp(?string $platform, array $outcome): JsonResponse|RedirectResponse
    {
        $target = $platform === null ? null : app(SocialFlowStore::class)->target($platform, $outcome);

        if ($target === null) {
            return $this->socialRefusal($outcome['error'] ?? 'platform_not_configured', 422);
        }

        // The code rides in the url, so it must not leak in a Referer or survive in a cache.
        return new RedirectResponse($target, 302, [
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
        ]);
    }
}
