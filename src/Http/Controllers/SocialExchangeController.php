<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\ConnectsSocialAccounts;
use FlutterSdk\MagicStarter\Http\Controllers\Concerns\AuthenticatesUsers;
use FlutterSdk\MagicStarter\Http\Requests\SocialExchangeRequest;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Social\SocialFlowStore;
use FlutterSdk\MagicStarter\Social\SocialSignInRefused;
use FlutterSdk\MagicStarter\Social\StepUpConfirmations;
use FlutterSdk\MagicStarter\Social\VerifiedIdentity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Redeems a social flow's one-time code for what the flow concluded.
 *
 * The code is spent first and answers only to the verifier behind the
 * challenge the flow started with. A sign-in then gets a token, or a 2FA
 * challenge when the account confirmed one. A connect and a confirm need the
 * caller's bearer, read here rather than through middleware because a sign-in
 * on the same route has none: a connect links the staged identity to the
 * ticket's user only for that user's bearer, and a confirm proves the bearer
 * controls an identity already linked to them.
 */
class SocialExchangeController
{
    use AuthenticatesUsers;

    /**
     * Exchange the code for a token, a link, or a step-up confirmation.
     */
    public function __invoke(SocialExchangeRequest $request): JsonResponse
    {
        $outcome = app(SocialFlowStore::class)->pullCode(
            (string) $request->validated('code'),
            (string) $request->validated('code_verifier'),
        );

        if ($outcome === null) {
            return $this->socialRefusal('flow_expired', 422);
        }

        if ($outcome['intent'] === SocialFlowStore::INTENT_SIGNIN) {
            $user = MagicStarter::userModel()::query()->find($outcome['user_id']);

            return $user === null
                ? $this->socialRefusal('flow_expired', 422)
                : $this->signInResponse($user, $request);
        }

        $bearer = Auth::guard('sanctum')->user();
        $identity = $outcome['identity'];

        if ($bearer === null || $identity === null) {
            return $this->unauthenticated();
        }

        return $outcome['intent'] === SocialFlowStore::INTENT_CONNECT
            ? $this->connect($bearer, $identity, $outcome)
            : $this->confirm($bearer, $identity);
    }

    /**
     * Link the staged identity to the ticket's user, when that user is the caller.
     *
     * @param  array{intent: string, user_id: string|null, identity: VerifiedIdentity|null, refresh_token: string|null, client_id: string|null}  $outcome
     */
    private function connect(Authenticatable $bearer, VerifiedIdentity $identity, array $outcome): JsonResponse
    {
        if ((string) $bearer->getAuthIdentifier() !== $outcome['user_id']) {
            return $this->unauthenticated();
        }

        try {
            $account = app(ConnectsSocialAccounts::class)->connect(
                $bearer,
                $identity,
                $outcome['refresh_token'],
                $outcome['client_id'],
            );
        } catch (SocialSignInRefused $refusal) {
            return $this->socialRefusal($refusal->code(), 409);
        }

        return response()->json([
            'data' => [
                'provider' => $account->getAttribute('provider'),
                'email' => $account->getAttribute('email_at_link'),
            ],
        ]);
    }

    /**
     * Mint a step-up confirmation when the identity is linked to the caller.
     */
    private function confirm(Authenticatable $bearer, VerifiedIdentity $identity): JsonResponse
    {
        $linked = MagicStarter::socialAccountModel()::query()
            ->where('provider', $identity->provider)
            ->where('provider_user_id', $identity->providerUserId)
            ->where('user_id', $bearer->getAuthIdentifier())
            // A link the provider revoked no longer proves the caller is its owner.
            ->whereNull('revoked_at')
            ->exists();

        if (! $linked) {
            return $this->socialRefusal('invalid_identity', 403);
        }

        return response()->json([
            'data' => [
                'confirmation_token' => app(StepUpConfirmations::class)->mint($bearer),
            ],
        ]);
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json([
            'message' => __('Unauthenticated.'),
        ], 401);
    }
}
