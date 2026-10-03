<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Http\Controllers\Concerns\AuthenticatesUsers;
use FlutterSdk\MagicStarter\Http\Requests\SocialRedirectRequest;
use FlutterSdk\MagicStarter\Social\ProviderIdentity;
use FlutterSdk\MagicStarter\Social\SocialFlowStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Starts a backend-hosted social flow: records it and sends the browser to the provider.
 *
 * The intent is fixed here and nowhere else: a link ticket makes it a connect
 * for the ticket's user, `intent=confirm` a step-up re-authentication, and
 * anything else a sign-in. Refusals answer JSON, since nothing is recorded yet
 * that could send the browser back to the app.
 */
class SocialRedirectController
{
    use AuthenticatesUsers;

    /**
     * Redirect to the provider's authorize endpoint for a new flow.
     */
    public function __invoke(SocialRedirectRequest $request, string $provider): JsonResponse|RedirectResponse
    {
        $identities = app(ProviderIdentity::class);
        $store = app(SocialFlowStore::class);
        $platform = (string) $request->validated('platform');
        $challenge = (string) $request->validated('challenge');

        // 1. Only an allowed provider, configured for this deployment, with a
        //    platform target to come back to, may start a flow.
        if (! $identities->supports($provider)) {
            return $this->socialRefusal('provider_not_supported', 404);
        }

        if ($store->target($platform) === null || ! $identities->isConfigured($provider)) {
            return $this->socialRefusal('platform_not_configured', 422);
        }

        // 2. A ticket must have been taken for this provider and this challenge.
        $intent = SocialFlowStore::INTENT_SIGNIN;
        $userId = null;

        if ($request->filled('ticket')) {
            $intent = SocialFlowStore::INTENT_CONNECT;
            $userId = $store->redeemTicket((string) $request->validated('ticket'), $provider, $challenge, $intent);

            if ($userId === null) {
                return $this->socialRefusal('flow_expired', 422);
            }
        } elseif ($request->validated('intent') === SocialFlowStore::INTENT_CONFIRM) {
            $intent = SocialFlowStore::INTENT_CONFIRM;
        }

        // 3. Record the flow, then hand the browser to the provider.
        $secrets = $identities->secrets($provider);
        $state = $store->putState([
            'provider' => $provider,
            'platform' => $platform,
            'challenge' => $challenge,
            'intent' => $intent,
            'user_id' => $userId,
            'code_verifier' => $secrets['code_verifier'],
            'nonce' => $secrets['nonce'],
        ]);

        return $identities->redirect($provider, $request, $state, $secrets['code_verifier'], $secrets['nonce']);
    }
}
