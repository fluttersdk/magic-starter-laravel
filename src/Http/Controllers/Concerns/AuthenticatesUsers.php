<?php

namespace FlutterSdk\MagicStarter\Http\Controllers\Concerns;

use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Http\Resources\UserResource;
use FlutterSdk\MagicStarter\MagicStarter;
use FlutterSdk\MagicStarter\Social\StepUpConfirmations;
use FlutterSdk\MagicStarter\Social\VerifiedIdentity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Provides shared authentication helpers for controllers.
 *
 * Extracted from AuthController so that GuestAuthController
 * and any future auth controllers can reuse the same
 * token generation and response logic.
 *
 * Every path that issues a token (password login, the 2FA challenge, the
 * social exchange, guest and OTP sign-in, registration) goes through
 * {@see self::createAuthToken()} and then {@see self::authenticatedResponse()},
 * which is why a sign-in cancelling a scheduled account deletion lives on that
 * pair: the first clears the schedule, the second reports it.
 */
trait AuthenticatesUsers
{
    /**
     * The request attribute that carries "this sign-in cancelled a deletion"
     * from the token to the response.
     */
    private const DELETION_CANCELLED_ATTRIBUTE = 'magic-starter.deletion_cancelled';

    /**
     * Build an authenticated JSON response with user and token.
     *
     * Sets the user resolver on the request so that downstream resources
     * (e.g. TeamResource) can access the authenticated user via
     * `$request->user()` — even before Sanctum middleware runs.
     *
     * @param  mixed  $user  The authenticated user model.
     * @param  Request  $request  The current HTTP request.
     * @param  string  $token  The plain-text Sanctum token.
     * @param  string|null  $message  Response message, or null for the sign-in default.
     * @param  int  $status  HTTP status code.
     * @return JsonResponse The JSON response containing user data and token.
     */
    protected function authenticatedResponse(
        mixed $user,
        Request $request,
        string $token,
        ?string $message = null,
        int $status = 200,
    ): JsonResponse {
        // The default is resolved here rather than in the signature because a
        // parameter default must be a constant expression, and the sentence is
        // a translation line now. Null means "whatever a plain sign-in says",
        // which is what social login, the OTP verify and the 2FA challenge all
        // want; every other caller names its own.
        $message ??= (string) __('magic-starter::auth.login_successful');

        // Make $request->user() available for nested resources.
        // Resource serialization resolves request from the container,
        // which may differ from the controller-injected $request instance.
        $resolver = fn () => $user;
        $request->setUserResolver($resolver);
        app('request')->setUserResolver($resolver);

        $data = [
            'user' => new UserResource($user),
            'token' => $token,
        ];

        // The cancelled deletion outranks the caller's sentence: it is the one
        // thing this sign-in did that the user did not ask for.
        if ($request->attributes->get(self::DELETION_CANCELLED_ATTRIBUTE) === true) {
            $data['deletion_cancelled'] = true;
            $message = (string) __('magic-starter::social.deletion_cancelled');
        }

        return response()->json([
            'data' => $data,
            'message' => $message,
        ], $status);
    }

    /**
     * Finish a sign-in: a 2FA challenge when the user confirmed 2FA, else a token.
     *
     * The one decision every sign-in path shares (password login, the social
     * exchange), so a confirmed second factor cannot be skipped by choosing
     * another way in. The challenge token is encrypted and expires after
     * `magic-starter.two_factor.challenge_token_ttl` minutes; it is redeemed at
     * `POST auth/two-factor-challenge`.
     *
     * @param  Model  $user  The user who proved the first factor.
     * @param  string|null  $message  Response message, or null for the sign-in default.
     */
    protected function signInResponse(Model $user, Request $request, ?string $message = null): JsonResponse
    {
        if (
            Features::hasTwoFactorAuthenticationFeatures() &&
            method_exists($user, 'hasEnabledTwoFactorAuthentication') &&
            $user->hasEnabledTwoFactorAuthentication()
        ) {
            $challengeToken = encrypt(json_encode([
                'user_id' => $user->getKey(),
                'expires_at' => now()->addMinutes(
                    (int) config('magic-starter.two_factor.challenge_token_ttl', 5),
                )->timestamp,
            ]));

            return response()->json([
                'two_factor' => true,
                'two_factor_token' => $challengeToken,
            ]);
        }

        return $this->authenticatedResponse(
            $user,
            $request,
            $this->createAuthToken($user, $request, true),
            $message,
        );
    }

    /**
     * Refuse a social flow step with its translated sentence and stable code.
     *
     * @param  string  $code  a key of `lang/<locale>/social.php`, such as `flow_expired`
     */
    protected function socialRefusal(string $code, int $status): JsonResponse
    {
        return response()->json([
            'message' => __('magic-starter::social.' . $code),
            'code' => $code,
        ], $status);
    }

    /**
     * Mint a step-up confirmation when the identity is linked to the caller.
     *
     * Shared by the browser exchange and the native token endpoint, so both
     * ways of proving an identity hold the caller to the same link.
     */
    protected function confirmSocialIdentity(Authenticatable $bearer, VerifiedIdentity $identity): JsonResponse
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

    /**
     * Refuse a social step that needs the caller's bearer and has none, or another's.
     */
    protected function unauthenticated(): JsonResponse
    {
        return response()->json([
            'message' => __('Unauthenticated.'),
        ], 401);
    }

    /**
     * Create an authentication token for the given user.
     *
     * Signing in during the grace period of a deletion the user asked for
     * cancels it first; see {@see self::cancelScheduledDeletion()}.
     *
     * @param  mixed  $user  The user model instance.
     * @param  Request  $request  The current HTTP request (used for device info).
     * @param  bool  $storeDeviceInfo  Whether to persist ip/user_agent on the token.
     * @return string The plain-text token string.
     */
    protected function createAuthToken(mixed $user, Request $request, bool $storeDeviceInfo = true): string
    {
        $this->cancelScheduledDeletion($user, $request);

        if (! method_exists($user, 'createToken')) {
            return Str::random(80);
        }

        $tokenResult = $user->createToken('auth_token');
        $plainTextToken = $tokenResult->plainTextToken ?? Str::random(80);

        $accessToken = $tokenResult->accessToken ?? null;

        if ($storeDeviceInfo && $accessToken && method_exists($accessToken, 'forceFill')) {
            $accessToken->forceFill([
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            if (method_exists($accessToken, 'save')) {
                $accessToken->save();
            }
        }

        return (string) $plainTextToken;
    }

    /**
     * Clear a deletion the user scheduled, and mark the request so the response
     * says so.
     *
     * An orphan's schedule stays: the identity provider deleted the account, so
     * signing in by another method is not the user taking the request back.
     *
     * Guarded by attribute presence rather than a schema query, so an older
     * users table without the deletion columns reads null and pays nothing per
     * sign-in. The flag rides on the REQUEST rather than on the controller,
     * because a route caches its controller instance and a long-lived worker
     * would carry one sign-in's flag into the next.
     */
    protected function cancelScheduledDeletion(mixed $user, Request $request): void
    {
        if (! $user instanceof Model
            || $user->getAttribute('deletion_scheduled_at') === null
            || $user->getAttribute('orphaned_at') !== null
        ) {
            return;
        }

        $user->forceFill([
            'deletion_scheduled_at' => null,
        ])->save();

        $request->attributes->set(self::DELETION_CANCELLED_ATTRIBUTE, true);
    }
}
