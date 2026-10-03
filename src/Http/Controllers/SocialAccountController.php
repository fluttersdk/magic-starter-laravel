<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\DisconnectsSocialAccounts;
use FlutterSdk\MagicStarter\Contracts\SetsUserPasswords;
use FlutterSdk\MagicStarter\Http\Controllers\Concerns\AuthenticatesUsers;
use FlutterSdk\MagicStarter\Http\Requests\LinkTicketRequest;
use FlutterSdk\MagicStarter\Http\Requests\SetPasswordRequest;
use FlutterSdk\MagicStarter\Social\ProviderIdentity;
use FlutterSdk\MagicStarter\Social\SocialFlowStore;
use FlutterSdk\MagicStarter\Social\SocialSignInRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The authenticated profile's sign-in methods: connect a provider, disconnect
 * one, and set the first password of a social-only account.
 *
 * A connect starts here and finishes in the browser flow: the ticket names the
 * caller to {@see SocialRedirectController} without the app's bearer token
 * ever reaching a browser.
 */
class SocialAccountController
{
    use AuthenticatesUsers;

    /**
     * Mint the single-use ticket that turns the next redirect into a connect for the caller.
     *
     * The ticket travels in the response body only: in a url it would sit in
     * browser history and access logs for as long as it can be redeemed.
     */
    public function linkTicket(LinkTicketRequest $request): JsonResponse
    {
        $provider = (string) $request->validated('provider');

        if (! app(ProviderIdentity::class)->supports($provider)) {
            return $this->socialRefusal('provider_not_supported', 404);
        }

        $ticket = app(SocialFlowStore::class)->mintTicket(
            $request->user(),
            $provider,
            (string) $request->validated('challenge'),
            SocialFlowStore::INTENT_CONNECT,
        );

        return response()->json([
            'data' => [
                'ticket' => $ticket,
            ],
        ]);
    }

    /**
     * Unlink the caller's identity at the provider.
     */
    public function destroy(Request $request, string $provider): JsonResponse|Response
    {
        try {
            app(DisconnectsSocialAccounts::class)->disconnect($request->user(), $provider);
        } catch (SocialSignInRefused $refusal) {
            return $this->socialRefusal($refusal->code(), 422);
        }

        return response()->noContent();
    }

    /**
     * Set the caller's first password; no current password exists to confirm.
     */
    public function setPassword(SetPasswordRequest $request): JsonResponse
    {
        try {
            app(SetsUserPasswords::class)->set($request->user(), $request->validated());
        } catch (SocialSignInRefused $refusal) {
            return $this->socialRefusal($refusal->code(), 422);
        }

        return response()->json([
            'data' => null,
            'message' => __('magic-starter::profile.password_updated'),
        ]);
    }
}
