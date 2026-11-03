<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\ExchangesHandoffCodes;
use FlutterSdk\MagicStarter\Contracts\IssuesHandoffCodes;
use FlutterSdk\MagicStarter\Http\Controllers\Concerns\AuthenticatesUsers;
use FlutterSdk\MagicStarter\Http\Requests\ExchangeHandoffRequest;
use FlutterSdk\MagicStarter\Http\Requests\IssueHandoffRequest;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Carries a sign-in from one origin to another without a bearer in a url.
 *
 * A signed-in session mints a single-use code; the browser carries it to the
 * app, which trades it, unauthenticated, for the answer a login gives. The
 * exchange goes through {@see AuthenticatesUsers::signInResponse()}, so a
 * confirmed second factor is challenged exactly as on a password login.
 */
class HandoffController
{
    use AuthenticatesUsers;

    /**
     * Mint a code for the signed-in caller.
     *
     * The exchange issues a full-ability token, so only a caller who already
     * holds one may mint: a guest, or a token restricted to some abilities,
     * answers 403 `handoff_forbidden` rather than minting its way up.
     */
    public function issue(IssueHandoffRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->mayHandOff($user)) {
            return response()->json([
                'message' => __('magic-starter::auth.handoff.forbidden'),
                'code' => 'handoff_forbidden',
            ], 403);
        }

        $handoff = app(IssuesHandoffCodes::class)->issue($user, $request->next());

        return response()->json([
            'data' => $handoff,
        ], 201);
    }

    /**
     * Exchange a code for a token, or the 2FA challenge, plus the path to open.
     *
     * Every refusal is one 422 `handoff_expired`, so the answer never tells an
     * unknown code from a spent, an expired or an orphaned one.
     */
    public function exchange(ExchangeHandoffRequest $request): JsonResponse
    {
        $code = $request->code();

        $handoff = $code === null ? null : app(ExchangesHandoffCodes::class)->exchange($code);

        if ($handoff === null) {
            return response()->json([
                'message' => __('magic-starter::auth.handoff.expired'),
                'code' => 'handoff_expired',
            ], 422);
        }

        return $this->withNext($this->signInResponse($handoff['user'], $request), $handoff['next']);
    }

    /**
     * Determine whether the caller is a full account on a token that can do everything.
     *
     * `method_exists` rather than plain calls, as elsewhere: HasGuestSupport and
     * HasApiTokens are traits the consumer applies to its own user model.
     */
    private function mayHandOff(?Authenticatable $user): bool
    {
        if ($user === null || ! method_exists($user, 'tokenCan') || ! $user->tokenCan('*')) {
            return false;
        }

        $isGuest = method_exists($user, 'isGuest')
            ? $user->isGuest()
            : (bool) ($user->is_guest ?? false);

        return ! $isGuest && $this->tokenIsYoungEnough($user);
    }

    /**
     * Determine whether the minting token is within `handoff.max_token_age`.
     *
     * A token whose age cannot be read (a transient one) fails when a limit is set, since freshness is
     * then unproven.
     */
    private function tokenIsYoungEnough(Authenticatable $user): bool
    {
        $maxAge = config('magic-starter.handoff.max_token_age');

        if ($maxAge === null) {
            return true;
        }

        $createdAt = method_exists($user, 'currentAccessToken')
            ? data_get($user->currentAccessToken(), 'created_at')
            : null;

        return $createdAt !== null && Carbon::parse($createdAt)->addSeconds((int) $maxAge)->isFuture();
    }

    /**
     * Add `data.next` to a sign-in answer, the challenge included, so the app
     * can open the path once the sign-in, or the 2FA challenge after it, is done.
     */
    private function withNext(JsonResponse $response, ?string $next): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $response->getData(true);

        /** @var array<string, mixed> $data */
        $data = $payload['data'] ?? [];
        $payload['data'] = [
            ...$data,
            'next' => $next,
        ];

        return $response->setData($payload);
    }
}
